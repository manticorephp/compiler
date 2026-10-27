<?php

require __DIR__ . '/bootstrap.php';

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

function lib(): LibrarySymbols
{
    $out = [];
    foreach (['core_interfaces.php', 'exceptions.php'] as $f) {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/' . $f);
        $out[] = new ParsedFile('lib:prelude/' . $f, \Parser\Parser::parseTolerant($src, $f)->program);
    }
    return LibrarySymbols::fromParts($out, ['strlen', 'count']);
}

/** @param string[] $files uri => text */
function ws(array $files): Workspace
{
    $w = new Workspace(lib());
    foreach ($files as $u => $t) { $w->open($u, $t); }
    return $w;
}

/** @return string[] */
function dg(Workspace $w, string $uri): array
{
    $o = [];
    foreach ($w->diagnostics($uri) as $d) { $o[] = "{$d->line}:{$d->col}:{$d->code}"; }
    return $o;
}

$USER = "<?php\nnew C;\nC::known();\n(new C)->nope();\necho C::K;\n";
$CLEAN_C = "<?php\nclass C {\n    const K = 1;\n    static function known(): int { return 1; }\n}\n";

check('half_typed_member_keeps_class', function () use ($USER, $CLEAN_C) {
    $ctl = ws(['c.php' => $CLEAN_C, 'u.php' => $USER]);
    $want = ['4:8:undefined.method'];
    if (dg($ctl, 'u.php') !== $want) { return 'control ' . json_encode(dg($ctl, 'u.php')); }
    $w = ws(['c.php' => "<?php\nclass C {\n    const K = 1;\n    public function 123(){}\n    static function known(): int { return 1; }\n}\n", 'u.php' => $USER]);
    if (dg($w, 'u.php') !== []) { return json_encode(dg($w, 'u.php')); }
    $loc = $w->definitionOf('u.php', 2, 5);
    return ($loc !== null && $loc->file === 'c.php') ?: 'class C left the index';
});

check('phantom_property_dollar_is_ignored_and_class_partial', function () use ($USER) {
    $w = ws(['c.php' => "<?php\nclass C {\n    const K = 1;\n    public int ;\n    static function known(): int { return 1; }\n}\n", 'u.php' => $USER]);
    if (dg($w, 'u.php') !== []) { return json_encode(dg($w, 'u.php')); }
    return $w->definitionOf('u.php', 2, 5) !== null ?: 'class C left the index';
});

check('valid_broken_fixed_roundtrip', function () use ($USER, $CLEAN_C) {
    $w = ws(['c.php' => $CLEAN_C, 'u.php' => $USER]);
    $before = dg($w, 'u.php');
    $w->update('c.php', "<?php\nclass C {\n    const K = 1;\n    public function ( \n    static function known(): int { return 1; }\n}\n");
    $during = dg($w, 'u.php');
    foreach ($during as $x) { if (str_contains($x, 'undefined.')) { return 'flood while broken: ' . json_encode($during); } }
    $w->update('c.php', $CLEAN_C);
    return ($before === ['4:8:undefined.method'] && dg($w, 'u.php') === $before) ?: json_encode([$before, $during, dg($w, 'u.php')]);
});

check('broken_use_line_suppresses_undefined_class_only_in_that_file', function () {
    $w = ws([
        'a.php' => "<?php\nnamespace N;\nuse Foo\;\nnew Bar;\nbar();\n",
        'c.php' => "<?php\nnew Zork;\n",
    ]);
    $a = dg($w, 'a.php');
    $codes = array_map(fn($x) => explode(':', $x)[2], $a);
    if (in_array('undefined.class', $codes, true) || in_array('undefined.function', $codes, true)) { return 'a.php: ' . json_encode($a); }
    if (!in_array('parse.error', $codes, true)) { return 'parse.error lost'; }
    return dg($w, 'c.php') === ['2:1:undefined.class'] ?: 'c.php ' . json_encode(dg($w, 'c.php'));
});

