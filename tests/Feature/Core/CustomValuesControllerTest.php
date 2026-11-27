<?php

namespace Tests\Feature\Core;

use Custom_Values;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;
use Tests\Concerns\SwitchesSqlMode;

/**
 * Custom_Values controller — application/modules/custom_values/controllers/Custom_values.php.
 *
 * A custom value is one option of a choice-type custom field. Required field
 * (Mdl_Custom_Values::validation_rules): custom_values_value. Routes:
 *   list   GET  /custom_values/field/{field_id}
 *   create POST /custom_values/create/{field_id}   -> /custom_values/field/{field_id}
 *   edit   POST /custom_values/edit/{id}           -> /custom_values/field/{field_id}
 *   delete POST /custom_values/delete/{id}         -> /custom_values/field/{field_id}
 * Absorbs Issue1694CustomValuesDeleteCsrfTest.
 */
#[Group('custom_values')]
#[CoversClass(Custom_Values::class)]
class CustomValuesControllerTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;
    use SwitchesSqlMode;

    private ?string $hiddenTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    protected function tearDown(): void
    {
        $this->showTable();
        $this->restoreSqlMode();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    #[Test]
    public function it_lists_every_value_for_a_field(): void
    {
        /* Arrange */
        $fieldId = $this->seedChoiceField();
        $this->seedValue($fieldId, 'Enterprise');
        $this->seedValue($fieldId, 'Small Business');

        /* Act */
        $response = $this->get('/custom_values/field/' . $fieldId);

        /* Assert */
        $this->assertResponseBodyContains($response, 'Enterprise');
        $this->assertResponseBodyContains($response, 'Small Business');
    }

    // -------------------------------------------------------------------------
    // Create — happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_a_value_for_a_field(): void
    {
        /* Arrange */
        $fieldId = $this->seedChoiceField();

        /* Act */
        $response = $this->post('/custom_values/create/' . $fieldId, [
            'custom_field_id'     => (string) $fieldId,
            'custom_values_value' => 'Non Profit',
            'btn_submit'          => '1',
        ]);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'A successful create redirects back to the field value list.');
        $this->assertDatabaseHas('ip_custom_values', ['custom_values_field' => $fieldId, 'custom_values_value' => 'Non Profit']);
    }

    // -------------------------------------------------------------------------
    // Create — validation (one omitted required field per test)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_fails_to_create_a_value_without_custom_values_value(): void
    {
        /* Arrange */
        $fieldId = $this->seedChoiceField();

        /* Act */
        $response = $this->post('/custom_values/create/' . $fieldId, [
            'custom_field_id'     => (string) $fieldId,
            'custom_values_value' => '',
            'btn_submit'          => '1',
        ]);

        /* Assert */
        self::assertFalse($response->isRedirect(), 'Invalid create must re-render the form, not redirect.');
        $this->assertDatabaseCount('ip_custom_values', 0);
    }

    // -------------------------------------------------------------------------
    // Update — happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_updates_a_value(): void
    {
        /* Arrange */
        $fieldId = $this->seedChoiceField();
        $id      = $this->seedValue($fieldId, 'Original Segment');

        /* Act */
        $response = $this->post('/custom_values/edit/' . $id, [
            'custom_field_id'     => (string) $fieldId,
            'custom_values_value' => 'Renamed Segment',
            'btn_submit'          => '1',
        ]);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'A successful update redirects back to the field value list.');
        $this->assertDatabaseHas('ip_custom_values', ['custom_values_id' => $id, 'custom_values_value' => 'Renamed Segment']);
        $this->assertDatabaseMissing('ip_custom_values', ['custom_values_value' => 'Original Segment']);
    }

    // -------------------------------------------------------------------------
    // Update — validation (one omitted required field per test)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_fails_to_update_a_value_without_custom_values_value(): void
    {
        /* Arrange */
        $fieldId = $this->seedChoiceField();
        $id      = $this->seedValue($fieldId, 'Keep This Segment');

        /* Act */
        $response = $this->post('/custom_values/edit/' . $id, [
            'custom_field_id'     => (string) $fieldId,
            'custom_values_value' => '',
            'btn_submit'          => '1',
        ]);

        /* Assert */
        self::assertFalse($response->isRedirect(), 'Invalid update must re-render the form, not redirect.');
        $this->assertDatabaseHas('ip_custom_values', ['custom_values_id' => $id, 'custom_values_value' => 'Keep This Segment']);
    }

    // -------------------------------------------------------------------------
    // Delete — happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_deletes_a_value(): void
    {
        /* Arrange */
        $fieldId = $this->seedChoiceField();
        $id      = $this->seedValue($fieldId, 'Deletable Segment');
        $keep    = $this->seedValue($fieldId, 'Kept Segment');

        /* Act */
        $response = $this->post('/custom_values/delete/' . $id, []);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'A successful delete redirects back to the field value list.');
        $this->assertDatabaseMissing('ip_custom_values', ['custom_values_id' => $id]);
        $this->assertDatabaseHas('ip_custom_values', ['custom_values_id' => $keep]);
    }

    // -------------------------------------------------------------------------
    // Delete — CSRF regression (#1694)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_still_deletes_a_value_when_csrf_protection_is_on_and_the_token_is_valid(): void
    {
        /* Arrange */
        $this->enableCsrfProtection();
        $fieldId = $this->seedChoiceField();
        $id      = $this->seedValue($fieldId, 'CSRF Segment');

        /* Act */
        $response = $this->postWithValidCsrfToken('/custom_values/delete/' . $id);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'A valid-token delete redirects.');
        $this->assertDatabaseMissing('ip_custom_values', ['custom_values_id' => $id]);
    }

    #[Test]
    public function it_does_not_delete_a_value_when_the_csrf_token_is_missing(): void
    {
        /* Arrange */
        $this->enableCsrfProtection();
        $fieldId = $this->seedChoiceField();
        $id      = $this->seedValue($fieldId, 'CSRF Segment Kept');

        /* Act */
        $response = $this->postWithoutCsrfToken('/custom_values/delete/' . $id);

        /* Assert */
        self::assertFalse($response->isRedirect(), 'A token-less delete must not reach the controller.');
        $this->assertDatabaseHas('ip_custom_values', ['custom_values_id' => $id, 'custom_values_value' => 'CSRF Segment Kept']);
    }

    #[Test]
    public function it_does_not_delete_a_value_on_a_plain_get_request(): void
    {
        /* Arrange */
        // Base_Controller::__construct() 404s any GET whose URL contains
        // "delete" before the controller (and ensure_valid_post_request())
        // ever runs — a distinct, earlier guard than the CSRF-token checks
        // above, and one no test here currently exercises.
        $fieldId = $this->seedChoiceField();
        $id      = $this->seedValue($fieldId, 'GET Segment Kept');

        /* Act */
        $response = $this->get('/custom_values/delete/' . $id);

        /* Assert */
        self::assertSame(404, $response->statusCode(), 'The global GET-to-delete gate in Base_Controller must reject this before it is acted on.');
        $this->assertDatabaseHas('ip_custom_values', ['custom_values_id' => $id, 'custom_values_value' => 'GET Segment Kept']);
    }

    // -------------------------------------------------------------------------
    // Guest access — always last
    // -------------------------------------------------------------------------

    #[Test]
    public function it_redirects_a_guest_to_login_and_leaks_no_value(): void
    {
        /* Arrange */
        $fieldId = $this->seedChoiceField();
        $this->seedValue($fieldId, 'Secret Segment');
        $this->actingAsGuest();

        /* Act */
        $response = $this->get('/custom_values/field/' . $fieldId);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'Unauthenticated request must redirect to login.');
        $this->assertResponseBodyNotContains($response, 'Secret Segment');
    }

    // -------------------------------------------------------------------------
    // Index: one row per field with its number of values (MySQL 8, ONLY_FULL_GROUP_BY)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_lists_each_field_once_with_the_number_of_its_values_under_only_full_group_by(): void
    {
        /* Arrange: two choice fields, 3 and 1 values, plus a field without values (not listed: inner join) */
        $this->switchSqlMode(self::MYSQL8_DEFAULT_SQL_MODE);
        self::assertStringContainsString('ONLY_FULL_GROUP_BY', $this->currentGlobalSqlMode());
        $big   = $this->seedLabelledField('Segment Alpha', 'ip_client_custom');
        $small = $this->seedLabelledField('Region Beta', 'ip_invoice_custom');
        $this->seedLabelledField('Unused Gamma', 'ip_quote_custom');
        foreach (['one', 'two', 'three'] as $value) {
            $this->seedValue($big, $value);
        }
        $this->seedValue($small, 'only');

        /* Act */
        $response = $this->get('/custom_values');

        /* Assert */
        $this->assertResponseOk($response);
        $body = $response->body();
        self::assertSame(1, substr_count($body, 'Segment Alpha'), 'a field is listed once, not once per value');
        self::assertSame(1, substr_count($body, 'Region Beta'));
        self::assertStringNotContainsString('Unused Gamma', $body);
        self::assertMatchesRegularExpression('/Segment Alpha.*?<td>3<\/td>/s', $body, 'the field with three values shows 3');
        self::assertMatchesRegularExpression('/Region Beta.*?<td>1<\/td>/s', $body, 'the field with one value shows 1');
    }

    #[Test]
    public function it_lists_the_fields_ordered_by_table_then_label_under_only_full_group_by(): void
    {
        /* Arrange: inserted out of order */
        $this->switchSqlMode(self::MYSQL8_DEFAULT_SQL_MODE);
        $this->seedValue($this->seedLabelledField('Zulu label', 'ip_client_custom'), 'v');
        $this->seedValue($this->seedLabelledField('Alpha label', 'ip_client_custom'), 'v');

        /* Act */
        $response = $this->get('/custom_values');

        /* Assert */
        $this->assertResponseOk($response);
        $body = $response->body();
        self::assertLessThan(strpos($body, 'Zulu label'), strpos($body, 'Alpha label'));
    }

    #[Test]
    public function it_answers_200_under_only_full_group_by_when_there_are_no_custom_fields_at_all(): void
    {
        /* Arrange: the reporter's second install: 0 fields, 0 values */
        $this->switchSqlMode(self::MYSQL8_DEFAULT_SQL_MODE);
        $this->assertDatabaseCount('ip_custom_fields', 0);

        /* Act */
        $response = $this->get('/custom_values');

        /* Assert: a rejected query used to end in a blank HTTP 500 */
        $this->assertResponseOk($response);
        self::assertNotSame('', $response->body());
    }

    // -------------------------------------------------------------------------
    // A query the database rejects stops the request; it is not read as "no rows"
    // -------------------------------------------------------------------------

    #[Test]
    public function it_answers_the_index_with_a_500_error_page_when_its_query_is_rejected(): void
    {
        /* Arrange: the list query joins ip_custom_values, so hiding it makes that query fail */
        $this->hideTable('ip_custom_values');

        /* Act */
        $response = $this->get('/custom_values');

        /* Assert: a real error page with the message, not a blank body and not an empty list */
        $this->assertResponseStatusCode($response, 500);
        $this->assertResponseBodyContains($response, 'A database error occurred');
    }

    #[Test]
    public function it_does_not_delete_a_value_whose_usage_could_not_be_checked(): void
    {
        /* Arrange: a value, then a database that rejects the lookup used() makes */
        $valueId = $this->seedValue($this->seedLabelledField('Kept', 'ip_client_custom'), 'maybe in use');
        $this->hideTable('ip_custom_fields');

        /* Act */
        $response = $this->post('/custom_values/delete/' . $valueId, []);
        $this->showTable();

        /* Assert: the request stopped with a 500 and the value survived */
        $this->assertResponseStatusCode($response, 500);
        $this->assertDatabaseHas('ip_custom_values', ['custom_values_id' => $valueId]);
    }

    #[Test]
    public function it_still_deletes_an_unused_value_in_the_production_environment_when_the_database_is_healthy(): void
    {
        /* Arrange: the positive control for the test above, in the same environment */
        $valueId = $this->seedValue($this->seedLabelledField('Control', 'ip_client_custom'), 'unused');
        $this->withServer(['CI_ENV' => 'production']);

        /* Act */
        $response = $this->post('/custom_values/delete/' . $valueId, []);

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseMissing('ip_custom_values', ['custom_values_id' => $valueId]);
    }

    protected function seedLabelledField(string $label, string $table): int
    {
        return $this->databaseInsert('ip_custom_fields', [
            'custom_field_table'    => $table,
            'custom_field_label'    => $label,
            'custom_field_type'     => 'SINGLE-CHOICE',
            'custom_field_order'    => 1,
            'custom_field_location' => 0,
        ]);
    }

    protected function seedChoiceField(): int
    {
        return $this->databaseInsert('ip_custom_fields', [
            'custom_field_table' => 'ip_client_custom',
            'custom_field_label' => 'Client Segment ' . bin2hex(random_bytes(3)),
            'custom_field_type'  => 'MULTIPLE-CHOICE',
        ]);
    }

    protected function seedValue(int $fieldId, string $value): int
    {
        return $this->databaseInsert('ip_custom_values', [
            'custom_values_field' => $fieldId,
            'custom_values_value' => $value,
        ]);
    }

    /**
     * Renames the table away for the request, in the production environment: there db_debug is off and
     * CodeIgniter hands the model false for a rejected query; in the testing environment it throws first.
     */
    private function hideTable(string $table): void
    {
        $this->withServer(['CI_ENV' => 'production']);
        $this->databaseRunScript("RENAME TABLE {$table} TO {$table}_hidden");
        $this->hiddenTable = $table;
    }

    /**
     * Puts a hidden table back; a no-op when none is hidden.
     */
    private function showTable(): void
    {
        if ($this->hiddenTable === null) {
            return;
        }

        $this->databaseRunScript("RENAME TABLE {$this->hiddenTable}_hidden TO {$this->hiddenTable}");
        $this->hiddenTable = null;
    }
}
