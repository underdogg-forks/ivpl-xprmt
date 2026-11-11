<?php

declare(strict_types=1);

namespace Tests\Support\SecurityScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Sweeps an InvoicePlane tree for the classes of mistake behind the reported advisories.
 *
 * Every rule looks for a shape of code, wherever it occurs, and not for one reported line: a request value that
 * ends up in a validation rule string, an echo into a script block that is not encoded for JavaScript, a public
 * method on a controller nobody has to log in for. A finding is a place to look at, not a proven vulnerability;
 * the baseline file holds the findings somebody has looked at, so only new ones fail the test.
 *
 * Usage from the command line: php tests/Support/SecurityScanner/scan.php <project folder>
 */
final class Scanner
{
    /** Functions whose result may be printed into HTML. */
    private const HTML_SAFE_FUNCTIONS = [
        'htmlsc', 'html_escape', 'htmlspecialchars', 'htmlentities', 'trans', 'lang', 'site_url', 'base_url',
        'current_url', 'uri_string', 'format_currency', 'format_amount', 'format_quantity', 'format_date',
        'date_from_mysql', 'date_from_timestamp', 'json_encode', 'intval', 'floatval', 'count', 'number_format',
        'urlencode', 'rawurlencode', 'pager', 'form_open', 'form_close', 'form_hidden', 'validation_errors',
        'escape_url_for_javascript', 'encode_for_javascript_string', 'date', 'time', 'strtotime', 'min', 'max',
        'round', 'abs', 'strlen', 'mb_strlen', 'ucfirst', 'check_select', 'get_safe_referer', 'nl2br', 'sprintf',
        'timeago', 'invoice_logo', 'invoice_logo_pdf', 'date_format_setting', 'date_format_datepicker', 'bin2hex',
        'md5', 'sha1', 'hash', 'str_repeat', 'implode',
    ];

    /** Functions whose result may be printed inside a <script> block. */
    private const SCRIPT_SAFE_FUNCTIONS = [
        'json_encode', 'intval', 'floatval', 'count', 'escape_url_for_javascript', 'encode_for_javascript_string',
        'site_url', 'base_url', 'time',
    ];

    /** Functions that only wrap their arguments: the result is as safe as what goes in. */
    private const AFTER_PHP_82 = [
        'json_validate', 'str_increment', 'str_decrement', 'mb_str_pad', 'stream_context_set_options',
        'mb_trim', 'mb_ltrim', 'mb_rtrim', 'mb_ucfirst', 'mb_lcfirst',
        'array_find', 'array_find_key', 'array_any', 'array_all',
        'bcceil', 'bcfloor', 'bcround', 'bcdivmod', 'fpow', 'grapheme_str_split', 'request_parse_body',
        'http_get_last_response_headers', 'http_clear_last_response_headers',
        'array_first', 'array_last', 'get_error_handler', 'get_exception_handler',
    ];

    private const PASS_THROUGH_FUNCTIONS = ['nl2br', 'sprintf', 'ucfirst', 'implode', 'str_repeat', 'trim', 'mb_strtolower', 'strtolower', 'mb_strtoupper', 'strtoupper'];

    private Parser $parser;

    private Standard $printer;

    private NodeFinder $finder;

    /** @var list<Finding> */
    private array $findings = [];

    private string $file = '';

    public function __construct(private readonly string $root)
    {
        $this->parser  = (new ParserFactory())->createForHostVersion();
        $this->printer = new Standard();
        $this->finder  = new NodeFinder();
    }

