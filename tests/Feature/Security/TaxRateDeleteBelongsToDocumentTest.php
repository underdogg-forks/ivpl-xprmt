<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * GHSA-97wx-pmp5-gj77: invoices/delete_invoice_tax/<invoice>/<tax row> and quotes/delete_quote_tax/<quote>/<tax row>
 * deleted the tax row by its bare id, whichever document it belonged to, and then recalculated the document in the
 * URL. The row must belong to the document named in the URL, as the item delete endpoints already require.
 */
#[Group('security')]
final class TaxRateDeleteBelongsToDocumentTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function documents(): array
    {
        return [
            'invoice' => ['invoice'],
            'quote'   => ['quote'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedRowIds(): array
    {
        $cases = [];

        foreach (['invoice', 'quote'] as $type) {
            $cases[$type . ': trailing letters'] = [$type, '%dabc'];
            $cases[$type . ': decimal']          = [$type, '%d.0'];
            $cases[$type . ': negative']         = [$type, '-%d'];
            $cases[$type . ': hex']              = [$type, '0x%d'];
            $cases[$type . ': unknown row']      = [$type, '%d999999'];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('documents')]
    public function it_refuses_to_delete_a_tax_row_of_another_document(string $type): void
    {
        /* Arrange */
        $own          = $this->seedDocument($type);
        $other        = $this->seedDocument($type);
        $ownRow       = $this->seedTaxRow($type, $own);
        $foreignRow   = $this->seedTaxRow($type, $other);
        $ownAmounts   = $this->amountsOf($type, $own);
        $otherAmounts = $this->amountsOf($type, $other);

        /* Act */
        $response = $this->post($this->deleteUri($type, $own, $foreignRow));

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        self::assertFalse($response->isRedirect(), 'A refused delete must not look like a successful one.');
        $this->assertDatabaseHas($this->table($type), [$this->rowKey($type) => $foreignRow, $type . '_id' => $other]);
        $this->assertDatabaseHas($this->table($type), [$this->rowKey($type) => $ownRow, $type . '_id' => $own]);
        self::assertSame($ownAmounts, $this->amountsOf($type, $own), 'The document in the URL must not be recalculated.');
        self::assertSame($otherAmounts, $this->amountsOf($type, $other), 'The other document must stay untouched.');
    }

    #[Test]
    #[DataProvider('documents')]
    public function it_deletes_only_the_named_tax_row_of_its_own_document(string $type): void
    {
        /* Arrange */
        $own        = $this->seedDocument($type);
        $other      = $this->seedDocument($type);
        $ownRow     = $this->seedTaxRow($type, $own);
        $siblingRow = $this->seedTaxRow($type, $own);
        $foreignRow = $this->seedTaxRow($type, $other);

        /* Act */
        $response = $this->post($this->deleteUri($type, $own, $ownRow));

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, $type . 's/view/' . $own);
        $this->assertDatabaseMissing($this->table($type), [$this->rowKey($type) => $ownRow]);
        $this->assertDatabaseHas($this->table($type), [$this->rowKey($type) => $siblingRow]);
        $this->assertDatabaseHas($this->table($type), [$this->rowKey($type) => $foreignRow]);
    }

    #[Test]
    #[DataProvider('malformedRowIds')]
    public function it_refuses_a_tax_row_id_that_is_not_exactly_a_row_of_the_document(string $type, string $pattern): void
    {
        /* Arrange: MySQL would read "5abc" as 5, so the id is only accepted as a plain integer */
        $own    = $this->seedDocument($type);
        $ownRow = $this->seedTaxRow($type, $own);

        /* Act */
        $response = $this->post($this->deleteUri($type, $own, sprintf($pattern, $ownRow)));

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertDatabaseHas($this->table($type), [$this->rowKey($type) => $ownRow]);
    }

    #[Test]
    #[DataProvider('documents')]
    public function it_refuses_a_tax_row_when_the_document_in_the_url_does_not_exist(string $type): void
    {
        /* Arrange */
        $other      = $this->seedDocument($type);
        $foreignRow = $this->seedTaxRow($type, $other);

        /* Act */
        $response = $this->post($this->deleteUri($type, $other + 999999, $foreignRow));

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertDatabaseHas($this->table($type), [$this->rowKey($type) => $foreignRow]);
    }

    #[Test]
    #[DataProvider('documents')]
    public function it_refuses_a_document_id_that_only_starts_with_the_owning_id(string $type): void
    {
        /* Arrange */
        $own    = $this->seedDocument($type);
        $ownRow = $this->seedTaxRow($type, $own);

        /* Act */
        $response = $this->post($this->deleteUri($type, $own . 'abc', $ownRow));

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertDatabaseHas($this->table($type), [$this->rowKey($type) => $ownRow]);
    }

    private function seedDocument(string $type): int
    {
        if ($type === 'invoice') {
            return $this->seedInvoice($this->seedClient(), [], ['invoice_tax_total' => '7.00', 'invoice_total' => '107.00', 'invoice_balance' => '107.00']);
        }

        $quoteId = (int) $this->seedModel('Quote', ['client_id' => $this->seedClient()])->quote_id;

        $this->databaseDelete('ip_quote_amounts', ['quote_id' => $quoteId]);
        $this->databaseInsert('ip_quote_amounts', [
            'quote_id'             => $quoteId,
            'quote_item_subtotal'  => '100.00',
            'quote_item_tax_total' => '0.00',
            'quote_tax_total'      => '7.00',
            'quote_total'          => '107.00',
        ]);

        return $quoteId;
    }

    private function seedTaxRow(string $type, int $documentId): int
    {
        return $this->databaseInsert($this->table($type), [
            $type . '_id'              => $documentId,
            'tax_rate_id'              => 1,
            'include_item_tax'         => 0,
            $type . '_tax_rate_amount' => '7.00',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function amountsOf(string $type, int $documentId): ?array
    {
        return $this->databaseFetchOne('ip_' . $type . '_amounts', [$type . '_id' => $documentId]);
    }

    private function deleteUri(string $type, int|string $documentId, int|string $rowId): string
    {
        return '/' . $type . 's/delete_' . $type . '_tax/' . $documentId . '/' . $rowId;
    }

    private function table(string $type): string
    {
        return 'ip_' . $type . '_tax_rates';
    }

    private function rowKey(string $type): string
    {
        return $type . '_tax_rate_id';
    }
}
