<?php

namespace Tests\Feature\Core\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * GHSA-2849-wpq6-pq9g (#1838): values echoed into a JavaScript string were written with a plain echo inside quotes, so
 * a quote or "</script>" in the value ended the string or the script block. The views now write them with json_encode
 * and the hex flags, which turns < > & ' " into \uXXXX escapes and renders a double-quoted literal.
 *
 * The user id and the invoice key reach the view only as digits or hex through the route, so those two are asserted by
 * the shape of the literal in GatewayHelperRoutingTest and UserClientsIdorTest. This class covers the two echoes that
 * come from a stored setting, which an attacker with settings access controls completely.
 */
#[Group('security')]
final class JavaScriptStringEscapingTest extends AbstractTestCase
{
    private const BREAKOUT = '1"</script><script>alert(1)//\'';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    #[Test]
    public function it_escapes_the_stored_version_in_the_settings_updates_script(): void
    {
        /* Arrange */
        $this->databaseInsert('ip_versions', ['version_date_applied' => time(), 'version_file' => '999_' . self::BREAKOUT, 'version_sql_errors' => 0]);

        /* Act */
        $response = $this->get('/settings');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, 'var ip_version = "1\u0022\u003C\/script\u003E\u003Cscript\u003Ealert(1)\/\/\u0027";');
    }

    #[Test]
    public function it_writes_the_default_tax_rate_as_a_json_string_in_the_product_lookup_modal(): void
    {
        /* Arrange */
        $this->actingAsAdmin();

        /* Act */
        $response = $this->ajax('GET', '/products/ajax/modal_product_lookups');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        self::assertMatchesRegularExpression('/items\[key\]\.tax_rate_id = "[^"\']*";/', $response->body());
        $this->assertResponseBodyNotContains($response, "tax_rate_id = '");
    }

    #[Test]
    public function it_writes_the_default_tax_rate_as_a_json_string_in_the_task_lookup_modal(): void
    {
        /* Arrange */
        $this->actingAsAdmin();

        /* Act */
        $response = $this->ajax('GET', '/tasks/ajax/modal_task_lookups');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        self::assertMatchesRegularExpression('/items\[key\]\.tax_rate_id = "[^"\']*";/', $response->body());
        $this->assertResponseBodyNotContains($response, "tax_rate_id = '");
    }
}