    /**
     * @return list<Finding>
     */
    public function scan(): array
    {
        $this->findings = [];

        foreach ($this->phpFiles($this->root . '/application') as $path) {
            $this->file = ltrim(str_replace($this->root, '', $path), '/');

            if (str_contains($this->file, 'application/language/') || str_contains($this->file, 'application/third_party/')) {
                continue;
            }

            try {
                $ast = $this->parser->parse((string) file_get_contents($path)) ?? [];
            } catch (Throwable $e) {
                $this->add('parse-error', null, 'file', 'The file could not be parsed: ' . $e->getMessage());

                continue;
            }

            if (str_contains($this->file, '/views/')) {
                $this->scanView($ast);
            }

            $this->scanCode($ast);
        }

        usort($this->findings, static fn (Finding $a, Finding $b): int => [$a->rule, $a->file, $a->line] <=> [$b->rule, $b->file, $b->line]);

        // The same code twice in one function gets a running number, so a third copy is a new finding.
        $seen     = [];
        $numbered = [];

        foreach ($this->findings as $finding) {
            $seen[$finding->fingerprint] = ($seen[$finding->fingerprint] ?? 0) + 1;
            $numbered[]                  = new Finding($finding->rule, $finding->file, $finding->line, $finding->where, $finding->message, $finding->fingerprint . '#' . $seen[$finding->fingerprint]);
        }

        return $this->findings = $numbered;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $folder): array
    {
        if ( ! is_dir($folder)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    // -----------------------------------------------------------------------------------------------------------
    // Views: what is printed, and where
    // -----------------------------------------------------------------------------------------------------------

    /**
     * @param array<Node> $ast
     */
    private function scanView(array $ast): void
    {
        $inScript = false;

        foreach ($this->inSourceOrder($ast) as $node) {
            if ($node instanceof Stmt\InlineHTML) {
                $inScript = $this->scriptStateAfter($node->value, $inScript);

                continue;
            }

            if ( ! $node instanceof Stmt\Echo_) {
                continue;
            }

            foreach ($node->exprs as $expr) {
                $code = $this->printer->prettyPrintExpr($expr);

                if (preg_match('/get_setting\(\s*[\'"][^\'"]*(secret|password|apikey|api_key|privatekey|private_key)[\'"]/i', $code) === 1
                    && preg_match('/apiKeyPublic|public/i', $code) !== 1) {
                    $this->add('secret-setting-in-view', $expr, 'view', 'A secret setting is printed into a page: ' . $code);
                }

                if ($inScript) {
                    if ( ! $this->isSafeToPrint($expr, self::SCRIPT_SAFE_FUNCTIONS)) {
                        $this->add('script-echo-not-encoded', $expr, 'view', 'Printed inside <script> without json_encode() or an integer cast: ' . $code);
                    }

                    continue;
                }

                if ( ! $this->isSafeToPrint($expr, self::HTML_SAFE_FUNCTIONS)) {
                    $this->add('html-echo-not-escaped', $expr, 'view', 'Printed into HTML without escaping: ' . $code);
                }
            }
        }
    }

    private function scriptStateAfter(string $html, bool $inScript): bool
    {
        preg_match_all('/<script\b[^>]*>|<\/script\s*>/i', $html, $matches);

        foreach ($matches[0] as $tag) {
            // A <script src="..."> element has no body of its own; its closing tag follows right away.
            $inScript = $tag[1] !== '/';
        }

        return $inScript;
    }

    /**
     * @param list<string> $safeFunctions
     */
    private function isSafeToPrint(Expr $expr, array $safeFunctions): bool
    {
        if ($expr instanceof Scalar\String_ || $expr instanceof Scalar\Int_ || $expr instanceof Scalar\Float_
            || $expr instanceof Expr\ConstFetch || $expr instanceof Expr\ClassConstFetch
            || $expr instanceof Expr\Cast\Int_ || $expr instanceof Expr\Cast\Double || $expr instanceof Expr\Cast\Bool_
            || $expr instanceof Expr\Isset_ || $expr instanceof Expr\Empty_) {
            return true;
        }

        if ($expr instanceof Expr\BinaryOp\Concat) {
            return $this->isSafeToPrint($expr->left, $safeFunctions) && $this->isSafeToPrint($expr->right, $safeFunctions);
        }

        if ($expr instanceof Expr\BinaryOp) {
            // Arithmetic and comparisons give a number or a boolean.
            return ! $expr instanceof Expr\BinaryOp\Coalesce
                || ($this->isSafeToPrint($expr->left, $safeFunctions) && $this->isSafeToPrint($expr->right, $safeFunctions));
        }

        if ($expr instanceof Expr\Ternary) {
            return $this->isSafeToPrint($expr->if ?? $expr->cond, $safeFunctions) && $this->isSafeToPrint($expr->else, $safeFunctions);
        }

        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name) {
            $name = $expr->name->toLowerString();
            $args = $expr->getArgs();

            // get_setting($key, $default, true) escapes; anchor($url, $text) is as safe as its text.
            if ($name === 'get_setting') {
                return isset($args[2]) && $args[2]->value instanceof Expr\ConstFetch && $args[2]->value->name->toLowerString() === 'true';
            }

            if ($name === 'anchor') {
                return ! isset($args[1]) || $this->isSafeToPrint($args[1]->value, $safeFunctions);
            }

            if (in_array($name, self::PASS_THROUGH_FUNCTIONS, true) && in_array($name, array_map('strtolower', $safeFunctions), true)) {
                foreach ($expr->getArgs() as $arg) {
                    if ( ! $this->isSafeToPrint($arg->value, $safeFunctions)) {
                        return false;
                    }
                }

                return true;
            }

            return in_array($name, array_map('strtolower', $safeFunctions), true);
        }

        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            // form_value($key, true) escapes; without the switch it prints the stored value as it is.
            if ($expr->name->toLowerString() === 'form_value') {
                $args = $expr->getArgs();

                return isset($args[1]) && $args[1]->value instanceof Expr\ConstFetch && $args[1]->value->name->toLowerString() === 'true';
            }

            // The layout and security helpers print markup or tokens they build themselves.
            return in_array($expr->name->toLowerString(), ['get_csrf_hash', 'get_csrf_token_name', 'load_view', 'load', 'view', 'format', 'create_links'], true);
        }

        return false;
    }

