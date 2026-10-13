<?php

namespace Tests\Feature\Invoices;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionFunction;
use Tests\AbstractTestCase;

/**
 * GHSA-qq8q-gf24-576m (PR #1716): archived invoice PDFs get an unguessable name.
 *
 * Mutation target: application/helpers/mpdf_helper.php ~220-223,
 * `$random_token = bin2hex(random_bytes(16)); $archived_file = UPLOADS_ARCHIVE_FOLDER . $random_token . '.pdf'`.
 * Killed mutants: restoring a name derived from the invoice number/filename, md5/sha of it
 * (deterministic: second generation would overwrite, giving one new file), random_bytes(8) (16 hex).
 * Writes into the real uploads/archive and removes exactly the files it created.
 */
#[Group('security')]
final class ArchivedPdfFilenameTest extends AbstractTestCase
{
    /** @var list<string> */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function it_archives_each_generated_invoice_pdf_under_a_fresh_32_hex_token(): void
    {
        /* Arrange */
        $this->actingAsAdmin();
        $clientId = $this->seedClient();
        $invoice  = $this->seedInvoice($clientId, ['invoice_number' => 'ZZSECRET7731', 'invoice_status_id' => 2], ['invoice_total' => '100.00', 'invoice_balance' => '100.00']);
        $before   = $this->archiveFiles();

        /* Act */
        $first  = $this->get('/invoices/generate_pdf/' . $invoice);
        $second = $this->get('/invoices/generate_pdf/' . $invoice);

        /* Assert */
        $this->assertResponseStatusCode($first, 200);
        $this->assertResponseStatusCode($second, 200);
        $new           = array_values(array_diff($this->archiveFiles(), $before));
        $this->created = array_map(static fn (string $f): string => ROOT_PATH . '/uploads/archive/' . $f, $new);

        self::assertCount(2, $new, 'Two generations must produce two distinct archive files (a deterministic name would collide).');
        foreach ($new as $name) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $name);
            self::assertStringNotContainsString('ZZSECRET7731', $name);
            self::assertStringNotContainsStringIgnoringCase('invoice', $name);
        }
    }

    #[Test]
    public function it_draws_the_archive_name_from_the_cryptographic_random_source(): void
    {
        /* Arrange: a predictable 32-hex name (md5 of the time, mt_rand, ...) passes the format and
         * uniqueness checks above, so the call site itself is pinned */
        require_once ROOT_PATH . '/application/helpers/mpdf_helper.php';
        $function = new ReflectionFunction('pdf_create');

        /* Act */
        $lines  = array_slice(file((string) $function->getFileName()), $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1);
        $source = implode('', $lines);

        /* Assert */
        self::assertStringContainsString('$random_token  = bin2hex(random_bytes(16));', $source);
    }

    /** @return list<string> */
    private function archiveFiles(): array
    {
        return array_map('basename', glob(ROOT_PATH . '/uploads/archive/*.pdf') ?: []);
    }
}
