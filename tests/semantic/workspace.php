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

// Minimal real prelude: the interfaces and exception classes the fixtures can name.
function prelude(): array
{
    $out = [];
    foreach (['core_interfaces.php', 'exceptions.php'] as $f) {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/' . $f);
        $out[] = new ParsedFile('prelude/' . $f, \Parser\Parser::parseTolerant($src, $f)->program);
    }
    return $out;
}

function lib(): LibrarySymbols
{
    return LibrarySymbols::fromParts(prelude(), ['strlen', 'count']);
}

function new_ws(string $a, string $b): Workspace
{
    $w = new Workspace(lib());
    $w->open('a.php', $a);
    $w->open('b.php', $b);
    return $w;
}

/** @return string[] */
function dg(Workspace $w, string $uri): array
{
    $o = [];
    foreach ($w->diagnostics($uri) as $d) { $o[] = "{$d->line}:{$d->col}:{$d->code}"; }
    return $o;
}

$A = "<?php\nnamespace N;\nclass Foo {}\nfunction helper(int \$x): int { return \$x; }\n";
$B = "<?php\nnamespace N;\nfunction use1() {\n    \$a = new Foo();\n    helper(1);\n    return new Missing();\n}\n";

check('empty_prelude_throws', function () {
    try { new Workspace(LibrarySymbols::fromParts([], [])); } catch (\RuntimeException $e) { return true; }
    return 'no throw';
});

check('diagnostics_match_batch_analyzer_on_fixture', function () use ($A, $B) {
    $w = new_ws($A, $B);
    $files = [];
    foreach (['a.php' => $A, 'b.php' => $B] as $p => $s) {
        $files[] = new ParsedFile($p, \Parser\Parser::parseTolerant($s, $p)->program);
    }
    $l = lib();
    $an = new Analyzer();
    $want = [];
    foreach ($an->run($files, $l->preludeFiles(), true, $l->stdlibFunctionNames()) as $d) {
        if ($d->file === 'b.php') { $want[] = "{$d->line}:{$d->col}:{$d->code}"; }
    }
    $got = dg($w, 'b.php');
    if ($got === [] || $want === []) { return 'fixture must produce a diagnostic: ' . json_encode([$got, $want]); }
    if ($got !== $want) { return json_encode([$got, $want]); }
    return dg($w, 'a.php') === [] ?: 'a.php must be clean';
});

check('undefined_class_has_exact_position', function () use ($A, $B) {
    $d = new_ws($A, $B)->diagnostics('b.php');
    return (count($d) === 1 && $d[0]->line === 6 && $d[0]->code === 'undefined.class' && str_contains($d[0]->message, 'Missing'))
        ?: json_encode(array_map(fn($x) => [$x->line, $x->col, $x->code, $x->message], $d));
});

check('update_into_syntax_error_then_back', function () use ($A, $B) {
    $w = new_ws($A, $B);
    $clean = dg($w, 'b.php');
    $w->update('a.php', "<?php\nnamespace N;\nclass Foo { function ( }\n");
    $da = $w->diagnostics('a.php');
    if (count($da) === 0) { return 'no parse errors'; }
    foreach ($da as $d) { if ($d->code !== 'parse.error') { return 'non-parse diag ' . $d->code; } }
    $db = dg($w, 'b.php');
    if (count($db) !== 3) { return 'stale or lost declarations: ' . json_encode($db); }
    if ($w->definitionOf('b.php', 4, 15) !== null) { return 'stale definition'; }
    $w->update('a.php', $A);
    return ($clean === dg($w, 'b.php') && $w->definitionOf('b.php', 4, 15) !== null && dg($w, 'a.php') === [])
        ?: 'not restored ' . json_encode(dg($w, 'b.php'));
});

check('syntax_error_keeps_other_files_declarations', function () use ($A, $B) {
    $w = new_ws($A, $B);
    $w->update('b.php', "<?php\nnamespace N;\nfunction use1( {\n");
    $w->open('c.php', "<?php\nnamespace N;\n\$x = new Foo();\n");
    return dg($w, 'c.php') === [] ?: 'a.php decls dropped: ' . json_encode(dg($w, 'c.php'));
});

check('remove_file_makes_dependents_undefined', function () use ($A, $B) {
    $w = new_ws($A, $B);
    if (count($w->diagnostics('b.php')) !== 1) { return 'precondition'; }
    $w->remove('a.php');
    $codes = array_map(fn($d) => $d->line . ':' . $d->code, $w->diagnostics('b.php'));
    sort($codes);
    return $codes === ['4:undefined.class', '5:undefined.function', '6:undefined.class'] ?: json_encode($codes);
});

check('bom_crlf_multibyte_columns_are_bytes', function () {
    $w = new Workspace(lib());
    $line3 = "function f() { \$s = 'é'; return new Nope(); }";
    $w->open('m.php', "\xEF\xBB\xBF<?php\r\nnamespace N;\r\n$line3\r\n");
    $d = $w->diagnostics('m.php');
    $col = strpos($line3, 'new Nope') + 1;
    return (count($d) === 1 && $d[0]->line === 3 && $d[0]->col === $col)
        ?: json_encode(array_map(fn($x) => [$x->line, $x->col, $x->code], $d)) . " want col $col";
});

check('definition_of_class_across_files', function () use ($A, $B) {
    $w = new_ws($A, $B);
    $loc = $w->definitionOf('b.php', 4, 15);
    $fn = $w->definitionOf('b.php', 5, 5);
    return ($loc !== null && $loc->file === 'a.php' && $loc->line === 3
        && $fn !== null && $fn->file === 'a.php' && $fn->line === 4
        && $w->definitionOf('b.php', 1, 1) === null) ?: json_encode([$loc, $fn]);
});

check('references_exclude_other_symbols', function () {
    $w = new Workspace(lib());
    $w->open('a.php', "<?php\nnamespace N;\nclass Foo {}\nclass Bar {}\n");
    $w->open('b.php', "<?php\nnamespace N;\n\$x = new Foo();\n\$y = new Bar();\n\$z = new Foo();\n");
    $lines = array_map(fn($l) => $l->file . ':' . $l->line, $w->referencesTo('b.php', 3, 12));
    return ($lines === ['b.php:3', 'b.php:5']) ?: json_encode($lines);
});

check('closed_file_keeps_index_but_not_ast', function () use ($A, $B) {
    $w = new_ws($A, $B);
    if ($w->openFileCount() !== 2) { return 'open count'; }
    $w->close('a.php');
    if ($w->openFileCount() !== 1) { return 'close did not drop AST'; }
    if (count($w->diagnostics('b.php')) !== 1) { return 'closed decls lost'; }
    $loc = $w->definitionOf('b.php', 4, 15);
    if ($loc === null || $loc->file !== 'a.php') { return 'definition lost'; }
    $w->remove('a.php');
    return count($w->diagnostics('b.php')) === 3 ?: 'remove kept decls';
});

exit($fail);
