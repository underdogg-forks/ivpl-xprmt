<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;
use Tests\Concerns\RecordsSmtpEnvelope;
use Tests\Integration\Support\HttpResponse;

/**
 * GHSA-qw48-r3gw-gc45 (CC/BCC part): once To and From are bound to the client and the configured sender,
 * the CC and BCC fields were still free text, so an administrator could copy a branded invoice or quote
 * to any outside address. CC and BCC addresses must now belong to the organisation's own domain: the
 * mailer_internal_domain setting, else the domain of smtp_mail_from, else the sender's own domain.
 *
 * Every test sends through a recording SMTP server and asserts on the envelope that went out.
 */
#[Group('security')]
final class MailerCcBccAuthorizationTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;
    use RecordsSmtpEnvelope;

    protected function tearDown(): void
    {
        $this->stopSmtpRecorder();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function documentKinds(): array
    {
        return ['invoice' => ['invoice'], 'quote' => ['quote']];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function semicolonAndInjectionInputs(): array
    {
        return [
            'semicolon list'        => ['ok@company.test;evil@evil.test'],
            'semicolon then comma'  => ['evil@evil.test;ok@company.test, ok2@company.test'],
            'crlf header injection' => ["ok@company.test\r\nBcc: evil@evil.test"],
            'display name form'     => ['Boss <evil@evil.test>'],
            'trailing comma'        => ['ok@company.test,'],
        ];
    }

    #[Test]
    #[DataProvider('documentKinds')]
    public function it_refuses_the_send_when_a_cc_address_is_outside_the_organisation_domain(string $kind): void
    {
        /* Act */
        [$response, $envelope] = $this->send($kind, ['cc' => 'someone@evil.test']);

        /* Assert: nothing is sent, not even to the client, and the user is told why */
        self::assertSame([], $envelope['to'], 'No mail may leave when a CC address is outside the domain.');
        self::assertTrue($response->isRedirect());
        self::assertNotEmpty($response->session()['alert_error'] ?? null, 'The refusal must be shown with a flash type the layout renders.');
    }

    #[Test]
    #[DataProvider('documentKinds')]
    public function it_refuses_the_send_when_a_bcc_address_is_outside_the_organisation_domain(string $kind): void
    {
        /* Act */
        [, $envelope] = $this->send($kind, ['bcc' => 'someone@evil.test']);

        /* Assert */
        self::assertSame([], $envelope['to'], 'No mail may leave when a BCC address is outside the domain.');
    }

    #[Test]
    public function it_refuses_the_whole_send_when_only_one_address_in_a_list_is_outside_the_domain(): void
    {
        /* Act */
        [, $envelope] = $this->send('invoice', ['cc' => 'ok@company.test, evil@evil.test']);

        /* Assert */
        self::assertSame([], $envelope['to']);
    }

    #[Test]
    #[DataProvider('semicolonAndInjectionInputs')]
    public function it_refuses_separators_and_header_injection_that_smuggle_an_outside_address(string $cc): void
    {
        /* Act */
        [, $envelope] = $this->send('invoice', ['cc' => $cc]);

        /* Assert */
        self::assertNotContains('evil@evil.test', $envelope['to'], 'An outside address must not reach the envelope however it is written.');
    }

    #[Test]
    #[DataProvider('documentKinds')]
    public function it_delivers_to_the_client_and_to_internal_cc_and_bcc_addresses(string $kind): void
    {
        /* Act: mixed case, spaces around the comma */
        [, $envelope] = $this->send($kind, ['cc' => 'Accounts@COMPANY.test , boss@company.test', 'bcc' => 'archive@company.test']);

        /* Assert */
        foreach (['client@legit.test', 'accounts@company.test', 'boss@company.test', 'archive@company.test'] as $expected) {
            self::assertContains($expected, array_map('strtolower', $envelope['to']), "{$expected} must receive the mail.");
        }
    }

    #[Test]
    public function it_prefers_the_mailer_internal_domain_setting_over_the_sender_domain(): void
    {
        /* Act */
        [, $envelope] = $this->send('invoice', ['cc' => 'partner@partner.test'], ['mailer_internal_domain' => 'partner.test']);

        /* Assert */
        self::assertContains('partner@partner.test', $envelope['to']);
    }

    #[Test]
    public function it_still_refuses_outside_addresses_when_no_sender_domain_is_configured(): void
    {
        /* Act: no mailer_internal_domain and no smtp_mail_from; the sender falls back to the document owner's address */
        [, $envelope] = $this->send('invoice', ['cc' => 'someone@evil.test'], ['smtp_mail_from' => '']);

        /* Assert: an unconfigured domain must not switch the check off */
        self::assertSame([], $envelope['to']);
    }

    #[Test]
    public function it_sends_with_no_cc_or_bcc_at_all(): void
    {
        /* Act */
        [, $envelope] = $this->send('invoice', []);

        /* Assert */
        self::assertContains('client@legit.test', $envelope['to']);
    }

    /**
     * @param array<string, string> $override
     * @param array<string, string> $settings
     *
     * @return array{0: HttpResponse, 1: array{to: list<string>, from: list<string>}}
     */
    private function send(string $kind, array $override, array $settings = []): array
    {
        $this->startSmtpRecorder($settings);

        $clientId = $this->databaseInsert('ip_clients', [
            'client_date_created'  => date('Y-m-d H:i:s'),
            'client_date_modified' => date('Y-m-d H:i:s'),
            'client_name'          => 'Legit Client',
            'client_email'         => 'client@legit.test',
        ]);
        $documentId = $kind === 'quote' ? $this->seedQuote($clientId) : $this->seedInvoice($clientId);
        $this->actingAsAdmin();

        $response = $this->postWithValidCsrfToken("/mailer/send_{$kind}/{$documentId}", array_merge([
            'to_email'     => 'client@legit.test',
            'from_email'   => 'billing@company.test',
            'from_name'    => 'Billing',
            'cc'           => '',
            'bcc'          => '',
            'subject'      => 'Mail',
            'body'         => 'Please pay.',
            'pdf_template' => '',
        ], $override));

        return [$response, $this->recordedEnvelope()];
    }

    private function seedQuote(int $clientId): int
    {
        return $this->databaseInsert('ip_quotes', [
            'user_id'             => 1,
            'client_id'           => $clientId,
            'invoice_id'          => 0,
            'invoice_group_id'    => 1,
            'quote_status_id'     => 1,
            'quote_date_created'  => date('Y-m-d'),
            'quote_date_modified' => date('Y-m-d H:i:s'),
            'quote_date_expires'  => date('Y-m-d', time() + 86400 * 30),
            'quote_number'        => 'Q-' . uniqid(),
            'quote_url_key'       => md5(uniqid('', true)),
        ]);
    }
}
