<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * The PDF routes and the upload route answered an unknown or malformed id with an error 500 (a null record used as
 * an object, or a type error on the controller argument). An error page can show file paths, and a route that
 * loads a record by "5-anything" acts on record 5. Both now answer 404 before anything is loaded or changed.
 */
#[Group('security')]
final class MalformedIdAnswers404Test extends AbstractTestCase
{
    private const DOCUMENT_ROUTES = [
        'invoice pdf' => '/invoices/generate_pdf/%s',
        'sumex pdf'   => '/invoices/generate_sumex_pdf/%s',
        'sumex copy'  => '/invoices/generate_sumex_copy/%s',
        'quote pdf'   => '/quotes/generate_pdf/%s',
    ];

    private const MALFORMED = [
        'unknown'       => '999999',
        'trailing text' => '999999-x(1)',
        'not a number'  => 'abc',
        'decimal'       => '1.5',
        'negative'      => '-1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function documentRequests(): array
    {
        $cases = [];

        foreach (self::DOCUMENT_ROUTES as $route => $uri) {
            foreach (self::MALFORMED as $shape => $id) {
                $cases[$route . ', ' . $shape] = [sprintf($uri, $id)];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedClientIds(): array
    {
        return ['trailing text' => ['7-x(1)'], 'not a number' => ['abc'], 'decimal' => ['1.5'], 'negative' => ['-1']];
    }

    #[Test]
    #[DataProvider('documentRequests')]
    public function it_answers_404_for_a_document_that_does_not_exist(string $uri): void
    {
        /* Act */
        $response = $this->get($uri);

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertResponseBodyNotContains($response, '%PDF');
        $this->assertResponseBodyNotContains($response, 'application/');
    }

    #[Test]
    public function it_does_not_act_on_the_invoice_whose_number_starts_a_malformed_id(): void
    {
        /* Arrange: MySQL compares "7-x(1)" with an integer column as 7, so without a check the route marks invoice 7
         * as sent and serves its PDF */
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'mark_invoices_sent_pdf', 'setting_value' => '1']);
        $invoiceId = $this->seedInvoice($this->seedClient(), ['invoice_status_id' => 1]);

        /* Act */
        $response = $this->get('/invoices/generate_pdf/' . $invoiceId . '-x(1)');

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertResponseBodyNotContains($response, '%PDF');
        $this->assertDatabaseHas('ip_invoices', ['invoice_id' => $invoiceId, 'invoice_status_id' => 1]);
    }

    #[Test]
    public function it_does_not_act_on_the_quote_whose_number_starts_a_malformed_id(): void
    {
        /* Arrange */
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'mark_quotes_sent_pdf', 'setting_value' => '1']);
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'quotes_expire_after', 'setting_value' => '15']);
        $quoteId = $this->databaseInsert('ip_quotes', [
            'user_id'            => 1, 'client_id' => $this->seedClient(), 'invoice_group_id' => 1, 'quote_status_id' => 1,
            'quote_number'       => 'QUO-404', 'quote_url_key' => bin2hex(random_bytes(16)),
            'quote_date_created' => date('Y-m-d'), 'quote_date_modified' => date('Y-m-d H:i:s'),
            'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
        ]);
        $this->databaseInsert('ip_quote_amounts', [
            'quote_id'        => $quoteId, 'quote_item_subtotal' => '0.00', 'quote_item_tax_total' => '0.00',
            'quote_tax_total' => '0.00', 'quote_total' => '0.00',
        ]);

        /* Act */
        $response = $this->get('/quotes/generate_pdf/' . $quoteId . '-x(1)');

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertResponseBodyNotContains($response, '%PDF');
        $this->assertDatabaseHas('ip_quotes', ['quote_id' => $quoteId, 'quote_status_id' => 1]);
    }

    #[Test]
    public function it_still_serves_the_pdf_of_an_invoice_that_exists(): void
    {
        /* Arrange */
        $invoiceId = $this->seedInvoice($this->seedClient());

        /* Act */
        $response = $this->get('/invoices/generate_pdf/' . $invoiceId);

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertStringStartsWith('%PDF', $response->body());
    }

    #[Test]
    #[DataProvider('malformedClientIds')]
    public function it_answers_404_for_an_upload_to_a_malformed_client_id(string $clientId): void
    {
        /* Arrange */
        $before = $this->databaseCount('ip_uploads');

        /* Act */
        $response = $this->post('/upload/upload_file/' . $clientId . '/somekey');

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertSame($before, $this->databaseCount('ip_uploads'));
    }

    #[Test]
    public function it_still_reaches_the_upload_for_an_integer_client_id(): void
    {
        /* Act: no file is sent, so the upload itself answers that one is missing */
        $response = $this->post('/upload/upload_file/' . $this->seedClient() . '/somekey');

        /* Assert */
        $this->assertResponseStatusCode($response, 400);
    }
}
