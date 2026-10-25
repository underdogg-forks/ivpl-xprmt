<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;

/**
 * GHSA-qw48-r3gw-gc45: Mailer allows arbitrary recipients and sender spoofing.
 *
 * MailerRecipientBindingTest only re-reads the client row from the database, which cannot change whatever
 * the controller does. This test sends the invoice through a real SMTP conversation with a local recording
 * server (tests/Support/smtp_recording_server.php) and asserts on the envelope that actually went out.
 */
#[Group('security')]
final class MailerRecipientCaptureTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;

    /** @var resource|null */
    private $server;

    private string $logFile = '';

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_addresses_the_mail_to_the_invoice_client_and_ignores_a_posted_recipient(): void
    {
        /* Arrange */
        $envelope = $this->sendInvoiceWith(['to_email' => 'attacker@evil.test']);

        /* Assert */
        self::assertContains('client@legit.test', $envelope['to'], 'The mail must reach the invoice client.');
        self::assertNotContains('attacker@evil.test', $envelope['to'], 'A POSTed to_email must never become a recipient.');
    }

    #[Test]
    public function it_sends_from_the_configured_organisation_address_and_ignores_a_posted_sender(): void
    {
        /* Arrange */
        $envelope = $this->sendInvoiceWith(['from_email' => 'ceo@spoofed.test']);

        /* Assert */
        self::assertContains('billing@company.test', $envelope['from'], 'The sender must be the configured address.');
        self::assertNotContains('ceo@spoofed.test', $envelope['from'], 'A POSTed from_email must never become the sender.');
    }

    /**
     * @param array<string, string> $override
     *
     * @return array{to: list<string>, from: list<string>}
     */
    private function sendInvoiceWith(array $override): array
    {
        $this->logFile = sys_get_temp_dir() . '/smtp-envelope-' . bin2hex(random_bytes(6)) . '.log';
        $pipes         = [];
        $this->server  = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/smtp_recording_server.php', $this->logFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($this->server, 'The recording SMTP server must start.');
        $banner = (string) fgets($pipes[1]);
        self::assertMatchesRegularExpression('/^PORT:\d+/', $banner, 'The recording SMTP server must report its port.');
        $port = (int) substr(trim($banner), 5);

        foreach ([
            'email_send_method'    => 'smtp',
            'smtp_server_address'  => '127.0.0.1',
            'smtp_port'            => (string) $port,
            'smtp_security'        => '',
            'smtp_authentication'  => '0',
            'smtp_mail_from'       => 'billing@company.test',
            'email_pdf_attachment' => '0',
        ] as $key => $value) {
            $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => $key, 'setting_value' => '']);
            $this->databaseUpdate('ip_settings', ['setting_value' => $value], ['setting_key' => $key]);
        }

        $clientId = $this->databaseInsert('ip_clients', [
            'client_date_created'  => date('Y-m-d H:i:s'),
            'client_date_modified' => date('Y-m-d H:i:s'),
            'client_name'          => 'Legit Client',
            'client_email'         => 'client@legit.test',
        ]);
        $invoiceId = $this->seedInvoice($clientId);
        $this->actingAsAdmin();

        $this->postWithValidCsrfToken("/mailer/send_invoice/{$invoiceId}", array_merge([
            'to_email'     => 'client@legit.test',
            'from_email'   => 'billing@company.test',
            'from_name'    => 'Billing',
            'cc'           => '',
            'bcc'          => '',
            'subject'      => 'Invoice',
            'body'         => 'Please pay.',
            'pdf_template' => '',
        ], $override));

        $to   = [];
        $from = [];
        foreach (is_file($this->logFile) ? (file($this->logFile, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            [$kind, $address] = explode(' ', $line, 2) + [1 => ''];
            if ($kind === 'TO') {
                $to[] = $address;
            } elseif ($kind === 'FROM') {
                $from[] = $address;
            }
        }

        self::assertNotEmpty($to, 'The controller must have sent a mail through the recording SMTP server.');

        return ['to' => $to, 'from' => $from];
    }
}
