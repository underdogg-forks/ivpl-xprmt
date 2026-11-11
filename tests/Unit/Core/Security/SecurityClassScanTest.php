<?php

namespace Tests\Unit\Core\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SecurityScanner\Finding;
use Tests\Support\SecurityScanner\Scanner;

/**
 * Keeps the classes of mistake behind the reported advisories from coming back somewhere else.
 *
 * The scanner (tests/Support/SecurityScanner) looks for shapes of code in the whole application. The findings
 * somebody has looked at are listed in baseline.txt. This test fails for a finding that is not in the baseline
 * (new code repeated a known mistake, or a new place needs a decision) and for a baseline line that no longer
 * matches anything (the place was fixed: remove the line, so the list only ever shrinks).
 *
 * To look at the findings:  php tests/Support/SecurityScanner/scan.php
 * To accept the current state after reviewing it:
 *   php tests/Support/SecurityScanner/scan.php --fingerprints > tests/Support/SecurityScanner/baseline.txt
 */
#[Group('security')]
final class SecurityClassScanTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    private const BASELINE = self::ROOT . '/tests/Support/SecurityScanner/baseline.txt';

    /**
     * rule, file inside the project, code that must be found, the same code written safely.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function shapes(): array
    {
        $controller = "<?php\nclass Things extends Admin_Controller {\n    public function act(\$id) {\n        %s\n    }\n}\n";
        $model      = "<?php\nclass Mdl_Things extends Response_Model {\n    public function validation_rules() {\n        return ['number' => ['field' => 'number', 'rules' => %s]];\n    }\n}\n";

        return [
            'rule string from the model id (GHSA-vx9q)' => [
                'rule-string-injection', 'application/modules/things/models/Mdl_things.php',
                sprintf($model, "'is_unique[ip_things.number' . ((\$this->id) ? '.thing_id.' . \$this->id : '') . ']'"),
                sprintf($model, "'is_unique[ip_things.number' . (ctype_digit((string) \$this->id) ? '.thing_id.' . (int) \$this->id : '') . ']'"),
            ],
            'rule string through set_rules()' => [
                'rule-string-injection', 'application/modules/things/controllers/Things.php',
                sprintf($controller, "\$this->form_validation->set_rules('number', 'Number', 'required|is_unique[ip_things.number.thing_id.' . \$id . ']');"),
                sprintf($controller, "\$this->form_validation->set_rules('number', 'Number', 'required|is_unique[ip_things.number.thing_id.' . (int) \$id . ']');"),
            ],
            'decoded request items saved as they came (GHSA-pmff)' => [
                'request-array-to-database', 'application/modules/things/controllers/Things.php',
                sprintf($controller, "\$items = json_decode(\$this->input->post('items'));\n        foreach (\$items as \$item) { \$this->mdl_things->save(null, \$item); }"),
                sprintf($controller, "\$items = json_decode(\$this->input->post('items'));\n        foreach (\$items as \$item) { \$this->mdl_things->save(null, ['thing_name' => \$item->thing_name]); }"),
            ],
            'the whole POST array saved' => [
                'request-array-to-database', 'application/modules/things/controllers/Things.php',
                sprintf($controller, "\$this->db->insert('ip_things', \$this->input->post());"),
                sprintf($controller, "\$this->db->insert('ip_things', ['thing_name' => \$this->input->post('thing_name')]);"),
            ],
            'an id printed unquoted into script (GHSA-478q)' => [
                'script-echo-not-encoded', 'application/modules/things/views/view.php',
                "<script>\n$.post('/x', { thing_id: <?php echo \$thing_id; ?> });\n</script>\n",
                "<script>\n$.post('/x', { thing_id: <?php echo json_encode(\$thing_id); ?> });\n</script>\n",
            ],
            'a setting printed into a script string (GHSA-x3r7)' => [
                'script-echo-not-encoded', 'application/modules/things/views/view.php',
                "<p>ok</p><script src=\"/a.js\"></script><script>\nvar first = '<?php echo get_setting('first_day_of_week'); ?>';\n</script>\n",
                "<p>ok</p><script src=\"/a.js\"></script><script>\nvar first = <?php echo json_encode(get_setting('first_day_of_week')); ?>;\n</script>\n",
            ],
            'a stored value printed into HTML (GHSA-gpv9)' => [
                'html-echo-not-escaped', 'application/modules/things/views/view.php',
                "<td><?php echo \$thing->thing_name; ?></td>\n",
                "<td><?php echo htmlsc(\$thing->thing_name); ?></td>\n",
            ],
            'form_value() without its escape switch' => [
                'html-echo-not-escaped', 'application/modules/things/views/form.php',
                "<input value=\"<?php echo \$this->mdl_things->form_value('thing_name'); ?>\">\n",
                "<input value=\"<?php echo \$this->mdl_things->form_value('thing_name', true); ?>\">\n",
            ],
            'a secret setting printed into a page (GHSA-8543)' => [
                'secret-setting-in-view', 'application/modules/things/views/pay.php',
                "<script>\nvar key = <?php echo json_encode(get_setting('gateway_stripe_apiKey')); ?>;\n</script>\n",
                "<script>\nvar key = <?php echo json_encode(get_setting('gateway_stripe_apiKeyPublic')); ?>;\n</script>\n",
            ],
            'a public method on a controller that needs no login (GHSA-x2m4)' => [
                'open-route', 'application/modules/things/controllers/Open.php',
                "<?php\nclass Open extends Base_Controller {\n    public function authenticate(\$a, \$b) { return true; }\n}\n",
                "<?php\nclass Open extends Base_Controller {\n    private function authenticate(\$a, \$b) { return true; }\n}\n",
            ],
            'a delete that a link can trigger (GHSA-qx8h)' => [
                'write-reachable-by-get', 'application/modules/things/controllers/Things.php',
                sprintf($controller, "\$this->mdl_things->delete(\$id);\n        redirect('things');"),
                sprintf($controller, "if ( ! \$this->ensure_valid_post_request('things')) { return; }\n        \$this->mdl_things->delete(\$id);\n        redirect('things');"),
            ],
            'a guest listing without the visibility rule (GHSA-4gpg)' => [
                'guest-query-without-visibility-rule', 'application/modules/guest/controllers/Payments.php',
                "<?php\nclass Payments extends Guest_Controller {\n    public function index() {\n        \$this->mdl_payments->where_in('ip_invoices.client_id', \$this->user_clients);\n        \$this->mdl_payments->paginate('x', 0);\n    }\n}\n",
                "<?php\nclass Payments extends Guest_Controller {\n    public function index() {\n        \$this->mdl_payments->guest_visible()->where_in('ip_invoices.client_id', \$this->user_clients);\n        \$this->mdl_payments->paginate('x', 0);\n    }\n}\n",
            ],
            'env_bool() with a boolean default (GHSA-vwmm)' => [
                'env-bool-default-not-a-string', 'application/config/config.php',
                "<?php\n\$config['cookie_secure'] = env_bool('COOKIE_SECURE', true);\n",
                "<?php\n\$config['cookie_secure'] = env_bool('COOKIE_SECURE', 'true');\n",
            ],
            'a token compared with !== (GHSA-wcqc)' => [
                'secret-compared-without-hash-equals', 'application/modules/things/controllers/Things.php',
                sprintf($controller, 'if ($user->user_passwordreset_token !== $token) { return; }'),
                sprintf($controller, 'if ( ! hash_equals((string) $user->user_passwordreset_token, (string) $token)) { return; }'),
            ],
            'a key from a predictable source (GHSA-chqc)' => [
                'predictable-randomness', 'application/modules/things/models/Mdl_things.php',
                "<?php\nclass Mdl_Things extends Response_Model {\n    public function get_url_key() { \$this->load->helper('string'); return random_string('alnum', 32); }\n}\n",
                "<?php\nclass Mdl_Things extends Response_Model {\n    public function get_url_key() { return bin2hex(random_bytes(16)); }\n}\n",
            ],
            'SQL text with a variable in it (GHSA-g7f2)' => [
                'sql-built-by-hand', 'application/modules/things/controllers/Things.php',
                sprintf($controller, "\$this->mdl_things->having('thing_name LIKE \\'' . \$id . '%\\'')->get();"),
                sprintf($controller, "\$this->mdl_things->where('thing_name LIKE', \$id . '%')->get();"),
            ],
            'a file written to a path nobody checked (GHSA-54xh)' => [
                'unchecked-file-path', 'application/helpers/thing_helper.php',
                "<?php\nfunction write_thing(\$thing) { file_put_contents(UPLOADS_TEMP_FOLDER . \$thing->thing_number . '.xml', 'x'); }\n",
                "<?php\nfunction write_thing(\$thing) { if ( ! validate_safe_filename(\$thing->thing_number)) { return; } file_put_contents(UPLOADS_TEMP_FOLDER . \$thing->thing_number . '.xml', 'x'); }\n",
            ],
            'a request to an address from a setting (GHSA-pqjr)' => [
                'outbound-request-to-variable-address', 'application/libraries/Thing.php',
                "<?php\nclass Thing {\n    public function pdf() { \$ch = curl_init(); curl_setopt(\$ch, CURLOPT_URL, SUMEX_URL . '/generateInvoice'); }\n}\n",
                "<?php\nclass Thing {\n    public function pdf() { \$ch = curl_init(); curl_setopt(\$ch, CURLOPT_URL, 'https://sumex.example/generateInvoice'); }\n}\n",
            ],
            'a file included by a name from a variable' => [
                'dynamic-include', 'application/helpers/thing_helper.php',
                "<?php\nfunction load_thing(\$name) { include APPPATH . 'views/things/' . \$name . '.php'; }\n",
                "<?php\nfunction load_thing(\$name) { include APPPATH . 'views/things/default.php'; }\n",
            ],
            'a function called by a name from a variable' => [
                'dangerous-call', 'application/helpers/thing_helper.php',
                "<?php\nfunction run_thing(\$rule, \$value) { return \$rule(\$value); }\n",
                "<?php\nfunction run_thing(\$rule, \$value) { \$check = static fn (\$v) => \$v !== ''; return \$check(\$value); }\n",
            ],
            'request data read around the input class' => [
                'superglobal-read', 'application/modules/things/controllers/Things.php',
                sprintf($controller, "\$name = \$_POST['name'];"),
                sprintf($controller, "\$name = \$this->input->post('name');"),
            ],
            'the referer taken from the request' => [
                'client-controlled-server-value', 'application/modules/things/controllers/Things.php',
                sprintf($controller, "redirect(\$_SERVER['HTTP_REFERER']);"),
                sprintf($controller, 'redirect(get_safe_referer());'),
            ],
        ];
    }

    #[Test]
    public function it_finds_no_place_that_is_not_in_the_reviewed_baseline(): void
    {
        /* Arrange */
        $baseline = $this->baseline();

        /* Act */
        $new = array_values(array_filter(
            (new Scanner((string) realpath(self::ROOT)))->scan(),
            static fn (Finding $finding): bool => ! isset($baseline[$finding->fingerprint])
        ));

        /* Assert */
        $report = implode("\n", array_map(
            static fn (Finding $f): string => sprintf('  [%s] %s:%d (%s)%s      %s', $f->rule, $f->file, $f->line, $f->where, "\n", $f->message),
            array_slice($new, 0, 25)
        ));

        self::assertSame([], array_map(static fn (Finding $f): string => $f->fingerprint, $new), count($new) . " finding(s) are not in the baseline:\n" . $report);
    }

    #[Test]
    public function it_has_no_baseline_line_for_a_place_that_was_fixed(): void
    {
        /* Arrange */
        $baseline = $this->baseline();

        /* Act */
        foreach ((new Scanner((string) realpath(self::ROOT)))->scan() as $finding) {
            unset($baseline[$finding->fingerprint]);
        }

        /* Assert */
        self::assertSame([], array_keys($baseline), 'These baseline lines match nothing any more. Remove them from baseline.txt.');
    }

    #[Test]
    #[DataProvider('shapes')]
    public function it_finds_the_vulnerable_shape_and_accepts_the_fixed_one(string $rule, string $file, string $vulnerable, string $fixed): void
    {
        /* Act */
        $found = $this->rulesFoundIn($file, $vulnerable);
        $after = $this->rulesFoundIn($file, $fixed);

        /* Assert */
        self::assertContains($rule, $found, 'The scanner missed the vulnerable code.');
        self::assertNotContains($rule, $after, 'The scanner still reports the fixed code.');
    }

    /**
     * @return list<string>
     */
    private function rulesFoundIn(string $file, string $code): array
    {
        $project = sys_get_temp_dir() . '/security-scan-' . bin2hex(random_bytes(6));
        $path    = $project . '/' . $file;
        mkdir(dirname($path), 0o700, true);
        file_put_contents($path, $code);

        try {
            return array_values(array_unique(array_map(
                static fn (Finding $finding): string => $finding->rule,
                (new Scanner($project))->scan()
            )));
        } finally {
            unlink($path);

            for ($folder = dirname($path); $folder !== dirname($project); $folder = dirname($folder)) {
                @rmdir($folder);
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function baseline(): array
    {
        self::assertFileExists(self::BASELINE);

        $lines = array_filter(array_map('trim', file(self::BASELINE) ?: []), static fn (string $line): bool => $line !== '' && $line[0] !== '#');

        return array_fill_keys($lines, true);
    }
}
