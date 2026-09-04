<?php
/**
 * requirements.php — extract the two follow-on epics' input lists from what the
 * application ACTUALLY references.
 *
 * These are the audit's most reusable outputs, and both are derived rather than
 * written: a hand-listed surface drifts the moment a package updates.
 *
 *   docs/audit/SAPI-REQUIREMENTS.md        which superglobal KEYS symfony reads,
 *                                          and which SAPI functions it calls
 *   docs/audit/PDO-SQLITE-REQUIREMENTS.md  which PDO/PDOStatement methods and
 *                                          constants DBAL touches
 *
 * Usage: php tools/audit/requirements.php [--app <dir>]
 */

$app = '/Users/taras/var/projects/symfony-demo-probe/app';
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--app' && isset($argv[$i + 1])) { $app = $argv[++$i]; }
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
    $files[] = $f->getPathname();
}
sort($files);

$sapiFns = ['header', 'header_remove', 'headers_sent', 'headers_list', 'http_response_code',
    'setcookie', 'setrawcookie', 'session_start', 'session_id', 'session_name',
    'session_destroy', 'session_regenerate_id', 'session_write_close', 'session_status',
    'ob_start', 'ob_get_clean', 'ob_get_contents', 'ob_end_clean', 'ob_end_flush',
    'ob_get_level', 'ob_get_length', 'php_sapi_name', 'putenv', 'filter_input',
    'filter_var', 'fastcgi_finish_request', 'move_uploaded_file', 'is_uploaded_file',
    'register_shutdown_function', 'set_error_handler', 'set_exception_handler',
    'trigger_error', 'error_get_last', 'ignore_user_abort', 'connection_status',
    'spl_autoload_register', 'spl_autoload_unregister', 'spl_autoload_functions'];

$superglobals = ['_SERVER', '_GET', '_POST', '_COOKIE', '_FILES', '_REQUEST', '_SESSION', '_ENV'];

/** @var array<string,array<string,int>> fn -> file:line -> count */
$sapiHits = [];
/** @var array<string,array<string,string>> superglobal -> key -> first site */
$sgKeys = [];
/** @var array<string,array<string,string>> class -> member -> first site */
$pdo = [];
/** @var array<string,string> constant -> first site */
$pdoConst = [];
$sqliteHints = [];

