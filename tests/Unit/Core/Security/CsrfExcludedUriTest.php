<?php

namespace Tests\Unit\Core\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #1694 / GHSA-mhvh-4j3w-7pvj (PR #1725): verify_csrf_token() may only trust "the framework already
 * validated and consumed the token" when the framework's csrf_verify() actually ran for the request.
 * For a URI listed in csrf_exclude_uris it returns early without touching the token, so a null
 * submitted token there means "never checked" and must be rejected.
 */
#[Group('security')]
class CsrfExcludedUriTest extends TestCase
{
    private const TOKEN = 'unit-csrf-token-0123456789';

    /** @var string|null */
    private $originalMethod;

    protected function setUp(): void
    {
        require_once ROOT_PATH . '/application/helpers/security_helper.php';

        $this->originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->originalMethod;
        }

        unset($GLOBALS['unitCiInstance'], $GLOBALS['unitCiConfig']);
    }

    /**
     * @return array<string, array{string, list<string>, bool}>
     */
    public static function exclusionCases(): array
    {
        return [
            'exact match'                    => ['api/webhook', ['api/webhook'], true],
            'wildcard match'                 => ['api/v1/pay', ['api/.*'], true],
            'match is case-insensitive'      => ['API/Webhook', ['api/webhook'], true],
            'pattern is anchored at start'   => ['x/api/webhook', ['api/webhook'], false],
            'pattern is anchored at end'     => ['api/webhook/extra', ['api/webhook'], false],
            'second pattern matches'         => ['hooks/stripe', ['api/.*', 'hooks/.*'], true],
            'no pattern matches'             => ['invoices/index', ['api/.*', 'hooks/.*'], false],
            'empty exclusion list'           => ['api/webhook', [], false],
            'empty uri never matches a path' => ['', ['api/.*'], false],
            'malformed regex does not match' => ['api/webhook', ['api/(unclosed'], false],
        ];
    }

    #[Test]
    public function it_trusts_a_consumed_token_on_a_post_to_a_normal_uri(): void
    {
        $this->request('POST', 'invoices/delete/5', excluded: ['api/.*']);

        self::assertTrue(verify_csrf_token(), 'the framework validated and consumed the token for this URI');
    }

    #[Test]
    public function it_trusts_a_consumed_token_when_no_uris_are_excluded(): void
    {
        $this->request('POST', 'invoices/delete/5', excluded: []);

        self::assertTrue(verify_csrf_token());
    }

    #[Test]
    public function it_rejects_a_tokenless_post_to_an_excluded_uri(): void
    {
        $this->request('POST', 'api/webhook', excluded: ['api/.*']);

        self::assertFalse(verify_csrf_token(), 'the framework skipped this URI, so a null token was never checked');
    }

    #[Test]
    public function it_accepts_a_matching_double_submit_token_on_an_excluded_uri(): void
    {
        $this->request('POST', 'api/webhook', excluded: ['api/.*'], post: ['_ip_csrf' => self::TOKEN], cookies: ['ip_csrf_cookie' => self::TOKEN]);

        self::assertTrue(verify_csrf_token());
    }

    #[Test]
    public function it_rejects_a_mismatching_token_on_an_excluded_uri(): void
    {
        $this->request('POST', 'api/webhook', excluded: ['api/.*'], post: ['_ip_csrf' => 'attacker-token'], cookies: ['ip_csrf_cookie' => self::TOKEN]);

        self::assertFalse(verify_csrf_token());
    }

    #[Test]
    public function it_rejects_a_token_when_the_cookie_is_missing_on_an_excluded_uri(): void
    {
        $this->request('POST', 'api/webhook', excluded: ['api/.*'], post: ['_ip_csrf' => self::TOKEN]);

        self::assertFalse(verify_csrf_token());
    }

    #[Test]
    public function it_rejects_a_null_token_on_a_non_post_request(): void
    {
        $this->request('GET', 'invoices/delete/5', excluded: []);

        self::assertFalse(verify_csrf_token(), 'only a POST can have had its token consumed by the framework');
    }

    #[Test]
    public function it_allows_everything_when_csrf_protection_is_off(): void
    {
        $this->request('POST', 'api/webhook', excluded: ['api/.*'], protection: false);

        self::assertTrue(verify_csrf_token());
    }

    /**
     * @param list<string> $excluded
     */
    #[Test]
    #[DataProvider('exclusionCases')]
    public function it_mirrors_the_framework_uri_exclusion_match(string $uri, array $excluded, bool $expected): void
    {
        $this->request('POST', $uri, excluded: $excluded);
        $CI = & get_instance();

        set_error_handler(static fn (): bool => true);

        try {
            self::assertSame($expected, _is_uri_csrf_excluded($CI));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param list<string>          $excluded
     * @param array<string, string> $post
     * @param array<string, string> $cookies
     */
    private function request(string $method, string $uri, array $excluded, array $post = [], array $cookies = [], bool $protection = true): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;

        $GLOBALS['unitCiConfig'] = [
            'csrf_protection'   => $protection,
            'csrf_token_name'   => '_ip_csrf',
            'csrf_cookie_name'  => 'ip_csrf_cookie',
            'csrf_exclude_uris' => $excluded,
        ];

        $GLOBALS['unitCiInstance'] = new class ($uri, $post, $cookies) {
            public object $uri;

            public object $input;

            public function __construct(string $uri, array $post, array $cookies)
            {
                $this->uri = new class ($uri) {
                    public function __construct(private string $uri) {}

                    public function uri_string(): string
                    {
                        return $this->uri;
                    }
                };

                $this->input = new class ($post, $cookies) {
                    public function __construct(private array $post, private array $cookies) {}

                    public function post(string $key): mixed
                    {
                        return $this->post[$key] ?? null;
                    }

                    public function cookie(string $key): mixed
                    {
                        return $this->cookies[$key] ?? null;
                    }

                    public function ip_address(): string
                    {
                        return '127.0.0.1';
                    }
                };
            }
        };
    }
}
