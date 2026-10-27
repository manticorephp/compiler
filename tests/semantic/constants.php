<?php

require __DIR__ . '/bootstrap.php';

use Analyze\ParsedFile;
use Semantic\LibrarySymbols;
use Semantic\Location;
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

function ws(): Workspace
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    return new Workspace(LibrarySymbols::fromParts([$pf], ['strlen']));
}

function at(array $srcs, string $uri, int $line, int $col): ?Location
{
    $w = ws();
    foreach ($srcs as $u => $t) { $w->open($u, $t); }
    return $w->definitionOf($uri, $line, $col);
}

function slice(string $src, ?Location $l): string
{
    if ($l === null) { return '(null)'; }
    return substr(explode("\n", $src)[$l->line - 1] ?? '', $l->col - 1, $l->endCol - $l->col);
}

/** @param Location[] $ls @return string[] */
function locs(array $ls): array
{
    $o = array_map(fn($l) => $l->file . ':' . $l->line, $ls);
    sort($o);
    return $o;
}

check('const_declared_in_namespace_resolves', function () {
    $a = "<?php\nnamespace N;\nconst FOO = 1;\n";
    $l = at(['a.php' => $a, 'b.php' => "<?php\nnamespace N;\necho FOO;\n", 'c.php' => "<?php\nnamespace M;\necho FOO;\n"], 'b.php', 3, 6);
    $m = at(['a.php' => $a, 'c.php' => "<?php\nnamespace M;\necho FOO;\n"], 'c.php', 3, 6);
    return ($l !== null && $l->file === 'a.php' && $l->line === 3 && $m === null) ?: json_encode([$l, $m]);
});

check('define_call_resolves_global', function () {
    $a = "<?php\ndefine('BAR', 2);\n";
    $l = at(['a.php' => $a, 'b.php' => "<?php\necho BAR;\n"], 'b.php', 2, 6);
    return ($l !== null && $l->file === 'a.php' && $l->line === 2) ?: json_encode($l);
});

check('define_inside_namespace_stays_global', function () {
    $a = "<?php\nnamespace N;\ndefine('BAR', 2);\n";
    $l = at(['a.php' => $a, 'b.php' => "<?php\necho BAR;\n"], 'b.php', 2, 6);
    $q = at(['a.php' => "<?php\nnamespace N;\ndefine('M\\\\BAZ', 2);\n", 'b.php' => "<?php\nnamespace M;\necho BAZ;\n"], 'b.php', 3, 6);
    return ($l !== null && $l->file === 'a.php' && $q !== null) ?: json_encode([$l, $q]);
});

check('namespaced_use_of_global_const_falls_back', function () {
    $l = at(['a.php' => "<?php\nconst G = 1;\n", 'b.php' => "<?php\nnamespace N\\Deep;\necho G;\n"], 'b.php', 3, 6);
    return ($l !== null && $l->file === 'a.php' && $l->line === 2) ?: json_encode($l);
});

check('case_sensitive', function () {
    $a = "<?php\nconst FOO = 1;\n";
    $upper = at(['a.php' => $a, 'b.php' => "<?php\necho FOO;\n"], 'b.php', 2, 6);
    $lower = at(['a.php' => $a, 'b.php' => "<?php\necho foo;\n"], 'b.php', 2, 6);
    return ($upper !== null && $lower === null) ?: json_encode([$upper, $lower]);
});

check('removed_file_forgets_const', function () {
    $w = ws();
    $w->open('a.php', "<?php\nconst FOO = 1;\n");
    $w->open('b.php', "<?php\necho FOO;\n");
    if ($w->definitionOf('b.php', 2, 6) === null) { return 'not declared'; }
    $w->remove('a.php');
    return $w->definitionOf('b.php', 2, 6) === null ?: 'still declared';
});

check('replace_drops_const_no_longer_declared', function () {
    $w = ws();
    $w->open('a.php', "<?php\nconst FOO = 1;\nconst BAR = 2;\n");
    $w->open('b.php', "<?php\necho FOO; echo BAR;\n");
    $w->update('a.php', "<?php\nconst FOO = 1;\n");
    $foo = $w->definitionOf('b.php', 2, 6);
    $bar = $w->definitionOf('b.php', 2, 16);
    return ($foo !== null && $bar === null) ?: json_encode([$foo, $bar]);
});

