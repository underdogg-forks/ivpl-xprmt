<?php

namespace Tests\Feature\Core;

use Custom_Values;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Throwable;

/**
 * Settings -> Custom Values (GET /custom_values) and its filter box (POST /filter/ajax/filter_custom_values)
 * list one row per custom field with its number of values. The query used to select ip_custom_values.* and
 * order by ip_custom_values.custom_values_value while grouping by the field, which MySQL 8's default
 * sql_mode (ONLY_FULL_GROUP_BY) rejects with error 1055: a blank HTTP 500 whatever the data. The suite
 * normally runs with sql_mode '' (tests/Support/sandbox-mariadb.sh, the phpunit workflow), so these tests
 * switch the server to MySQL 8's default mode for the request and restore it afterwards.
 */
#[Group('custom_values')]
#[CoversClass(Custom_Values::class)]
class CustomValuesGroupedQueryTest extends AbstractTestCase
{
    private const MYSQL8_DEFAULT_SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private ?string $originalSqlMode = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();

        /* The request runs in its own PHP process with its own database connection, so a session mode set on this
           test's connection would never reach it: the server's global mode is the only switch available, and it
           applies to connections opened after it. Restored in tearDown() and, should the process die first, at shutdown. */
        $this->originalSqlMode = (string) $this->databaseSelect('SELECT @@GLOBAL.sql_mode AS mode')[0]['mode'];
        $original              = $this->originalSqlMode;
        $this->databaseRunScript("SET GLOBAL sql_mode = '" . self::MYSQL8_DEFAULT_SQL_MODE . "'");
        register_shutdown_function(function () use ($original): void {
            try {
                $this->databaseRunScript("SET GLOBAL sql_mode = '" . addslashes($original) . "'");
            } catch (Throwable) {
                // the connection is gone; nothing left to restore through
            }
        });
    }

    protected function tearDown(): void
    {
        if ($this->originalSqlMode !== null) {
            $this->databaseRunScript("SET GLOBAL sql_mode = '" . addslashes($this->originalSqlMode) . "'");
        }

        parent::tearDown();
    }

    #[Test]
    public function it_runs_these_tests_under_only_full_group_by(): void
    {
        /* Assert: the premise of every test below, read back from the server the app connects to */
        $mode = (string) $this->databaseSelect('SELECT @@GLOBAL.sql_mode AS mode')[0]['mode'];

        self::assertStringContainsString('ONLY_FULL_GROUP_BY', $mode);
    }

    #[Test]
    public function it_lists_each_field_once_with_the_number_of_its_values(): void
    {
        /* Arrange: two choice fields, 3 and 1 values, plus a field without values (not listed: inner join) */
        $big   = $this->seedField('Segment Alpha', 'ip_client_custom');
        $small = $this->seedField('Region Beta', 'ip_invoice_custom');
        $empty = $this->seedField('Unused Gamma', 'ip_quote_custom');
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
        self::assertNotNull($empty);
    }

    #[Test]
    public function it_lists_the_fields_ordered_by_table_then_label(): void
    {
        /* Arrange: inserted out of order */
        $z = $this->seedField('Zulu label', 'ip_client_custom');
        $a = $this->seedField('Alpha label', 'ip_client_custom');
        $this->seedValue($z, 'v');
        $this->seedValue($a, 'v');

        /* Act */
        $body = $this->get('/custom_values')->body();

        /* Assert */
        self::assertLessThan(strpos($body, 'Zulu label'), strpos($body, 'Alpha label'));
    }

    #[Test]
    public function it_answers_200_when_there_are_no_custom_fields_at_all(): void
    {
        /* Arrange: the reporter's second install: 0 fields, 0 values */
        $this->assertDatabaseCount('ip_custom_fields', 0);

        /* Act */
        $response = $this->get('/custom_values');

        /* Assert: a rejected query used to end in a blank HTTP 500 */
        $this->assertResponseOk($response);
        self::assertNotSame('', $response->body());
    }

    #[Test]
    public function it_filters_the_list_by_value_text_under_only_full_group_by(): void
    {
        /* Arrange */
        $match = $this->seedField('Matching field', 'ip_client_custom');
        $other = $this->seedField('Other field', 'ip_client_custom');
        $this->seedValue($match, 'NeedleValue');
        $this->seedValue($match, 'second');
        $this->seedValue($other, 'Unrelated');

        /* Act */
        $response = $this->ajax('POST', '/filter/ajax/filter_custom_values', ['filter_query' => 'needlevalue']);

        /* Assert: the field owning the matching value is listed once, with its full count; the other is not */
        $this->assertResponseOk($response);
        $body = $response->body();
        self::assertSame(1, substr_count($body, 'Matching field'));
        self::assertStringNotContainsString('Other field', $body);
        self::assertMatchesRegularExpression('/Matching field.*?<td>1<\/td>/s', $body, 'only the matching value is counted');
    }

    #[Test]
    public function it_lists_every_field_when_the_filter_box_is_empty(): void
    {
        /* Arrange */
        foreach (['First field', 'Second field'] as $label) {
            $this->seedValue($this->seedField($label, 'ip_client_custom'), 'v');
        }

        /* Act */
        $response = $this->ajax('POST', '/filter/ajax/filter_custom_values', ['filter_query' => '']);

        /* Assert */
        $this->assertResponseOk($response);
        $this->assertResponseBodyContains($response, 'First field');
        $this->assertResponseBodyContains($response, 'Second field');
    }

    /**
     * Inserts a choice-type custom field and returns its id.
     */
    private function seedField(string $label, string $table): int
    {
        return $this->databaseInsert('ip_custom_fields', [
            'custom_field_table'    => $table,
            'custom_field_label'    => $label,
            'custom_field_type'     => 'SINGLE-CHOICE',
            'custom_field_order'    => 1,
            'custom_field_location' => 0,
        ]);
    }

    /**
     * Inserts one value for the field and returns its id.
     */
    private function seedValue(int $fieldId, string $value): int
    {
        return $this->databaseInsert('ip_custom_values', ['custom_values_field' => $fieldId, 'custom_values_value' => $value]);
    }
}