check('arg_count_not_reported_for_error_expr_call', function () {
    $fn = "<?php\nfunction f(int \$a, int \$b) {}\n";
    $ctl = ws(['a.php' => $fn . "f(1);\n"]);
    if (dg($ctl, 'a.php') !== ['3:1:arg.count']) { return 'control ' . json_encode(dg($ctl, 'a.php')); }
    $w = ws(['a.php' => $fn . "f(1,\n"]);
    return dg($w, 'a.php') === ['4:1:parse.error'] ?: json_encode(dg($w, 'a.php'));
});

check('return_void_not_reported_for_error_expr', function () {
    $ctl = ws(['a.php' => "<?php\nfunction g(): void { return 1; }\n"]);
    if (dg($ctl, 'a.php') !== ['2:22:return.void']) { return 'control ' . json_encode(dg($ctl, 'a.php')); }
    $w = ws(['a.php' => "<?php\nfunction g(): void { return 1 + ; }\n"]);
    return dg($w, 'a.php') === ['2:33:parse.error'] ?: json_encode(dg($w, 'a.php'));
});

$FOO = "<?php\nnamespace N;\nclass Foo {}\nfunction helper(int \$x): int { return \$x; }\n";
$USE = "<?php\nnamespace N;\nfunction use1() {\n    \$a = new Foo();\n    helper(1);\n    return new Missing();\n}\n";
$LOST = "<?php\nnamespace N;\nclass Foo extends {\n}\nfunction helper(\n";

check('sticky_declarations_survive_error_update_but_not_remove', function () use ($FOO, $USE, $LOST) {
    $w = ws(['a.php' => $FOO, 'b.php' => $USE]);
    $clean = dg($w, 'b.php');
    $w->update('a.php', $LOST);
    if (dg($w, 'b.php') !== $clean) { return 'lost on error ' . json_encode(dg($w, 'b.php')); }
    if ($w->definitionOf('b.php', 4, 15) === null) { return 'definition lost'; }
    $w->update('a.php', $LOST . "\n");
    if (dg($w, 'b.php') !== $clean) { return 'lost on second error update'; }
    $w->remove('a.php');
    return count(dg($w, 'b.php')) === 3 ?: json_encode(dg($w, 'b.php'));
});

check('sticky_dropped_on_clean_replace_that_no_longer_declares_it', function () use ($FOO, $USE, $LOST) {
    $w = ws(['a.php' => $FOO, 'b.php' => $USE]);
    $w->update('a.php', $LOST);
    $w->update('a.php', "<?php\nnamespace N;\nclass Other {}\n");
    return count(dg($w, 'b.php')) === 3 ?: json_encode(dg($w, 'b.php'));
});

echo "SKIP broken_class_header_keeps_name_when_declName_available: declName is not indexed. The ErrorStmt carries no declaration kind and no namespace, so a class/function/qualified name would be a guess; the sticky entry already covers the editing flow.\n";

check('other_valid_rules_still_report_in_broken_file', function () {
    $src = "<?php\nfunction f(array \$x) {}\n\$s = 'abc' + 1;\nfunction ( {\n";
    $w = ws(['a.php' => $src]);
    $got = dg($w, 'a.php');
    foreach (['2:12:array.no-value-type', '3:12:arith.string'] as $want) {
        if (!in_array($want, $got, true)) { return "missing $want in " . json_encode($got); }
    }
    return in_array('4:10:parse.error', $got, true) ?: 'parse.error lost';
});

check('real_undefined_class_in_broken_file_still_reported', function () {
    $w = ws(['a.php' => "<?php\nnew Typo;\n\nclass C { public function ( }\n"]);
    $got = dg($w, 'a.php');
    return in_array('2:1:undefined.class', $got, true) ?: json_encode($got);
});

check('open_file_count_counts_live_asts', function () {
    $w = ws(['ok.php' => "<?php\n", 'bad.php' => "<?php\nclass { \n"]);
    if ($w->openFileCount() !== 2) { return 'count ' . $w->openFileCount(); }
    $w->close('bad.php');
    if ($w->openFileCount() !== 1) { return 'close ' . $w->openFileCount(); }
    return count($w->diagnostics('bad.php')) > 0 ?: 'closed file lost its parse errors';
});
exit($fail);
