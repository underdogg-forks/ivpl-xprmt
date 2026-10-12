<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\UsesCodeIgniterModels;

/**
 * GHSA-chqc-v432-8pj8: the guest-view url_key for invoices and quotes came from a non-cryptographic
 * PRNG (random_string()), so keys were predictable. They now come from generate_secure_token(16):
 * 32 lowercase hex characters from random_bytes(). IpSecurityHelperTest covers the helper itself,
 * but nothing checked that the two models actually call it, so reverting either to random_string()
 * kept the suite green (random_string('alnum', 32) is almost never all hex).
 */
final class UrlKeyGenerationTest extends AbstractTestCase
{
    use UsesCodeIgniterModels;

    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'core/Password_Encryption_Trait.php';
    }

    protected function tearDown(): void
    {
        $this->tearDownCodeIgniter();
        parent::tearDown();
    }

    #[Test]
    public function it_generates_a_32_character_hex_url_key_for_invoices_that_differs_every_time(): void
    {
        /* Arrange */
        $ci = $this->bootCodeIgniter();
        $ci->load->model('invoices/mdl_invoices');

        /* Act */
        $keys = [];
        for ($i = 0; $i < 20; $i++) {
            $keys[] = $ci->mdl_invoices->get_url_key();
        }

        /* Assert: a random_string('alnum') key is essentially never 32 hex characters, let alone 20 in a row */
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        }
        self::assertCount(20, array_unique($keys), 'url keys must not repeat.');
    }

    #[Test]
    public function it_generates_a_32_character_hex_url_key_for_quotes_that_differs_every_time(): void
    {
        /* Arrange */
        $ci = $this->bootCodeIgniter();
        $ci->load->model('quotes/mdl_quotes');

        /* Act */
        $keys = [];
        for ($i = 0; $i < 20; $i++) {
            $keys[] = $ci->mdl_quotes->get_url_key();
        }

        /* Assert */
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        }
        self::assertCount(20, array_unique($keys));
    }
}
