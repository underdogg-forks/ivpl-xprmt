<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\UsesCodeIgniterModels;

/**
 * Direct-model backstop for the validation-rule injection in invoices/quotes save (CWE-94, reported by Rick Weng /.
 *
 * @rickwengdev): Mdl_Invoices::validation_rules_save_invoice() and Mdl_Quotes::validation_rules_save_quote()
 * concatenate $this->id into a CodeIgniter validation-rule string. The controllers now cast the posted id to int
 * before calling set_id(), but set_id() itself stores whatever it is given (MY_Model::set_id()), so this tests the
 * model directly with the same malicious string the controller fix now keeps out, in case a future caller of
 * set_id() forgets that cast.
 */
#[Group('security')]
final class ValidationRuleBuildingTest extends AbstractTestCase
{
    use UsesCodeIgniterModels;

    protected function tearDown(): void
    {
        $this->tearDownCodeIgniter();
        parent::tearDown();
    }

    #[Test]
    public function it_drops_a_non_integer_id_from_the_invoice_number_uniqueness_rule(): void
    {
        /* Arrange */
        $model = $this->model('invoices/models/Mdl_invoices');
        $model->set_id('1]|strtoupper|trim[');

        /* Act */
        $rules = $model->validation_rules_save_invoice()['invoice_number']['rules'];

        /* Assert: no part of the attacker string reaches the rule; the clause that adds the uniqueness scope is omitted entirely */
        self::assertSame('is_unique[ip_invoices.invoice_number]', $rules);
    }

    #[Test]
    public function it_keeps_the_uniqueness_scope_for_a_plain_integer_id(): void
    {
        /* Arrange: positive control */
        $model = $this->model('invoices/models/Mdl_invoices');
        $model->set_id(42);

        /* Act */
        $rules = $model->validation_rules_save_invoice()['invoice_number']['rules'];

        /* Assert */
        self::assertSame('is_unique[ip_invoices.invoice_number.invoice_id.42]', $rules);
    }

    #[Test]
    public function it_drops_a_non_integer_id_from_the_quote_number_uniqueness_rule(): void
    {
        /* Arrange */
        $model = $this->model('quotes/models/Mdl_quotes');
        $model->set_id('1]|system|trim[');

        /* Act */
        $rules = $model->validation_rules_save_quote()['quote_number']['rules'];

        /* Assert */
        self::assertSame('regex_match[/^[^\x00-\x1F\x7F<>"\']+$/]|is_unique[ip_quotes.quote_number]', $rules);
    }

    #[Test]
    public function it_keeps_the_uniqueness_scope_for_a_plain_integer_quote_id(): void
    {
        /* Arrange: positive control */
        $model = $this->model('quotes/models/Mdl_quotes');
        $model->set_id(7);

        /* Act */
        $rules = $model->validation_rules_save_quote()['quote_number']['rules'];

        /* Assert */
        self::assertSame('regex_match[/^[^\x00-\x1F\x7F<>"\']+$/]|is_unique[ip_quotes.quote_number.quote_id.7]', $rules);
    }

    private function model(string $relative): object
    {
        require_once APPPATH . 'helpers/trans_helper.php';
        require_once APPPATH . 'core/Password_Encryption_Trait.php';
        $ci       = $this->bootCodeIgniter();
        $ci->lang = new class () {
            public function line(string $key): string
            {
                return $key;
            }
        };

        return $this->ciModel($relative);
    }
}
