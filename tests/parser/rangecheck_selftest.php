<?php
// Self-test of Parser\RangeCheck on hand-built trees. Run with Zend php by
// tests/parser/run.sh. Exit 0 = every rule fires exactly where it should.
spl_autoload_register(function ($c) {
    $f = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) { require $f; }
});

use Parser\Ast\Expr;
use Parser\Ast\Program;
use Parser\Ast\Span;
use Parser\Ast\Stmt;
use Parser\RangeCheck;

function lit(int $v, int $c1, int $c2): Expr { return Expr::int($v, new Span(1, $c1, 'f', 1, $c2)); }
function bin(Expr $l, Expr $r, int $c1, int $c2): Expr { return Expr::binary('+', $l, $r, new Span(1, $c1, 'f', 1, $c2)); }
function prog(Expr $e): Program { return new Program([Stmt::expression($e, new Span(1, 1, 'f'))]); }

$fail = 0;
function expect(string $name, array $got, array $needles): void
{
    global $fail;
    $ok = count($got) === count($needles);
    foreach ($needles as $i => $n) {
        if (!isset($got[$i]) || !str_contains($got[$i], $n)) { $ok = false; }
    }
    if (!$ok) { $fail++; echo "FAIL $name: ", json_encode($got), "\n"; }
}

// valid: 1 + 2 at cols 1..6
expect('valid', RangeCheck::verify(prog(bin(lit(1, 1, 2), lit(2, 5, 6), 1, 6))), []);

// start > end
expect('start-after-end', RangeCheck::verify(prog(lit(1, 9, 8))), ['f:1:9 end precedes start']);

// child outside parent
expect('child-outside', RangeCheck::verify(prog(bin(lit(1, 1, 2), lit(2, 5, 9), 1, 6))), ['child range 1:5-1:9 outside parent 1:1-1:6']);

// overlapping siblings
expect('overlap', RangeCheck::verify(prog(bin(lit(1, 1, 4), lit(2, 3, 6), 1, 6))), ['sibling range 1:3-1:6 overlaps 1:1-1:4']);

// two REAL siblings sharing one (non-parent) range are reported
expect('shared-real', RangeCheck::verify(prog(bin(lit(1, 2, 5), lit(2, 2, 5), 1, 6))), ['sibling range 1:2-1:5 overlaps 1:2-1:5']);

// both siblings carry exactly the parent's range (interpolated string): allowed
expect('shared-parent', RangeCheck::verify(prog(bin(lit(1, 1, 6), lit(2, 1, 6), 1, 6))), []);

// only ONE sibling with the parent's range, the other nested inside it: still an overlap
expect('one-parent-range', RangeCheck::verify(prog(bin(lit(1, 1, 6), lit(2, 3, 4), 1, 6))), ['sibling range 1:3-1:4 overlaps 1:1-1:6']);

// unset (endLine 0) node is skipped, its descendants checked against the ranged ancestor
$unset = Expr::binary('+', lit(1, 1, 2), lit(2, 40, 41), new Span(1, 1, 'f'));
expect('unset-descends', RangeCheck::verify(new Program([Stmt::expression(bin($unset, lit(3, 8, 9), 1, 10), new Span(1, 1, 'f'))])), ['child range 1:40-1:41 outside parent 1:1-1:10']);

// requireEnds makes unset a violation
expect('require-ends', RangeCheck::verify(prog($unset), true), ['no end position', 'no end position']);

// requireEnds on a fully ended declaration tree is clean; the attribute node
// hanging off two sibling params (`#[A] int $a, $b` style sharing) counts once
function par(string $n, int $c1, int $c2, array $attrs): Parser\Ast\Param
{
    return new Parser\Ast\Param($n, null, null, false, false, '', false, $attrs, new Span(1, $c1, 'f', 1, $c2));
}
$sp = fn(int $c1, int $c2) => new Span(1, $c1, 'f', 1, $c2);
$attr = new Parser\Ast\AttributeNode('A', [], $sp(3, 10));
$decl = new Parser\Ast\FunctionDecl('f', [par('a', 1, 20, [$attr]), par('b', 22, 40, [$attr])], null, null, $sp(1, 41));
expect('decl-require-ends', RangeCheck::verify(new Program([Stmt::function_($decl, $sp(1, 41))]), true), []);
// the same tree with the second param's end unset is reported under requireEnds only
$decl2 = new Parser\Ast\FunctionDecl('f', [par('a', 1, 20, [$attr]), new Parser\Ast\Param('b', null, null, false, false, '', false, [], new Span(1, 22, 'f'))], null, null, $sp(1, 41));
expect('decl-missing-end', RangeCheck::verify(new Program([Stmt::function_($decl2, $sp(1, 41))]), true), ['no end position']);
expect('decl-missing-end-lenient', RangeCheck::verify(new Program([Stmt::function_($decl2, $sp(1, 41))])), []);

// a non-attribute node placed twice under one parent is still an overlap
$twice = par('a', 1, 20, []);
$decl3 = new Parser\Ast\FunctionDecl('f', [$twice, $twice], null, null, $sp(1, 41));
expect('node-twice', RangeCheck::verify(new Program([Stmt::function_($decl3, $sp(1, 41))]), true), ['sibling range 1:1-1:20 overlaps 1:1-1:20']);

exit($fail === 0 ? 0 : 1);