foreach ($files as $path) {
    $src = (string)file_get_contents($path);
    $rel = str_replace($app . '/', '', $path);

    foreach ($sapiFns as $fn) {
        if (preg_match_all('/(?<![\w$>:])' . preg_quote($fn, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                if (!isset($sapiHits[$fn])) { $sapiHits[$fn] = []; }
                $sapiHits[$fn][$rel . ':' . $line] = 1;
            }
        }
    }

    foreach ($superglobals as $sg) {
        if (preg_match_all('/\$' . $sg . "\\s*\\[\\s*['\"]([^'\"]+)['\"]/", $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $i => $hit) {
                $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                if (!isset($sgKeys[$sg][$hit[0]])) { $sgKeys[$sg][$hit[0]] = $rel . ':' . $line; }
            }
        }
        // A bare read (`$_SERVER` passed whole) matters just as much: it means
        // the SAPI cannot get away with populating a subset.
        if (preg_match('/\$' . $sg . '(?!\s*\[)/', $src)) {
            $sgKeys[$sg]['<whole array>'] = $sgKeys[$sg]['<whole array>'] ?? $rel;
        }
    }

    if (preg_match_all('/\b(PDO|PDOStatement|PDOException)::([A-Za-z_][A-Za-z0-9_]*)/', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[2] as $i => $hit) {
            $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $cls = $m[1][$i][0];
            $pdoConst[$cls . '::' . $hit[0]] = $pdoConst[$cls . '::' . $hit[0]] ?? ($rel . ':' . $line);
        }
    }
    if (preg_match_all('/\$(?:pdo|stmt|connection|conn|statement)\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        if (str_contains($path, '/dbal/') || str_contains($path, '/orm/')) {
            foreach ($m[1] as $hit) {
                $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                $pdo['method'][$hit[0]] = $pdo['method'][$hit[0]] ?? ($rel . ':' . $line);
            }
        }
    }
    if (preg_match_all('/\bsqlite[a-z_0-9]*\b/i', $src, $m)) {
        if (str_contains($path, '/dbal/')) {
            foreach ($m[0] as $hit) { $sqliteHints[strtolower($hit)] = ($sqliteHints[strtolower($hit)] ?? 0) + 1; }
        }
    }
}

@mkdir('docs/audit', 0777, true);

// ---------------------------------------------------------------- SAPI
$md = "# SAPI requirements\n\n";
$md .= "Derived by `tools/audit/requirements.php` from what symfony-demo actually\n";
$md .= "references. Do not hand-edit — regenerate.\n\n";
$md .= "This is the input list for the SAPI epic. It is what a request-scoped\n";
$md .= "runtime has to provide before `Request::createFromGlobals()` and\n";
$md .= "`Response::send()` mean anything. Note the audit could measure the whole\n";
$md .= "web stack WITHOUT any of it — `Request::create()` reads no superglobals —\n";
$md .= "so this surface is needed to SERVE, not to test.\n\n";

$md .= "## Superglobal keys read\n\n";
foreach ($superglobals as $sg) {
    if (empty($sgKeys[$sg])) { continue; }
    ksort($sgKeys[$sg]);
    $md .= "### `\$$sg` — " . count($sgKeys[$sg]) . " distinct\n\n";
    $md .= "| key | first site |\n|---|---|\n";
    foreach ($sgKeys[$sg] as $k => $at) { $md .= "| `$k` | $at |\n"; }
    $md .= "\n";
}

$md .= "## SAPI functions called\n\n";
$md .= "`present` is measured against this tree's stdlib; see\n";
$md .= "`tests/audit/probes/cap_sapi_fn_presence.php` for the runtime check.\n\n";
$md .= "| function | call sites | first site |\n|---|---|---|\n";
ksort($sapiHits);
foreach ($sapiHits as $fn => $sites) {
    $first = array_key_first($sites);
    $md .= "| `$fn()` | " . count($sites) . " | $first |\n";
}
file_put_contents('docs/audit/SAPI-REQUIREMENTS.md', $md);

// ---------------------------------------------------------------- PDO
$md = "# pdo_sqlite requirements\n\n";
$md .= "Derived by `tools/audit/requirements.php` from doctrine/dbal + doctrine/orm\n";
$md .= "as installed in symfony-demo. Do not hand-edit — regenerate.\n\n";
$md .= "## Two FFI ceilings that shape the design\n\n";
$md .= "1. **No callbacks.** Nothing can take a PHP function's address, so\n";
$md .= "   `sqlite3_exec()` and `sqlite3_create_function()` are unreachable. The\n";
$md .= "   `sqlite3_prepare_v2` / `step` / `column_*` / `finalize` route is the\n";
$md .= "   only viable one — which is what PDO wants anyway.\n";
$md .= "2. **No struct-by-value.** Args and returns are i64/ptr/double/i1 only.\n";
$md .= "   sqlite3 passes handles as opaque pointers, so this does not bite, but\n";
$md .= "   it rules out any C API that returns a struct.\n\n";
$md .= "Also: the extension manifest's `\"static\"` key is never read (Main.php\n";
$md .= "reads only `link`), so an extension links dynamically today — at odds\n";
$md .= "with the fully-static-binary goal, and worth closing in the same epic.\n\n";
$md .= "And `PDO`/`PDOStatement` must NOT live in `src/Runtime`: a compiled\n";
$md .= "library's `.sig` carries functions only, so a class declared there is\n";
$md .= "never registered by a user program. `prelude/resource.php` documents the\n";
$md .= "same trap. Extension glue compiles INTO the application module, so\n";
$md .= "`ext/sqlite3/` is the place classes can safely live.\n\n";

$md .= "## PDO surface referenced\n\n";
ksort($pdoConst);
$md .= "### Class constants and static members — " . count($pdoConst) . "\n\n";
$md .= "| member | first site |\n|---|---|\n";
foreach ($pdoConst as $c => $at) { $md .= "| `$c` | $at |\n"; }
if (!empty($pdo['method'])) {
    ksort($pdo['method']);
    $md .= "\n### Methods called on a connection/statement handle — " . count($pdo['method']) . "\n\n";
    $md .= "Heuristic (receiver named `\$pdo`/`\$stmt`/`\$conn`/…), so treat as a\n";
    $md .= "floor rather than the exact set.\n\n";
    $md .= "| method | first site |\n|---|---|\n";
    foreach ($pdo['method'] as $m => $at) { $md .= "| `$m()` | $at |\n"; }
}
arsort($sqliteHints);
$md .= "\n### sqlite mentions inside dbal — " . count($sqliteHints) . " distinct tokens\n\n";
$n = 0;
$md .= "| token | occurrences |\n|---|---|\n";
foreach ($sqliteHints as $t => $c) { if (++$n > 30) { break; } $md .= "| `$t` | $c |\n"; }
file_put_contents('docs/audit/PDO-SQLITE-REQUIREMENTS.md', $md);

printf("requirements: %d files scanned\n", count($files));
printf("  SAPI: %d functions, %d superglobals with keys -> docs/audit/SAPI-REQUIREMENTS.md\n",
    count($sapiHits), count($sgKeys));
printf("  PDO : %d class members, %d methods -> docs/audit/PDO-SQLITE-REQUIREMENTS.md\n",
    count($pdoConst), count($pdo['method'] ?? []));
