<?php

require __DIR__ . '/bootstrap.php';

use Analyze\Analyzer;
use Analyze\ParsedFile;
use Semantic\LibrarySymbols;
use Semantic\Workspace;

$fail = 0;
function check(string $name, callable $fn): void
{
    global $fail;
    try {
        $r = $fn();
        if ($r === true) { echo "PASS $name\n"; return; }
        echo "FAIL $name: " . (is_string($r) ? $r : 'false') . "\n";
    } catch (\Throwable $e) {
        echo "FAIL $name: " . get_class($e) . ' ' . $e->getMessage() . "\n";
    }
    $fail = 1;
}

function pf(string $path, string $src): ParsedFile
{
    return new ParsedFile($path, \Parser\Parser::parseTolerant($src, $path)->program);
}

function libP(): LibrarySymbols
{
    return LibrarySymbols::fromParts([
        pf('lib:prelude/x.php', '<?php class LibClass { function only_prelude() {} } function libfn(int $a) {}'),
    ], []);
}

/** @param ParsedFile[] $files @return string[] */
function viaRun(array $files, string $report): array
{
    $l = libP();
    $o = [];
    foreach ((new Analyzer())->run($files, $l->preludeFiles(), true, $l->stdlibFunctionNames()) as $d) {
        if ($d->file === $report) { $o[] = "{$d->line}:{$d->code}"; }
    }
    return $o;
}

/** @param array<string, string> $texts @return string[] */
function viaWs(array $texts, string $report): array
{
    $w = new Workspace(libP());
    foreach ($texts as $u => $t) { $w->open($u, $t); }
    $o = [];
    foreach ($w->diagnostics($report) as $d) { $o[] = "{$d->line}:{$d->code}"; }
    return $o;
}

check('user_redeclaring_prelude_class_prelude_wins_in_run_and_workspace', function () {
    $t = ['u.php' => "<?php\nclass LibClass { function only_user() {} }\n\$o = new LibClass();\n\$o->only_prelude();\n\$o->only_user();\n"];
    $a = viaRun([pf('u.php', $t['u.php'])], 'u.php');
    $b = viaWs($t, 'u.php');
    return ($a === $b && $a === ['5:undefined.method']) ?: json_encode([$a, $b]);
});

check('user_redeclaring_prelude_function_prelude_wins_in_run_and_workspace', function () {
    $t = ['u.php' => "<?php\nfunction libfn() {}\nlibfn(1);\nlibfn();\n"];
    $a = viaRun([pf('u.php', $t['u.php'])], 'u.php');
    $b = viaWs($t, 'u.php');
    return ($a === $b && $a === ['4:arg.count']) ?: json_encode([$a, $b]);
});

check('user_vs_user_duplicate_legacy_last_wins_in_run', function () {
    $a = pf('a.php', "<?php\nfunction d(int \$x) {}\n");
    $b = pf('b.php', "<?php\nfunction d() {}\nd(1);\n");
    $got = viaRun([$a, $b], 'b.php');
    return $got === ['3:arg.count'] ?: json_encode($got);
});

exit($fail);
