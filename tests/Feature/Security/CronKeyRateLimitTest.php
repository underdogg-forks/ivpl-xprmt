<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\AbstractTestCase;

/**
 * GHSA-28fh-2656-wc63: the unauthenticated cron endpoint's only credential, cron_key, could be guessed at unlimited
 * speed. Wrong keys are now counted per IP in ip_login_log (10 within 15 minutes) and the endpoint answers 429 before it
 * even looks at the key. The key generation is tested elsewhere; the limiter had no test at all.
 */
final class CronKeyRateLimitTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseDelete('ip_settings', ['setting_key' => 'cron_key']);
        $this->databaseInsert('ip_settings', ['setting_key' => 'cron_key', 'setting_value' => 'the-real-key']);
    }

    #[Test]
    public function it_counts_each_wrong_key_attempt_for_the_calling_ip(): void
    {
        /* Act: two wrong keys (show_error 500 surfaces as an exception in the harness) */
        $this->wrongKeyRequest();
        $this->wrongKeyRequest();

        /* Assert */
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->key(), 'log_count' => 2]);
    }

    #[Test]
    public function it_answers_429_and_ignores_even_the_correct_key_once_the_attempt_limit_is_reached(): void
    {
        /* Arrange: 10 wrong attempts already inside the window */
        $this->seedLog(10, 'now');

        /* Act */
        $response = $this->get('/invoices/cron/recur/the-real-key');

        /* Assert: blocked before the key is checked, and the counter is not reset by the (correct) key */
        $this->assertResponseStatusCode($response, 429);
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->key(), 'log_count' => 10]);
    }

    #[Test]
    public function it_still_accepts_the_correct_key_one_attempt_below_the_limit_and_clears_the_counter(): void
    {
        /* Arrange */
        $this->seedLog(9, 'now');

        /* Act */
        $response = $this->get('/invoices/cron/recur/the-real-key');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $this->key()]);
    }

    #[Test]
    public function it_lifts_the_limit_once_the_window_has_elapsed(): void
    {
        /* Arrange: at the limit, but the last attempt was 16 minutes ago (window is 15) */
        $this->seedLog(10, '-16 minutes');

        /* Act */
        $response = $this->get('/invoices/cron/recur/the-real-key');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $this->key()]);
    }

    #[Test]
    public function it_restarts_the_count_at_one_when_a_wrong_key_arrives_after_the_window_elapsed(): void
    {
        /* Arrange */
        $this->seedLog(10, '-16 minutes');

        /* Act */
        $this->wrongKeyRequest();

        /* Assert: the stale counter was reset first, then this attempt counted */
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->key(), 'log_count' => 1]);
    }

    private function wrongKeyRequest(): void
    {
        try {
            $this->get('/invoices/cron/recur/wrong-key');
            self::fail('A wrong cron key must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Wrong cron key provided', $exception->getMessage());
        }
    }

    private function key(): string
    {
        return 'cron_key:' . hash('sha256', '127.0.0.1');
    }

    private function seedLog(int $count, string $when): void
    {
        $this->databaseInsert('ip_login_log', [
            'login_name' => $this->key(), 'log_count' => $count, 'log_create_timestamp' => date('Y-m-d H:i:s', strtotime($when)),
        ]);
    }
}
