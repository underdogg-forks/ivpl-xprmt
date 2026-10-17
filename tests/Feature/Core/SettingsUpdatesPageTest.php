<?php

namespace Tests\Feature\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * The update check and news panel of the settings page (application/modules/settings/views/partial_settings_updates.php,
 * upstream #1777 and #1787). The browser behaviour is covered by tests/js/settings-updates-flow.test.js; this covers what
 * the server contributes to the script: the real page is rendered with its real PHP interpolations and the script it emits
 * is checked for the bounded requests, the request order and the values PHP puts into it.
 */
#[Group('settings')]
final class SettingsUpdatesPageTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->withEnvironment(['SETUP_COMPLETED' => 'true', 'DISABLE_SETUP' => 'true']);
    }

    #[Test]
    public function it_gives_every_request_of_the_update_and_news_flows_the_same_bounded_timeout(): void
    {
        /* Arrange */
        $script = $this->updatesScript($this->get('/settings')->body());

        /* Act */
        $requests = preg_match_all('/\$\.ajax\(\{/', $script);
        $timeouts = preg_match_all("/'timeout': ajaxTimeout,/", $script);

        /* Assert: six requests (update check and news, each GitHub plus two IDS hosts), none without a timeout */
        self::assertSame(6, $requests);
        self::assertSame($requests, $timeouts, 'Every request needs the shared timeout.');
        self::assertSame(1, preg_match('/var ajaxTimeout = (\d+);/', $script, $m));
        self::assertGreaterThan(0, (int) $m[1]);
        self::assertLessThanOrEqual(30000, (int) $m[1]);
    }

    #[Test]
    public function it_asks_github_first_and_keeps_both_ids_hosts_as_the_fallback(): void
    {
        /* Arrange */
        $script = $this->updatesScript($this->get('/settings')->body());

        /* Act */
        $positions = [];
        foreach ([
            'latest release'    => 'api.github.com/repos/InvoicePlane/InvoicePlane/releases/latest',
            'release list'      => "api.github.com/repos/InvoicePlane/InvoicePlane/releases?per_page=5'",
            'update check .com' => 'ids.invoiceplane.com/updatecheck',
            'update check .org' => 'ids.invoiceplane.org/updatecheck',
            'news .com'         => 'ids.invoiceplane.com/get_news',
            'news .org'         => 'ids.invoiceplane.org/get_news',
        ] as $label => $needle) {
            $positions[$label] = strpos($script, $needle);
        }

        /* Assert: every endpoint is present, and the legacy hosts are only the fallback of the GitHub request */
        foreach ($positions as $label => $position) {
            self::assertNotFalse($position, 'Missing request: ' . $label);
        }
        self::assertSame(1, substr_count($script, 'function legacyUpdateCheck'));
        self::assertSame(1, substr_count($script, 'function legacyNews()'));
        self::assertSame(1, substr_count($script, 'function legacyNewsOrg()'));
    }

    #[Test]
    public function it_puts_the_installed_version_into_the_script(): void
    {
        /* Arrange: the installed version is the newest row of ip_versions (Mdl_versions::get_current_version()) */
        $this->databaseInsert('ip_versions', ['version_date_applied' => '99999999999999', 'version_file' => '999_9.8.7.sql', 'version_sql_errors' => 0]);

        try {
            /* Act */
            $script = $this->updatesScript($this->get('/settings')->body());
        } finally {
            $this->databaseDelete('ip_versions', ['version_file' => '999_9.8.7.sql']);
        }

        /* Assert */
        self::assertStringContainsString('var ip_version = "9.8.7";', $script);
    }

    #[Test]
    public function it_emits_the_date_label_of_a_news_entry_as_a_json_string(): void
    {
        /* Arrange */
        $script = $this->updatesScript($this->get('/settings')->body());

        /* Act */
        $hasJsonLabel = str_contains($script, '.text("Date" + \': \' + date)');

        /* Assert: a quoted JSON string, not a bare PHP echo inside a JavaScript expression */
        self::assertTrue($hasJsonLabel, 'The release date label must be a JSON-encoded string.');
    }

    private function updatesScript(string $body): string
    {
        self::assertStringNotContainsString('A PHP Error was encountered', $body);
        $start = strpos($body, 'var checktime');
        self::assertNotFalse($start, 'The update check script is missing from the settings page.');

        return substr($body, $start, 12000);
    }
}
