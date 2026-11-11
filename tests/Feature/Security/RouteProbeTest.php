<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Support\SecurityScanner\RouteInventory;
use Throwable;

/**
 * Sends the same hostile request to every route instead of to the one that was reported. Each probe keeps a list of
 * the routes that fail it today (`tests/Support/SecurityScanner/probe-baseline.txt`); the list may only shrink.
 */
#[Group('security')]
#[Group('slow')]
final class RouteProbeTest extends AbstractTestCase
{
    private const BASELINE = __DIR__ . '/../../Support/SecurityScanner/probe-baseline.txt';

    private const MARKER = 'ipauditmarker';

    private const LOGIN_GUARDS = ['Admin_Controller', 'Guest_Controller', 'User_Controller'];

    #[Test]
    public function it_finds_the_controllers_of_the_application(): void
    {
        /* Act */
        $routes = $this->routes();
        $uris   = array_column($routes, 'uri');

        /* Assert: the probes below are worthless if the inventory is empty or misses a module */
        $this->assertGreaterThan(150, count($routes));
        $this->assertContains('invoices/view/{invoice_id}', $uris);
        $this->assertContains('guest/gateways/stripe/callback/{checkout_session_id}', $uris);
        $this->assertContains('sessions/login', $uris);
    }

    #[Test]
    public function it_serves_no_login_protected_route_to_a_visitor_without_a_session(): void
    {
        /* Arrange */
        $failures = [];

        /* Act */
        foreach ($this->routes() as $route) {
            if ( ! in_array($route['middleware'][0], self::LOGIN_GUARDS, true)) {
                continue;
            }

            foreach (['GET', 'POST'] as $method) {
                [$status, $body] = $this->send($method, $route, '1');

                if ($status >= 500 || ($status < 300 && mb_trim($body) !== '')) {
                    $failures[] = 'no-session ' . $method . ' ' . $route['uri'] . ' -> ' . $status;
                }
            }
        }

        /* Assert */
        $this->assertSame($this->baseline('no-session'), $failures);
    }

    #[Test]
    public function it_never_prints_or_crashes_on_a_malformed_id_in_any_route(): void
    {
        /* Arrange: real records, so that a page which loads a record by the numeric start of the id is reached.
         * A route that could change or remove something gets an id that matches nothing. */
        $this->actingAsAdmin();
        $clientId = $this->seedClient();
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'quotes_expire_after', 'setting_value' => '15']);
        $quoteId = $this->databaseInsert('ip_quotes', [
            'user_id'            => 1, 'client_id' => $clientId, 'invoice_group_id' => 1, 'quote_status_id' => 2,
            'quote_number'       => 'QUO-PROBE', 'quote_url_key' => bin2hex(random_bytes(16)),
            'quote_date_created' => date('Y-m-d'), 'quote_date_modified' => date('Y-m-d H:i:s'),
            'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
        ]);
        $this->databaseInsert('ip_quote_amounts', [
            'quote_id'        => $quoteId, 'quote_item_subtotal' => '0.00', 'quote_item_tax_total' => '0.00',
            'quote_tax_total' => '0.00', 'quote_total' => '0.00',
        ]);
        $ids      = ['client_id' => $clientId, 'invoice_id' => $this->seedInvoice($clientId), 'quote_id' => $quoteId];
        $failures = [];

        /* Act */
        foreach ($this->routes() as $route) {
            if ($route['parameters'] === []) {
                continue;
            }

            $risky           = preg_match('/delete|remove|cron|recur|generate|upload|send|import|mark|copy|create|save/', $route['action']) === 1;
            $id              = $risky ? 999999 : ($ids[$route['parameters'][0]] ?? 1);
            [$status, $body] = $this->send('GET', $route, $id . '-' . self::MARKER . '(1)');
            preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $body, $scripts);

            if ($status >= 500) {
                $failures[] = 'malformed-id ' . $route['uri'] . ' -> crash';
            } elseif (str_contains(implode("\n", $scripts[1]), self::MARKER)) {
                $failures[] = 'malformed-id ' . $route['uri'] . ' -> printed in script';
            }
        }

        /* Assert */
        $this->assertSame($this->baseline('malformed-id'), $failures);
    }

    /**
     * @return list<array{uri: string, middleware: list<string>, parameters: list<string>}>
     */
    private function routes(): array
    {
        return (new RouteInventory(dirname(__DIR__, 3)))->routes();
    }

    /**
     * An Ajax controller answers nothing at all to a request without the ajax header, so it is asked with one.
     *
     * @param array{uri: string, action: string} $route
     *
     * @return array{int, string}
     */
    private function send(string $method, array $route, string $value): array
    {
        $uri = '/' . preg_replace('/\{\w+\}/', $value, $route['uri']);

        try {
            $response = match (true) {
                str_starts_with($route['action'], 'Ajax@') => $this->ajax($method, $uri),
                $method === 'GET'                          => $this->get($uri),
                default                                    => $this->post($uri),
            };

            return [$response->statusCode(), $response->body()];
        } catch (Throwable) {
            return [500, ''];
        }
    }

    /**
     * @return list<string>
     */
    private function baseline(string $probe): array
    {
        $lines = file(self::BASELINE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, $probe . ' ')));
    }
}