    // -----------------------------------------------------------------------------------------------------------
    // Controllers, models, helpers, libraries
    // -----------------------------------------------------------------------------------------------------------

    /**
     * @param array<Node> $ast
     */
    private function scanCode(array $ast): void
    {
        foreach ($this->finder->findInstanceOf($ast, Stmt\Class_::class) as $class) {
            $this->scanClass($class);
        }

        $functions = $this->finder->find($ast, static fn (Node $n): bool => $n instanceof Stmt\ClassMethod || $n instanceof Stmt\Function_);

        foreach ($functions as $function) {
            $this->scanFunction($function, (array) $function->stmts);
        }

        // Code outside any function (config files, views).
        $this->scanCalls($ast, 'file', false);
    }

    private function scanClass(Stmt\Class_ $class): void
    {
        $parent = $class->extends?->toString() ?? '';
        $name   = $class->name?->toString() ?? '(anonymous)';

        if ( ! str_contains($this->file, '/controllers/')) {
            return;
        }

        $isAjax = false;

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'ajax_controller' && $prop->default instanceof Expr\ConstFetch && $prop->default->name->toLowerString() === 'true') {
                    $isAjax = true;
                }
            }
        }

        foreach ($class->getMethods() as $method) {
            $methodName = $method->name->toString();

            if ( ! $method->isPublic() || str_starts_with($methodName, '_')) {
                continue;
            }

            // Every public method of a controller is a URL. On a controller nobody has to log in for, that URL is
            // open to the internet.
            if (in_array($parent, ['Base_Controller', 'MX_Controller', 'CI_Controller'], true)) {
                $this->add('open-route', $method, $name . '::' . $methodName, 'Public method on a controller that needs no login (' . $parent . '): ' . $name . '::' . $methodName . '()', $name . '::' . $methodName);
            }

            if ( ! $isAjax && $this->writesWithoutPostCheck($method)) {
                $this->add('write-reachable-by-get', $method, $name . '::' . $methodName, $name . '::' . $methodName . '() changes data and never checks that the request is a POST', $name . '::' . $methodName);
            }
        }
    }

    private function writesWithoutPostCheck(Stmt\ClassMethod $method): bool
    {
        $writes = false;
        $checks = false;

        foreach ($this->finder->find((array) $method->stmts, static fn (Node $n): bool => $n instanceof Expr\MethodCall || $n instanceof Expr\FuncCall) as $call) {
            $callName = $call->name instanceof Node\Identifier || $call->name instanceof Node\Name ? strtolower($call->name->toString()) : '';

            if ($call instanceof Expr\MethodCall && in_array($callName, ['delete', 'save', 'insert', 'update', 'insert_batch', 'update_batch', 'truncate', 'empty_table'], true)) {
                $writes = true;
            }

            if (in_array($callName, ['ensure_valid_post_request', 'verify_csrf_token', 'post', 'method', 'run_validation', 'is_ajax_request'], true)) {
                $checks = true;
            }
        }

        return $writes && ! $checks;
    }

    /**
     * @param array<Node> $stmts
     */
    private function scanFunction(Stmt\ClassMethod|Stmt\Function_ $function, array $stmts): void
    {
        $where = $function->name->toString();

        $callNames = [];

        foreach ($this->finder->find($stmts, static fn (Node $n): bool => $n instanceof Expr\FuncCall && $n->name instanceof Node\Name) as $call) {
            $callNames[] = $call->name->toLowerString();
        }

        $validatesPaths = array_intersect($callNames, ['validate_safe_filename', 'validate_file_in_directory', 'basename', 'realpath', 'sanitize_filename_for_header']) !== [];

        $this->scanCalls($stmts, $where, $validatesPaths);
        $this->scanRequestArrays($stmts, $where);

        if (str_contains($this->file, 'modules/guest/controllers/')) {
            $this->scanGuestQueries($stmts, $where);
        }
    }

    /**
     * @param array<Node> $stmts
     */
    private function scanCalls(array $stmts, string $where, bool $validatesPaths): void
    {
        $topLevelOnly = $where === 'file';

        $nodes = $topLevelOnly
            ? $this->findOutsideFunctions($stmts)
            : $this->finder->find($stmts, static fn (Node $n): bool => true);

        // Variables that hold a closure written right here: calling them is an ordinary call.
        $closures = [];

        foreach ($nodes as $node) {
            if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)
                && ($node->expr instanceof Expr\Closure || $node->expr instanceof Expr\ArrowFunction)) {
                $closures[] = $node->var->name;
            }
        }

        foreach ($nodes as $node) {
            // --- A validation rule string built from something that is not a literal -----------------------
            if ($node instanceof Node\ArrayItem && $node->key instanceof Scalar\String_ && $node->key->value === 'rules'
                && $this->hasUnguardedDynamicPart($node->value)) {
                $this->add('rule-string-injection', $node, $where, 'A validation rule string is built from a value that is not an integer cast: ' . $this->code($node->value));
            }

            if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier && $node->name->toString() === 'set_rules'
                && isset($node->getArgs()[2]) && $this->hasUnguardedDynamicPart($node->getArgs()[2]->value)) {
                $this->add('rule-string-injection', $node, $where, 'set_rules() gets a rule string built from a value that is not an integer cast: ' . $this->code($node->getArgs()[2]->value));
            }

            // --- SQL text put together by hand --------------------------------------------------------------
            if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier
                && in_array($node->name->toLowerString(), ['where', 'or_where', 'having', 'or_having', 'query', 'select', 'join', 'order_by', 'group_by', 'like', 'or_like', 'filter_where', 'filter_having', 'filter_or_where'], true)
                && isset($node->getArgs()[0]) && $this->hasDynamicPart($node->getArgs()[0]->value)) {
                $this->add('sql-built-by-hand', $node, $where, $node->name->toString() . '() gets SQL text with a variable in it: ' . $this->code($node->getArgs()[0]->value));
            }

            // --- Secrets compared with == or === ------------------------------------------------------------
            if (($node instanceof Expr\BinaryOp\Equal || $node instanceof Expr\BinaryOp\NotEqual
                    || $node instanceof Expr\BinaryOp\Identical || $node instanceof Expr\BinaryOp\NotIdentical)
                && ! $this->isLiteral($node->left) && ! $this->isLiteral($node->right)) {
                $code = $this->code($node);

                if (preg_match('/token|cron_key|url_key|passwordreset|secret|signature|fingerprint|csrf/i', $code) === 1
                    && preg_match('/token_name|_expiry|token_expir|\bcount\(|strlen\(|\$key\s*===?\s*\'|user_id|client_id/i', $code) !== 1) {
                    $this->add('secret-compared-without-hash-equals', $node, $where, 'A secret is compared with an operator that is not constant-time: ' . $code);
                }
            }

            if ( ! $node instanceof Expr\FuncCall && ! $node instanceof Expr\Include_ && ! $node instanceof Expr\Eval_
                && ! $node instanceof Expr\Variable && ! $node instanceof Expr\ArrayDimFetch) {
                continue;
            }

            // --- Request data read around CodeIgniter's input class -------------------------------------------
            if ($node instanceof Expr\Variable && is_string($node->name) && in_array($node->name, ['_POST', '_GET', '_REQUEST', '_COOKIE'], true)) {
                $this->add('superglobal-read', $node, $where, 'Request data is read from $' . $node->name . ' and not through $this->input');

                continue;
            }

            if ($node instanceof Expr\ArrayDimFetch && $node->var instanceof Expr\Variable && $node->var->name === '_SERVER'
                && $node->dim instanceof Scalar\String_ && in_array($node->dim->value, ['HTTP_REFERER', 'HTTP_HOST', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_CLIENT_IP', 'PHP_SELF', 'REQUEST_URI', 'QUERY_STRING'], true)) {
                $this->add('client-controlled-server-value', $node, $where, 'A value the client controls is read from $_SERVER[\'' . $node->dim->value . '\']');

                continue;
            }

            if ($node instanceof Expr\Include_ && $this->hasDynamicPart($node->expr) && $this->containsVariable($node->expr)) {
                $this->add('dynamic-include', $node, $where, 'A file is included from a path with a variable in it: ' . $this->code($node->expr));

                continue;
            }

            if ($node instanceof Expr\Eval_) {
                $this->add('dangerous-call', $node, $where, 'eval() is called');

                continue;
            }

            if ( ! $node instanceof Expr\FuncCall) {
                continue;
            }

            if ( ! $node->name instanceof Node\Name) {
                if ( ! ($node->name instanceof Expr\Variable && is_string($node->name->name) && in_array($node->name->name, $closures, true))) {
                    $this->add('dangerous-call', $node, $where, 'A function is called by a name held in a variable: ' . $this->code($node));
                }

                continue;
            }

            $name = $node->name->toLowerString();
            $args = $node->getArgs();

            // --- env_bool() with a default that is not a string ------------------------------------------------
            if ($name === 'env_bool' && isset($args[1]) && ! $args[1]->value instanceof Scalar\String_) {
                $this->add('env-bool-default-not-a-string', $node, $where, 'env_bool() gets a default that is not a string: ' . $this->code($node));
            }

            // --- Functions that do not exist on the oldest supported PHP (8.2): a fatal error on that code path ---
            if (in_array($name, self::AFTER_PHP_82, true)) {
                $this->add('newer-than-php-baseline', $node, $where, $name . '() does not exist on PHP 8.2: ' . $this->code($node));
            }

            // --- Randomness that can be predicted ---------------------------------------------------------------
            if (in_array($name, ['rand', 'mt_rand', 'uniqid', 'str_shuffle', 'random_string', 'lcg_value', 'mt_srand', 'srand', 'shuffle', 'array_rand'], true)) {
                $this->add('predictable-randomness', $node, $where, $name . '() is not a cryptographic random source: ' . $this->code($node));
            }

            // --- Calls that run code or commands, or rebuild objects from text -------------------------------------
            if (in_array($name, ['system', 'exec', 'passthru', 'shell_exec', 'popen', 'proc_open', 'pcntl_exec', 'assert', 'create_function', 'extract', 'parse_str'], true)
                || (in_array($name, ['unserialize', 'call_user_func', 'call_user_func_array'], true) && isset($args[0]) && ! $this->isLiteral($args[0]->value))) {
                $this->add('dangerous-call', $node, $where, $name . '() is called: ' . $this->code($node));
            }

            // --- File system calls on a path nobody checked ----------------------------------------------------------
            if ( ! $validatesPaths && in_array($name, ['unlink', 'file_put_contents', 'fopen', 'rename', 'copy', 'move_uploaded_file', 'mkdir', 'rmdir', 'readfile', 'file'], true)
                && isset($args[0]) && $this->containsVariable($args[0]->value)) {
                $this->add('unchecked-file-path', $node, $where, $name . '() gets a path with a variable in it, and nothing in this function checks the path: ' . $this->code($args[0]->value));
            }

            // --- Outbound requests to an address that is not fixed ------------------------------------------------------
            // A constant counts as "not fixed" here: SUMEX_URL and its like come from ipconfig.php.
            if (($name === 'curl_init' && isset($args[0]) && ! $this->isFixedText($args[0]->value))
                || ($name === 'curl_setopt' && isset($args[2]) && $this->code($args[1]->value) === 'CURLOPT_URL' && ! $this->isFixedText($args[2]->value))
                || (in_array($name, ['file_get_contents', 'fsockopen', 'get_headers', 'getimagesize'], true) && isset($args[0]) && preg_match('/url|uri|http|host/i', $this->code($args[0]->value)) === 1 && ! $this->isFixedText($args[0]->value))) {
                $this->add('outbound-request-to-variable-address', $node, $where, $name . '() fetches an address that is not fixed: ' . $this->code($node));
            }
        }
    }

    /**
     * Request arrays that reach the database as they came: the whole POST array, or decoded JSON, handed to save(),
     * insert() or update(), where every key becomes a column name.
     *
     * @param array<Node> $stmts
     */
    private function scanRequestArrays(array $stmts, string $where): void
    {
        $tainted = [];

        foreach ($this->finder->findInstanceOf($stmts, Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Expr\Variable && is_string($assign->var->name) && $this->isWholeRequestArray($assign->expr)) {
                $tainted[$assign->var->name] = true;
            }
        }

        // foreach ($items as $item): the element is as open as the array it comes from.
        foreach ($this->finder->findInstanceOf($stmts, Stmt\Foreach_::class) as $loop) {
            if ($loop->expr instanceof Expr\Variable && is_string($loop->expr->name) && isset($tainted[$loop->expr->name])
                && $loop->valueVar instanceof Expr\Variable && is_string($loop->valueVar->name)) {
                $tainted[$loop->valueVar->name] = true;
            }
        }

        foreach ($this->finder->findInstanceOf($stmts, Expr\MethodCall::class) as $call) {
            if ( ! $call->name instanceof Node\Identifier || ! in_array($call->name->toLowerString(), ['save', 'insert', 'update', 'insert_batch', 'update_batch', 'set', 'replace'], true)) {
                continue;
            }

            foreach ($call->getArgs() as $arg) {
                $value = $arg->value;

                if ($this->isWholeRequestArray($value) || ($value instanceof Expr\Variable && is_string($value->name) && isset($tainted[$value->name]))) {
                    $this->add('request-array-to-database', $call, $where, $call->name->toString() . '() gets a request array whose keys nobody limited: ' . $this->code($call));

                    break;
                }
            }
        }
    }

    private function isWholeRequestArray(Expr $expr): bool
    {
        // $this->input->post() or ->get() without a key: the whole array.
        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier
            && in_array($expr->name->toLowerString(), ['post', 'get', 'post_get', 'get_post'], true)
            && $expr->var instanceof Expr\PropertyFetch && $expr->var->name instanceof Node\Identifier && $expr->var->name->toString() === 'input'
            && ($expr->getArgs() === [] || ($expr->getArgs()[0]->value instanceof Expr\ConstFetch && $expr->getArgs()[0]->value->name->toLowerString() === 'null'))) {
            return true;
        }

        // json_decode() of anything that came from the request.
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && $expr->name->toLowerString() === 'json_decode' && isset($expr->getArgs()[0])) {
            return preg_match('/->input->|php:\/\/input|\$_POST|\$_GET/', $this->code($expr->getArgs()[0]->value)) === 1;
        }

        return false;
    }

    /**
     * Guest pages: every query on invoices, quotes or payments must carry the rule for what a guest may see.
     *
     * @param array<Node> $stmts
     */
    private function scanGuestQueries(array $stmts, string $where): void
    {
        $all = $this->code(new Stmt\Block($stmts));

        foreach (['invoices', 'quotes', 'payments'] as $model) {
            $property = '$this->mdl_' . $model . '->';

            if ( ! str_contains($all, $property)) {
                continue;
            }

            // The rule counts wherever it is applied in the function: filters stay on the model until the query runs.
            if (preg_match('/\$this->mdl_' . $model . '(->\w+\([^;]*?\))*->(guest_visible|is_open|is_overdue)\(\)/s', $all) === 1) {
                continue;
            }

            foreach ($this->finder->findInstanceOf($stmts, Expr\MethodCall::class) as $call) {
                if ( ! $call->name instanceof Node\Identifier || ! in_array($call->name->toLowerString(), ['where', 'where_in', 'by_client', 'get', 'paginate'], true)) {
                    continue;
                }

                $code = $this->code($call);

                // Only the outermost call of a chain on this model, and not a deny-all filter.
                if ( ! str_starts_with($code, $property) || str_contains($code, "where('1=0')")) {
                    continue;
                }

                $this->add('guest-query-without-visibility-rule', $call, $where, 'A guest query on ' . $model . ' never applies guest_visible() in this function: ' . $code, $model);

                break;
            }
        }
    }

    // -----------------------------------------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------------------------------------

    /** A string put together from parts, at least one of which is not a literal. */
    private function hasDynamicPart(Expr $expr): bool
    {
        if ($expr instanceof Scalar\InterpolatedString) {
            return true;
        }

        if ($expr instanceof Expr\BinaryOp\Concat) {
            return ! ($this->isLiteral($expr->left) && $this->isLiteral($expr->right))
                && ($this->containsVariable($expr->left) || $this->containsVariable($expr->right));
        }

        return false;
    }

    /** Like hasDynamicPart(), but a part that is cast to an integer (or guarded by ctype_digit) does not count. */
    private function hasUnguardedDynamicPart(Expr $expr): bool
    {
        if ($expr instanceof Scalar\InterpolatedString) {
            return true;
        }

        if ($expr instanceof Expr\BinaryOp\Concat) {
            return $this->hasUnguardedDynamicPart($expr->left) || $this->hasUnguardedDynamicPart($expr->right);
        }

        if ($expr instanceof Expr\Ternary) {
            // (ctype_digit((string) $id) ? '.col.' . (int) $id : '') is the accepted shape.
            return $this->hasUnguardedDynamicPart($expr->if ?? $expr->cond) || $this->hasUnguardedDynamicPart($expr->else);
        }

        if ($this->isLiteral($expr) || $expr instanceof Expr\Cast\Int_
            || ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && in_array($expr->name->toLowerString(), ['intval', 'trans', 'lang'], true))) {
            return false;
        }

        return $this->containsVariable($expr);
    }

    private function isLiteral(Expr $expr): bool
    {
        return $expr instanceof Scalar\String_ || $expr instanceof Scalar\Int_ || $expr instanceof Scalar\Float_
            || $expr instanceof Expr\ConstFetch || $expr instanceof Expr\ClassConstFetch
            || ($expr instanceof Expr\BinaryOp\Concat && $this->isLiteral($expr->left) && $this->isLiteral($expr->right))
            || ($expr instanceof Expr\Array_ && $expr->items === []);
    }

    /** Text written out in the source, with no constant, variable or call in it. */
    private function isFixedText(Expr $expr): bool
    {
        return $expr instanceof Scalar\String_
            || ($expr instanceof Expr\BinaryOp\Concat && $this->isFixedText($expr->left) && $this->isFixedText($expr->right));
    }

    private function containsVariable(Node $node): bool
    {
        return $node instanceof Expr\Variable
            || $this->finder->findFirst([$node], static fn (Node $n): bool => $n instanceof Expr\Variable || $n instanceof Expr\MethodCall || $n instanceof Expr\PropertyFetch) !== null;
    }

    private function code(Node $node): string
    {
        $code = $node instanceof Expr ? $this->printer->prettyPrintExpr($node) : $this->printer->prettyPrint([$node]);

        return (string) preg_replace('/\s+/', ' ', $code);
    }

    /**
     * @param array<Node> $ast
     *
     * @return list<Node>
     */
    private function inSourceOrder(array $ast): array
    {
        $nodes = $this->finder->find($ast, static fn (Node $n): bool => $n instanceof Stmt\InlineHTML || $n instanceof Stmt\Echo_);
        usort($nodes, static fn (Node $a, Node $b): int => $a->getStartFilePos() <=> $b->getStartFilePos());

        return $nodes;
    }

    /**
     * Every node that is not inside a function or method body.
     *
     * @param array<Node> $ast
     *
     * @return list<Node>
     */
    private function findOutsideFunctions(array $ast): array
    {
        $inside = [];

        foreach ($this->finder->find($ast, static fn (Node $n): bool => $n instanceof Stmt\ClassMethod || $n instanceof Stmt\Function_) as $function) {
            $inside[] = [$function->getStartFilePos(), $function->getEndFilePos()];
        }

        return array_values(array_filter(
            $this->finder->find($ast, static fn (Node $n): bool => true),
            static function (Node $n) use ($inside): bool {
                foreach ($inside as [$start, $end]) {
                    if ($n->getStartFilePos() >= $start && $n->getEndFilePos() <= $end) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    private function add(string $rule, ?Node $node, string $where, string $message, ?string $identity = null): void
    {
        $snippet = $identity ?? ($node !== null ? $this->code($node) : $message);

        $this->findings[] = new Finding(
            $rule,
            $this->file,
            $node?->getStartLine() ?? 0,
            $where,
            $message,
            $rule . '|' . $this->file . '|' . $where . '|' . substr(sha1($snippet), 0, 12)
        );
    }
}
