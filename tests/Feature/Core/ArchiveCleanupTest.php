<?php

namespace Tests\Feature\Core;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\AbstractTestCase;

/**
 * AbstractTestCase::tearDown() removes the PDFs a test left in uploads/archive and nothing else. Requests that
 * archive an invoice leaked about 55 files per full run; after a few runs /invoices/archive listed so many that
 * the harness could not read the response back (ArchivedPdfFilenameTest failed).
 *
 * The second test runs after the first one's tearDown (#[Depends]) and looks at what is left.
 */
final class ArchiveCleanupTest extends AbstractTestCase
{
    private static ?string $keeper = null;

    private static ?string $leak = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        /* Created once, before any test's setUp() snapshots the folder, so it counts as "there before the test" */
        self::$keeper = dirname(__DIR__, 3) . '/uploads/archive/keeper_' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents(self::$keeper, '%PDF-1.4');
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$keeper, self::$leak] as $file) {
            if ($file !== null) {
                @unlink($file);
            }
        }

        parent::tearDownAfterClass();
    }

    #[Test]
    public function it_lets_a_test_leave_a_pdf_in_the_archive_folder(): void
    {
        /* Arrange */
        self::$leak = $this->archiveFolder() . 'leak_' . bin2hex(random_bytes(6)) . '.pdf';

        /* Act */
        file_put_contents(self::$leak, '%PDF-1.4');

        /* Assert: the premise of the next test */
        self::assertFileExists(self::$leak);
    }

    #[Test]
    #[Depends('it_lets_a_test_leave_a_pdf_in_the_archive_folder')]
    public function it_removes_the_pdf_a_test_created_and_keeps_the_ones_that_were_there_before(): void
    {
        /* Assert: the previous test's tearDown ran */
        self::assertFileDoesNotExist((string) self::$leak, 'a PDF created during a test is removed after it');
        self::assertFileExists((string) self::$keeper, 'a PDF that existed before the test is left alone');
    }

    #[Test]
    public function it_deletes_nothing_when_setUp_never_took_the_snapshot(): void
    {
        /* Arrange: a test class whose own setUp() does not call parent::setUp() never takes the snapshot;
           deleting "everything new" against an empty snapshot would wipe the whole folder */
        $bystander = $this->archiveFolder() . 'bystander_' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($bystander, '%PDF-1.4');
        $withoutSnapshot = new class ('it_does_nothing') extends AbstractTestCase {};

        /* Act */
        (new ReflectionMethod($withoutSnapshot, 'tearDown'))->invoke($withoutSnapshot);

        /* Assert */
        try {
            self::assertFileExists($bystander);
        } finally {
            @unlink($bystander);
        }
    }

    private function archiveFolder(): string
    {
        return dirname(__DIR__, 3) . '/uploads/archive/';
    }
}
