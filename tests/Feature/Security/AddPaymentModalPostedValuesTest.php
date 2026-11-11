<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * The add-payment modal printed the posted `payment_cf_exist` into `value="..."` after xss_clean() only, and
 * xss_clean() does not encode a double quote. The field has two meanings, "yes" and anything else, so the view
 * prints one of two fixed words.
 */
#[Group('security')]
final class AddPaymentModalPostedValuesTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function postedValues(): array
    {
        return [
            'yes'                    => ['yes', 'yes'],
            'no'                     => ['no', 'no'],
            'missing'                => [null, 'no'],
            'attribute break-out'    => ['yes" data-ipauditmarker="1', 'no'],
            'tag break-out'          => ['"><b id=ipauditmarker>', 'no'],
            'upper case is not yes'  => ['YES', 'no'],
            'an array, not a string' => [['yes'], 'no'],
        ];
    }

    #[Test]
    #[DataProvider('postedValues')]
    public function it_prints_only_yes_or_no_for_the_custom_field_flag(mixed $posted, string $printed): void
    {
        /* Arrange */
        $invoiceId = $this->seedInvoice($this->seedClient());
        $data      = ['invoice_id' => $invoiceId, 'invoice_balance' => '10.00'];

        if ($posted !== null) {
            $data['payment_cf_exist'] = $posted;
        }

        /* Act */
        $response = $this->ajax('POST', '/payments/ajax/modal_add_payment', $data);

        /* Assert: the modal is still served, with the flag reduced to one of its two values */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseHasNoPhpErrors($response);
        $this->assertResponseBodyContains($response, 'id="payment_cf_exist" value="' . $printed . '"');
        $this->assertResponseBodyNotContains($response, 'ipauditmarker');
        $this->assertResponseBodyContains($response, 'name="invoice_id" id="invoice_id" value="' . $invoiceId . '"');
    }
}
