<?php

namespace Tests\Unit\Clients\Helpers;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pre-1.7 clients could end up with the client form's internal 'custom' sentinel value saved
 * literally into client_title (the free-text title it stands for was never saved). format_client()
 * is the single rendering point used by every invoice/quote/client/guest view and PDF template, so
 * it must treat that bare sentinel as blank rather than printing it as if it were a real title.
 */
final class ClientTitleCustomSentinelTest extends TestCase
{
    private mixed $previousCi = null;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 4);
        require_once $root . '/application/helpers/trans_helper.php';
        require_once $root . '/application/helpers/client_helper.php';
        require_once $root . '/application/libraries/ClientTitleEnum.php';

        $this->previousCi                = $GLOBALS['unitCiInstance'] ?? null;
        $GLOBALS['unitCiInstance']       = (object) [];
        $GLOBALS['unitCiInstance']->lang = new class () {
            public function line(string $line): string
            {
                return 'T:' . $line;
            }
        };
    }

    protected function tearDown(): void
    {
        $GLOBALS['unitCiInstance'] = $this->previousCi;

        parent::tearDown();
    }

    #[Test]
    public function it_prints_nothing_for_the_bare_custom_sentinel(): void
    {
        /* Arrange: the exact stale value pre-1.7 installs could carry */
        $client = (object) ['client_name' => 'Acme', 'client_surname' => '', 'client_title' => 'custom'];

        /* Act */
        $result = format_client($client);

        /* Assert: no "Custom" prefix, just the name */
        self::assertSame('Acme', $result);
    }

    #[Test]
    public function it_still_prints_a_genuine_custom_title_as_free_text(): void
    {
        /* Arrange: a real custom title is not the bare 'custom' string, so it must still show */
        $client = (object) ['client_name' => 'Acme', 'client_surname' => '', 'client_title' => 'Baron'];

        /* Act */
        $result = format_client($client);

        /* Assert */
        self::assertSame('Baron Acme', $result);
    }

    #[Test]
    public function it_still_translates_a_known_title_code(): void
    {
        /* Arrange */
        $client = (object) ['client_name' => 'Acme', 'client_surname' => '', 'client_title' => 'mr'];

        /* Act */
        $result = format_client($client);

        /* Assert: known codes still go through trans(), unaffected by the sentinel guard */
        self::assertSame('T:mr Acme', $result);
    }
}