check('broken_file_keeps_sticky_const', function () {
    $w = ws();
    $w->open('a.php', "<?php\nconst FOO = 1;\nconst BAR = 2;\n");
    $w->open('b.php', "<?php\necho BAR;\n");
    $w->update('a.php', "<?php\nconst FOO = 1;\nclass {\n");
    if ($w->definitionOf('b.php', 2, 6) === null) { return 'sticky lost'; }
    $w->update('a.php', "<?php\nconst FOO = 1;\n");
    return $w->definitionOf('b.php', 2, 6) === null ?: 'clean parse kept it';
});

check('duplicate_const_first_declarer_wins_and_promotes', function () {
    $w = ws();
    $w->open('a.php', "<?php\nconst X = 1;\n");
    $w->open('b.php', "<?php\nconst X = 2;\n");
    $w->open('c.php', "<?php\necho X;\n");
    $first = $w->definitionOf('c.php', 2, 6);
    $w->update('a.php', "<?php\n\nconst X = 1;\n");
    $keeps = $w->definitionOf('c.php', 2, 6);
    $w->remove('a.php');
    $promoted = $w->definitionOf('c.php', 2, 6);
    return ($first->file === 'a.php' && $keeps->file === 'a.php' && $keeps->line === 3 && $promoted->file === 'b.php')
        ?: json_encode([$first, $keeps, $promoted]);
});

check('definition_range_is_the_name_literal', function () {
    $a = "<?php\nnamespace N;\nconst FOO = 1;\ndefine('BAR', 2);\n";
    $b = "<?php\nnamespace N;\necho FOO, BAR;\n";
    $w = ws();
    $w->open('a.php', $a);
    $w->open('b.php', $b);
    $f = slice($a, $w->definitionOf('b.php', 3, 6));
    $r = slice($a, $w->definitionOf('b.php', 3, 12));
    $self = slice($a, $w->definitionOf('a.php', 3, 8));
    return ($f === 'FOO' && $r === "'BAR'" && $self === 'FOO') ?: json_encode([$f, $r, $self]);
});

check('references_include_const_declaration_site', function () {
    $w = ws();
    $w->open('a.php', "<?php\nnamespace N;\nconst FOO = 1;\necho FOO;\n");
    $w->open('b.php', "<?php\nnamespace N;\nuse const N\\FOO;\necho FOO;\necho \\N\\FOO;\n");
    $w->open('c.php', "<?php\nnamespace M;\necho FOO;\n");
    $want = ['a.php:3', 'a.php:4', 'b.php:3', 'b.php:4', 'b.php:5'];
    $fromDecl = locs($w->referencesTo('a.php', 3, 8));
    $fromUse = locs($w->referencesTo('a.php', 4, 6));
    return ($fromDecl === $want && $fromUse === $want) ?: json_encode([$fromDecl, $fromUse]);
});

check('references_distinguish_case_and_declarations', function () {
    $w = ws();
    $w->open('a.php', "<?php\nconst FOO = 1;\nconst foo = 2;\necho FOO; echo foo;\n");
    $f = locs($w->referencesTo('a.php', 2, 8));
    $l = locs($w->referencesTo('a.php', 3, 8));
    return ($f === ['a.php:2', 'a.php:4'] && $l === ['a.php:3', 'a.php:4']) ?: json_encode([$f, $l]);
});

check('block_namespace_form', function () {
    $w = ws();
    $w->open('a.php', "<?php\nnamespace N {\n    const FOO = 1;\n}\nnamespace {\n    const G = 2;\n}\n");
    $w->open('b.php', "<?php\nnamespace N;\necho FOO, G;\n");
    $w->open('c.php', "<?php\nnamespace M;\necho FOO, G;\n");
    $foo = $w->definitionOf('b.php', 3, 6);
    $g = $w->definitionOf('b.php', 3, 11);
    $mFoo = $w->definitionOf('c.php', 3, 6);
    $mG = $w->definitionOf('c.php', 3, 11);
    return ($foo !== null && $foo->line === 3 && $g !== null && $g->line === 6 && $mFoo === null && $mG !== null)
        ?: json_encode([$foo, $g, $mFoo, $mG]);
});

check('conditional_define_inside_if_not_defined', function () {
    $a = "<?php\nif (!defined('X')) {\n    define('X', 1);\n}\n";
    $l = at(['a.php' => $a, 'b.php' => "<?php\necho X;\n"], 'b.php', 2, 6);
    return ($l !== null && $l->file === 'a.php' && $l->line === 3) ?: json_encode($l);
});

exit($fail);
