<?php

namespace Tests\Feature\Quotes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Quotes;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;

/**
 * Quotes controller — application/modules/quotes/controllers/Quotes.php.
 *
 * Quotes are created/edited through quotes/ajax/* (see QuotesAjaxControllerTest).
 * This controller lists, views, deletes and strips tax rates. Absorbs the
 * non-AJAX half of QuotesTest and Issue1694QuotesDeleteCsrfTest.
 */
#[Group('quotes')]
#[CoversClass(Quotes::class)]
class QuotesControllerTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;

    // =========================================================================
    // Guest Portal Tests — guest/controllers/Quotes.php
    // =========================================================================

    private const DRAFT = 1;

    private const SENT = 2;

    private const VIEWED = 3;

    private const APPROVED = 4;

    private const REJECTED = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    /** @return array<string, array{0: string, 1: list<string>, 2: list<string>}> */
    public static function guestStatusFilters(): array
    {
        return [
            'all shows every guest-visible own quote' => ['all', ['OWN-SENT', 'OWN-APPROVED', 'OWN-REJECTED'], ['OWN-DRAFT', 'OTHER-APPROVED']],
            'viewed'                                  => ['viewed', ['OWN-VIEWED'], ['OWN-SENT', 'OWN-APPROVED']],
            'approved'                                => ['approved', ['OWN-APPROVED'], ['OWN-REJECTED', 'OTHER-APPROVED']],
            'rejected'                                => ['rejected', ['OWN-REJECTED'], ['OWN-APPROVED', 'OTHER-APPROVED']],
        ];
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function guestDecisions(): array
    {
        return ['approve' => ['approve', self::APPROVED], 'reject' => ['reject', self::REJECTED]];
    }

    // =========================================================================
    // Item Amount/Sign Logic
    // =========================================================================

    /** @return array<string, array{string, string, string, string}> */
    public static function negativeAmounts(): array
    {
        return [
            'negative quantity' => ['-3', '150', '', 'item_quantity'],
            'negative price'    => ['3', '-150', '', 'item_price'],
            'negative discount' => ['1', '10', '-5', 'item_discount_amount'],
        ];
    }

    // =========================================================================
    // Amount/Sign Message Boundaries (GHSA-jvcw-w8pr-j92c)
    // =========================================================================

    /** @return array<string, array{array<string, string>, string, string}> */
    public static function singleDiscountFields(): array
    {
        return [
            'negative amount'  => [['quote_discount_amount' => '-1'], 'quote_discount_amount', 'The discount cannot be negative.'],
            'negative percent' => [['quote_discount_percent' => '-1'], 'quote_discount_percent', 'The discount cannot be negative.'],
            'percent over 100' => [['quote_discount_percent' => '100.5'], 'quote_discount_percent', 'The discount percentage cannot exceed 100.'],
        ];
    }

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    #[Test]
    public function it_lists_every_quote(): void
    {
        /* Arrange */
        $clientId = $this->seedClient(['client_name' => 'Quote List Client']);
        $this->seedQuote(['client_id' => $clientId, 'quote_number' => 'QUO-LIST-0001']);
        $this->seedQuote(['client_id' => $clientId, 'quote_number' => 'QUO-LIST-0002']);

        /* Act */
        $response = $this->get('/quotes/status/all');

        /* Assert */
        $this->assertResponseBodyContains($response, 'QUO-LIST-0001');
        $this->assertResponseBodyContains($response, 'QUO-LIST-0002');
    }

    #[Test]
    public function it_shows_a_single_quote(): void
    {
        /* Arrange */
        $clientId = $this->seedClient(['client_name' => 'Quote View Client']);
        $quoteId  = $this->seedQuote(['client_id' => $clientId, 'quote_number' => 'QUO-VIEW-0007']);

        /* Act */
        $response = $this->get('/quotes/view/' . $quoteId);

        /* Assert */
        $this->assertResponseBodyContains($response, 'QUO-VIEW-0007');
        $this->assertResponseBodyContains($response, 'Quote View Client');
    }

    // -------------------------------------------------------------------------
    // Delete — happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_deletes_a_quote(): void
    {
        /* Arrange */
        $clientId = $this->seedClient();
        $id       = $this->seedQuote(['client_id' => $clientId, 'quote_number' => 'QUO-DEL-0001']);
        $keep     = $this->seedQuote(['client_id' => $clientId, 'quote_number' => 'QUO-DEL-0002']);

        /* Act */
        $response = $this->post('/quotes/delete/' . $id, []);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'Delete redirects back to the quote list.');
        $this->assertDatabaseMissing('ip_quotes', ['quote_id' => $id]);
        $this->assertDatabaseHas('ip_quotes', ['quote_id' => $keep]);
    }

    // -------------------------------------------------------------------------
    // Delete — CSRF regression (#1694)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_still_deletes_a_quote_when_csrf_protection_is_on_and_the_token_is_valid(): void
    {
        /* Arrange */
        $this->enableCsrfProtection();
        $id = $this->seedQuote();

        /* Act */
        $response = $this->postWithValidCsrfToken('/quotes/delete/' . $id);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'A valid-token delete redirects.');
        $this->assertDatabaseMissing('ip_quotes', ['quote_id' => $id]);
    }

    #[Test]
    public function it_does_not_delete_a_quote_when_the_csrf_token_is_missing(): void
    {
        /* Arrange */
        $this->enableCsrfProtection();
        $id = $this->seedQuote(['quote_number' => 'QUO-CSRF-KEPT']);

        /* Act */
        $response = $this->postWithoutCsrfToken('/quotes/delete/' . $id);

        /* Assert */
        self::assertFalse($response->isRedirect(), 'A token-less delete must not reach the controller.');
        $this->assertDatabaseHas('ip_quotes', ['quote_id' => $id, 'quote_number' => 'QUO-CSRF-KEPT']);
    }

    // -------------------------------------------------------------------------
    // Quote tax rates — Quotes::delete_quote_tax
    // -------------------------------------------------------------------------

    #[Test]
    public function it_removes_a_tax_rate_from_a_quote(): void
    {
        /* Arrange */
        $quoteId = $this->seedQuote();
        $rateId  = $this->databaseInsert('ip_quote_tax_rates', ['quote_id' => $quoteId, 'tax_rate_id' => 1, 'include_item_tax' => 0, 'quote_tax_rate_amount' => '0.00']);
        $keepId  = $this->databaseInsert('ip_quote_tax_rates', ['quote_id' => $quoteId, 'tax_rate_id' => 1, 'include_item_tax' => 0, 'quote_tax_rate_amount' => '0.00']);

        /* Act */
        $response = $this->post('/quotes/delete_quote_tax/' . $quoteId . '/' . $rateId, []);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'Removing a tax rate redirects back to the quote.');
        $this->assertDatabaseMissing('ip_quote_tax_rates', ['quote_tax_rate_id' => $rateId]);
        $this->assertDatabaseHas('ip_quote_tax_rates', ['quote_tax_rate_id' => $keepId]);
    }

    // -------------------------------------------------------------------------
    // Guest access — always last
    // -------------------------------------------------------------------------

    #[Test]
    public function it_redirects_a_guest_to_login_and_leaks_no_quote(): void
    {
        /* Arrange */
        $this->seedQuote(['quote_number' => 'QUO-SECRET-0001']);
        $this->actingAsGuest();

        /* Act */
        $response = $this->get('/quotes/status/all');

        /* Assert */
        self::assertTrue($response->isRedirect(), 'Unauthenticated request must redirect to login.');
        $this->assertResponseBodyNotContains($response, 'QUO-SECRET-0001');
    }

    #[Test]
    public function it_redirects_the_guest_index_to_open_quotes(): void
    {
        /* Arrange */
        $ownClient = $this->seedClient(['client_name' => 'Own Client']);
        $this->actingAsGuestFor($ownClient);

        /* Act */
        $response = $this->get('/guest/quotes');

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'guest/quotes/status/open');
        $this->assertResponseOk($this->get('/guest/quotes/status/open'));
    }

    #[Test]
    public function it_lists_only_the_guests_own_open_quotes(): void
    {
        /* Arrange */
        $ownClient   = $this->seedClient(['client_name' => 'Own Client']);
        $otherClient = $this->seedClient(['client_name' => 'Other Client']);
        $this->actingAsGuestFor($ownClient);

        $this->seedGuestQuote($ownClient, self::SENT, 'OWN-SENT');
        $this->seedGuestQuote($ownClient, self::VIEWED, 'OWN-VIEWED');
        $this->seedGuestQuote($ownClient, self::DRAFT, 'OWN-DRAFT');
        $this->seedGuestQuote($ownClient, self::APPROVED, 'OWN-APPROVED');
        $this->seedGuestQuote($otherClient, self::SENT, 'OTHER-SENT');

        /* Act */
        $response = $this->get('/guest/quotes/status/open');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, 'OWN-SENT');
        $this->assertResponseBodyContains($response, 'OWN-VIEWED');
        foreach (['OWN-DRAFT', 'OWN-APPROVED', 'OTHER-SENT'] as $hidden) {
            $this->assertResponseBodyNotContains($response, $hidden);
        }
    }

    #[Test]
    #[DataProvider('guestStatusFilters')]
    public function it_filters_guest_listing_by_status_without_crossing_clients(string $status, array $shown, array $hidden): void
    {
        /* Arrange */
        $ownClient   = $this->seedClient(['client_name' => 'Own Client']);
        $otherClient = $this->seedClient(['client_name' => 'Other Client']);
        $this->actingAsGuestFor($ownClient);

        $this->seedGuestQuote($ownClient, self::DRAFT, 'OWN-DRAFT');
        $this->seedGuestQuote($ownClient, self::SENT, 'OWN-SENT');
        $this->seedGuestQuote($ownClient, self::VIEWED, 'OWN-VIEWED');
        $this->seedGuestQuote($ownClient, self::APPROVED, 'OWN-APPROVED');
        $this->seedGuestQuote($ownClient, self::REJECTED, 'OWN-REJECTED');
        $this->seedGuestQuote($otherClient, self::APPROVED, 'OTHER-APPROVED');

        /* Act */
        $response = $this->get('/guest/quotes/status/' . $status);

        /* Assert */
        foreach ($shown as $number) {
            $this->assertResponseBodyContains($response, $number);
        }
        foreach ($hidden as $number) {
            $this->assertResponseBodyNotContains($response, $number);
        }
    }

    #[Test]
    public function it_shows_an_own_quote_and_marks_a_sent_quote_as_viewed(): void
    {
        /* Arrange */
        $ownClient = $this->seedClient();
        $this->actingAsGuestFor($ownClient);
        $quoteId = $this->seedGuestQuote($ownClient, self::SENT, 'OWN-VIEW-1');

        /* Act */
        $response = $this->get('/guest/quotes/view/' . $quoteId);

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, 'OWN-VIEW-1');
        $this->assertDatabaseHas('ip_quotes', ['quote_id' => $quoteId, 'quote_status_id' => self::VIEWED]);
    }

    #[Test]
    public function it_hides_another_clients_quote_and_does_not_mark_it_viewed(): void
    {
        /* Arrange */
        $ownClient   = $this->seedClient();
        $otherClient = $this->seedClient();
        $this->actingAsGuestFor($ownClient);
        $quoteId = $this->seedGuestQuote($otherClient, self::SENT, 'OTHER-VIEW-1');

        /* Act */
        $response = $this->get('/guest/quotes/view/' . $quoteId);

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertResponseBodyNotContains($response, 'OTHER-VIEW-1');
        $this->assertDatabaseHas('ip_quotes', ['quote_id' => $quoteId, 'quote_status_id' => self::SENT]);
    }

    #[Test]
    public function it_hides_a_draft_quote_even_from_its_own_client(): void
    {
        /* Arrange */
        $ownClient = $this->seedClient();
        $this->actingAsGuestFor($ownClient);
        $quoteId = $this->seedGuestQuote($ownClient, self::DRAFT, 'OWN-DRAFT-VIEW');

        /* Act */
        $response = $this->get('/guest/quotes/view/' . $quoteId);

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertResponseBodyNotContains($response, 'OWN-DRAFT-VIEW');
    }

    #[Test]
    public function it_refuses_the_pdf_of_another_clients_quote(): void
    {
        /* Arrange */
        $ownClient   = $this->seedClient();
        $otherClient = $this->seedClient();
        $this->actingAsGuestFor($ownClient);
        $quoteId = $this->seedGuestQuote($otherClient, self::SENT, 'OTHER-PDF-1');

        /* Act */
        $response = $this->get('/guest/quotes/generate_pdf/' . $quoteId);

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertDatabaseHas('ip_quotes', ['quote_id' => $quoteId, 'quote_status_id' => self::SENT]);
    }

    #[Test]
    #[DataProvider('guestDecisions')]
    public function it_applies_the_decision_to_an_own_open_quote(string $action, int $expectedStatus): void
    {
        /* Arrange */
        $ownClient = $this->seedClient();
        $this->actingAsGuestFor($ownClient);
        $quoteId = $this->seedGuestQuote($ownClient, self::SENT, 'OWN-DECIDE-1');

        /* Act */
        $response = $this->postWithValidCsrfToken('/guest/quotes/' . $action . '/' . $quoteId);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'A decision must redirect back to the portal.');
        $this->assertDatabaseHas('ip_quotes', ['quote_id' => $quoteId, 'quote_status_id' => $expectedStatus]);
    }

    #[Test]
    #[DataProvider('guestDecisions')]
    public function it_cannot_decide_on_another_clients_quote(string $action, int $expectedStatus): void
    {
        /* Arrange */
        $ownClient   = $this->seedClient();
        $otherClient = $this->seedClient();
        $this->actingAsGuestFor($ownClient);
        $quoteId = $this->seedGuestQuote($otherClient, self::SENT, 'OTHER-DECIDE-1');

        /* Act */
        $response = $this->postWithValidCsrfToken('/guest/quotes/' . $action . '/' . $quoteId);

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertDatabaseHas('ip_quotes', ['quote_id' => $quoteId, 'quote_status_id' => self::SENT]);
    }

    #[Test]
    #[DataProvider('guestDecisions')]
    public function it_cannot_change_a_decision_that_was_already_made(string $action, int $expectedStatus): void
    {
        /* Arrange */
        $ownClient = $this->seedClient();
        $this->actingAsGuestFor($ownClient);
        $quoteId = $this->seedGuestQuote($ownClient, self::APPROVED, 'OWN-FINAL-1');

        /* Act */
        $response = $this->postWithValidCsrfToken('/guest/quotes/' . $action . '/' . $quoteId);

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertDatabaseHas('ip_quotes', ['quote_id' => $quoteId, 'quote_status_id' => self::APPROVED]);
    }

    #[Test]
    #[DataProvider('guestDecisions')]
    public function it_cannot_decide_on_a_draft_quote(string $action, int $expectedStatus): void
    {
        /* Arrange */
        $ownClient = $this->seedClient();
        $this->actingAsGuestFor($ownClient);
        $quoteId = $this->seedGuestQuote($ownClient, self::DRAFT, 'OWN-DRAFT-DECIDE');

        /* Act */
        $response = $this->postWithValidCsrfToken('/guest/quotes/' . $action . '/' . $quoteId);

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertDatabaseHas('ip_quotes', ['quote_id' => $quoteId, 'quote_status_id' => self::DRAFT]);
    }

    #[Test]
    #[DataProvider('guestDecisions')]
    public function it_ignores_a_decision_sent_as_a_plain_get(string $action, int $expectedStatus): void
    {
        /* Arrange */
        $ownClient = $this->seedClient();
        $this->actingAsGuestFor($ownClient);
        $quoteId = $this->seedGuestQuote($ownClient, self::SENT, 'OWN-GET-1');

        /* Act */
        $response = $this->get('/guest/quotes/' . $action . '/' . $quoteId);

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertDatabaseHas('ip_quotes', ['quote_id' => $quoteId, 'quote_status_id' => self::SENT]);
    }

    #[Test]
    #[DataProvider('negativeAmounts')]
    public function it_rejects_each_negative_amount_and_keeps_the_quote_unchanged(string $quantity, string $price, string $discount, string $field): void
    {
        /* Arrange */
        [$quoteId, $itemId] = $this->quoteWithOneItem();

        /* Act */
        $response = $this->saveItems($quoteId, [$this->existingItem($quoteId, $itemId), $this->newItem($quoteId, 'Extra', $quantity, $price, $discount)]);

        /* Assert */
        $json = json_decode($response->body(), true);
        self::assertSame(0, $json['success'] ?? null);
        self::assertSame([$field], array_keys($json['validation_errors']));
        $this->assertDatabaseMissing('ip_quote_items', ['quote_id' => $quoteId, 'item_name' => 'Extra']);
        $this->assertDatabaseHas('ip_quote_amounts', ['quote_id' => $quoteId, 'quote_total' => '300.00']);
    }

    #[Test]
    public function it_rejects_the_double_negative_and_a_negative_global_discount(): void
    {
        /* Arrange */
        [$quoteId, $itemId] = $this->quoteWithOneItem();

        /* Act */
        $response = $this->saveItems($quoteId, [$this->existingItem($quoteId, $itemId), $this->newItem($quoteId, 'Adjustment', '-3', '-150')], ['quote_discount_amount' => '-10', 'quote_discount_percent' => '-5']);

        /* Assert */
        $json = json_decode($response->body(), true);
        self::assertSame(0, $json['success'] ?? null);
        self::assertEqualsCanonicalizing(['item_quantity', 'item_price', 'quote_discount_amount', 'quote_discount_percent'], array_keys($json['validation_errors']));
        $this->assertDatabaseHas('ip_quote_amounts', ['quote_id' => $quoteId, 'quote_total' => '300.00']);
    }

    #[Test]
    public function it_rejects_a_discount_percent_above_one_hundred_and_keeps_the_quote_unchanged(): void
    {
        /* Arrange */
        [$quoteId, $itemId] = $this->quoteWithOneItem();

        /* Act */
        $response = $this->saveItems($quoteId, [$this->existingItem($quoteId, $itemId)], ['quote_discount_percent' => '101']);

        /* Assert */
        $json = json_decode($response->body(), true);
        self::assertSame(0, $json['success'] ?? null);
        self::assertSame('The discount percentage cannot exceed 100.', $json['validation_errors']['quote_discount_percent'] ?? null);
        $this->assertDatabaseHas('ip_quote_amounts', ['quote_id' => $quoteId, 'quote_total' => '300.00']);
    }

    #[Test]
    public function it_accepts_a_discount_percent_of_exactly_one_hundred(): void
    {
        /* Arrange */
        [$quoteId, $itemId] = $this->quoteWithOneItem();

        /* Act */
        $response = $this->saveItems($quoteId, [$this->existingItem($quoteId, $itemId)], ['quote_discount_percent' => '100']);

        /* Assert */
        self::assertSame(1, json_decode($response->body(), true)['success'] ?? null, 'Body: ' . $response->body());
    }

    #[Test]
    public function it_still_saves_zero_and_positive_amounts(): void
    {
        /* Arrange */
        [$quoteId, $itemId] = $this->quoteWithOneItem();

        /* Act */
        $response = $this->saveItems($quoteId, [$this->existingItem($quoteId, $itemId), $this->newItem($quoteId, 'Free sample', '0', '0'), $this->newItem($quoteId, 'Extra', '2', '10')]);

        /* Assert */
        $json = json_decode($response->body(), true);
        self::assertSame(1, $json['success'] ?? null, 'Body: ' . $response->body());
        $this->assertDatabaseHas('ip_quote_amounts', ['quote_id' => $quoteId, 'quote_total' => '320.00']);
    }

    #[Test]
    public function it_refuses_an_item_id_of_another_quote_or_a_missing_one(): void
    {
        /* Arrange */
        [$quoteA, $itemA]     = $this->quoteWithOneItem();
        [$quoteB, $itemB]     = $this->quoteWithOneItem();
        $hijack               = $this->existingItem($quoteA, $itemB);
        $hijack['item_price'] = '1';

        /* Act & Assert */
        $foreign = json_decode($this->saveItems($quoteA, [$this->existingItem($quoteA, $itemA), $hijack])->body(), true);
        $missing = json_decode($this->saveItems($quoteA, [$this->existingItem($quoteA, 987654)])->body(), true);

        self::assertSame('One of the submitted items does not belong to this document.', $foreign['validation_errors']['item_id'] ?? null);
        self::assertArrayHasKey('item_id', $missing['validation_errors']);
        $this->assertDatabaseHas('ip_quote_items', ['item_id' => $itemB, 'quote_id' => $quoteB, 'item_price' => '150.00']);
    }

    #[Test]
    public function it_saves_a_new_item_to_the_posted_quote_even_if_the_item_names_another(): void
    {
        /* Arrange */
        [$quoteA] = $this->quoteWithOneItem();
        [$quoteB] = $this->quoteWithOneItem();

        /* Act */
        $response = $this->saveItems($quoteA, [$this->newItem($quoteB, 'Misdirected', '1', '10')]);

        /* Assert */
        self::assertSame(1, json_decode($response->body(), true)['success'] ?? null, 'Body: ' . $response->body());
        $this->assertDatabaseHas('ip_quote_items', ['quote_id' => $quoteA, 'item_name' => 'Misdirected']);
        $this->assertDatabaseMissing('ip_quote_items', ['quote_id' => $quoteB, 'item_name' => 'Misdirected']);
    }

    #[Test]
    #[DataProvider('singleDiscountFields')]
    public function it_names_exactly_one_field_and_message_for_each_bad_global_discount(array $extra, string $field, string $message): void
    {
        /* Arrange */
        [$quoteId, $itemId] = $this->quoteWithOneItem();

        /* Act */
        $response = $this->saveItems($quoteId, [$this->existingItem($quoteId, $itemId, '2', '150')], $extra);

        /* Assert */
        $json = json_decode($response->body(), true);
        self::assertSame(0, $json['success'] ?? null);
        self::assertSame([$field => $message], $json['validation_errors']);
        $this->assertDatabaseHas('ip_quote_amounts', ['quote_id' => $quoteId, 'quote_total' => '300.00']);
    }

    #[Test]
    public function it_uses_the_plain_negative_quantity_message_on_a_quote_and_accepts_zero(): void
    {
        /* Arrange */
        [$quoteId, $itemId] = $this->quoteWithOneItem();

        /* Act */
        $negative = json_decode($this->saveItems($quoteId, [$this->existingItem($quoteId, $itemId, '-2', '150')])->body(), true);
        $zero     = json_decode($this->saveItems($quoteId, [$this->existingItem($quoteId, $itemId, '0', '150')])->body(), true);

        /* Assert */
        self::assertSame(['item_quantity' => 'The item quantity cannot be negative.'], $negative['validation_errors']);
        self::assertSame(1, $zero['success'] ?? null, 'positive control: zero quantity is saved');
        $this->assertDatabaseHas('ip_quote_amounts', ['quote_id' => $quoteId, 'quote_total' => '0.00']);
    }

    #[Test]
    public function it_checks_a_negative_row_that_follows_a_valid_new_row(): void
    {
        /* Arrange */
        [$quoteId, $itemId] = $this->quoteWithOneItem();

        /* Act */
        $response = $this->saveItems($quoteId, [
            $this->existingItem($quoteId, $itemId, '2', '150'),
            $this->newItem($quoteId, 'Fine', '1', '10'),
            $this->newItem($quoteId, 'Bad', '1', '-10'),
        ]);

        /* Assert */
        $json = json_decode($response->body(), true);
        self::assertSame(['item_price' => 'The item price cannot be negative.'], $json['validation_errors'] ?? null);
        $this->assertDatabaseMissing('ip_quote_items', ['quote_id' => $quoteId, 'item_name' => 'Fine']);
        $this->assertDatabaseHas('ip_quote_amounts', ['quote_id' => $quoteId, 'quote_total' => '300.00']);
    }

    protected function seedQuote(array $overrides = []): int
    {
        $clientId = $overrides['client_id'] ?? $this->seedClient();
        unset($overrides['client_id']);

        return (int) $this->seedModel('Quote', array_merge(['client_id' => $clientId], $overrides))->quote_id;
    }

    // ---- helpers -------------------------------------------------------------------------------

    /**
     * @return array{0: int, 1: int} [quoteId, itemId] of a saved 300.00 quote (2 x 150)
     */
    private function quoteWithOneItem(): array
    {
        $quoteId = $this->databaseInsert('ip_quotes', [
            'user_id'            => 1, 'client_id' => $this->seedClient(), 'invoice_group_id' => 1, 'quote_status_id' => 2,
            'quote_number'       => 'QSIGN-' . bin2hex(random_bytes(3)), 'quote_url_key' => bin2hex(random_bytes(16)),
            'quote_date_created' => date('Y-m-d'), 'quote_date_modified' => date('Y-m-d H:i:s'),
            'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
        ]);
        $this->databaseInsert('ip_quote_amounts', [
            'quote_id' => $quoteId, 'quote_item_subtotal' => '0.00', 'quote_item_tax_total' => '0.00', 'quote_tax_total' => '0.00', 'quote_total' => '0.00',
        ]);
        $response = $this->saveItems($quoteId, [$this->newItem($quoteId, 'Widget', '2', '150')]);
        self::assertSame(1, json_decode($response->body(), true)['success'] ?? null, 'Setup save failed: ' . $response->body());

        return [$quoteId, (int) $this->databaseFetchOne('ip_quote_items', ['quote_id' => $quoteId, 'item_name' => 'Widget'])['item_id']];
    }

    /**
     * @param list<array<string, string>> $items
     * @param array<string, string>       $extra
     */
    private function saveItems(int $quoteId, array $items, array $extra = [])
    {
        return $this->ajax('POST', '/quotes/ajax/save', array_merge([
            'quote_id'           => (string) $quoteId, 'quote_status_id' => '2', 'quote_number' => 'QSIGN-' . $quoteId,
            'quote_date_created' => date('Y-m-d'), 'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
            'quote_password'     => '', 'notes' => '', 'quote_discount_percent' => '0', 'quote_discount_amount' => '0',
            'service_id'         => '', 'items' => json_encode($items),
        ], $extra));
    }

    /** @return array<string, string> */
    private function newItem(int $quoteId, string $name, string $quantity, string $price, string $discount = ''): array
    {
        return [
            'quote_id'   => (string) $quoteId, 'item_id' => '', 'item_name' => $name, 'item_description' => '', 'item_quantity' => $quantity,
            'item_price' => $price, 'item_discount_amount' => $discount, 'item_product_id' => '', 'item_product_unit_id' => '', 'item_tax_rate_id' => '0',
        ];
    }

    /** @return array<string, string> */
    private function existingItem(int $quoteId, int $itemId, string $quantity = '2', string $price = '150'): array
    {
        return ['item_id' => (string) $itemId] + $this->newItem($quoteId, 'Widget', $quantity, $price);
    }

    private function seedGuestQuote(int $clientId, int $status, string $number): int
    {
        $quoteId = $this->databaseInsert('ip_quotes', [
            'user_id'            => 1, 'client_id' => $clientId, 'invoice_group_id' => 1, 'quote_status_id' => $status,
            'quote_number'       => $number, 'quote_url_key' => bin2hex(random_bytes(16)),
            'quote_date_created' => date('Y-m-d'), 'quote_date_modified' => date('Y-m-d H:i:s'),
            'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
        ]);
        $this->databaseInsert('ip_quote_amounts', [
            'quote_id'        => $quoteId, 'quote_item_subtotal' => '10.00', 'quote_item_tax_total' => '0.00',
            'quote_tax_total' => '0.00', 'quote_total' => '10.00',
        ]);

        return $quoteId;
    }

    private function actingAsGuestFor(int $clientId): void
    {
        $guestId = $this->databaseInsert('ip_users', [
            'user_type'         => 2, 'user_name' => 'Portal Guest', 'user_email' => 'portal-guest@test.local',
            'user_password'     => password_hash('secret123', PASSWORD_DEFAULT), 'user_psalt' => bin2hex(random_bytes(8)),
            'user_language'     => 'system', 'user_active' => 1,
            'user_date_created' => date('Y-m-d H:i:s'), 'user_date_modified' => date('Y-m-d H:i:s'),
        ]);
        $this->databaseInsert('ip_user_clients', ['user_id' => $guestId, 'client_id' => $clientId]);
        $this->actingAs(['user_id' => $guestId, 'user_type' => 2, 'user_email' => 'portal-guest@test.local', 'user_name' => 'Portal Guest']);
        $this->enableCsrfProtection();
    }
}
