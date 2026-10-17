<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * InvoicePlane/InvoicePlane#1780: render_template_view()'s custom-templates-folder branch
 * (application/helpers/template_helper.php) has two outcomes depending on $return -- return
 * the rendered string (the PDF callers in pdf_helper.php / Sumex.php, which embed it into a
 * PDF rather than emit it directly) or append it to $CI->output (the guest-view public-template
 * caller). GuestViewCustomTemplateTest exercises only the $return = false path through the full
 * HTTP stack; no test reaches the $return = true path, so a mutation that forces that branch
 * survived undetected. This runs the real helper directly, in a clean process, against both
 * $return values.
 */
final class RenderTemplateViewRealHelperTest extends TestCase
{
    #[Test]
    public function it_returns_the_rendered_string_instead_of_appending_it_when_return_is_true(): void
    {
        /* Act */
        [$returned, $appended] = $this->runRenderTemplateView(true);

        /* Assert: the output comes back as the function's return value, and never reaches
         * $CI->output->append_output() -- the mutant (if ($return) -> if (false)) would route
         * it through append_output and return null instead. */
        self::assertSame('MARKER-return-true', $returned);
        self::assertSame('NONE', $appended, '$CI->output->append_output() must not be called when $return is true.');
    }

    #[Test]
    public function it_appends_the_rendered_string_to_ci_output_instead_of_returning_it_when_return_is_false(): void
    {
        /* Act */
        [$returned, $appended] = $this->runRenderTemplateView(false);

        /* Assert: the inverse of the above -- proves the two branches are not just both taken,
         * but each one exclusively. */
        self::assertSame('NONE', $returned, 'render_template_view() must not return a value when $return is false.');
        self::assertSame('MARKER-return-false', $appended);
    }

    /**
     * @return array{0: string, 1: string} [return value echoed as 'NONE' when null, $CI->output->append_output() argument echoed as 'NONE' when never called]
     */
    private function runRenderTemplateView(bool $return): array
    {
        $fixtureDir = sys_get_temp_dir() . '/render_template_view_test_' . bin2hex(random_bytes(6));
        mkdir($fixtureDir . '/invoice_templates/pdf', 0777, true);
        file_put_contents(
            $fixtureDir . '/invoice_templates/pdf/Custom.php',
            '<?php echo $marker;'
        );

        $script = sprintf(
            <<<'PHP'
                define('BASEPATH', '/');
                define('CUSTOM_TEMPLATES_FOLDER', %s . DIRECTORY_SEPARATOR);
                $GLOBALS['appended'] = null;
                class StubOutput { public function append_output($s) { $GLOBALS['appended'] = $s; } }
                class StubCI { public $output; public function __construct() { $this->output = new StubOutput(); } }
                function &get_instance() { static $ci; if ($ci === null) { $ci = new StubCI(); } return $ci; }
                require %s;
                $returned = render_template_view('invoice_templates/pdf/Custom', ['marker' => %s], %s);
                echo ($returned === null ? 'NONE' : $returned) . '|' . ($GLOBALS['appended'] === null ? 'NONE' : $GLOBALS['appended']);
                PHP,
            var_export($fixtureDir, true),
            var_export(dirname(__DIR__, 3) . '/application/helpers/template_helper.php', true),
            var_export('MARKER-return-' . ($return ? 'true' : 'false'), true),
            var_export($return, true)
        );

        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=-1 -d display_errors=1 -r ' . escapeshellarg($script) . ' 2>&1');

        unlink($fixtureDir . '/invoice_templates/pdf/Custom.php');
        rmdir($fixtureDir . '/invoice_templates/pdf');
        rmdir($fixtureDir . '/invoice_templates');
        rmdir($fixtureDir);

        return explode('|', (string) $output, 2) + ['', ''];
    }
}
