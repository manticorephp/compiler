<?php

// Object comparison across operand representations: a plain `obj<C>`, a
// `?C`, a static object UNION (a field read off a base class whose subclasses
// declare it with different classes) and a boxed CELL, under every operator —
// `===`/`!==`, `==`/`!=`, `<=>`/`<`/`>`, `match` (strict) and `switch` (loose).
// Rows: the same instance, distinct instances with equal properties, distinct
// instances with different properties, a different class, and null.

final class Decl { public function __construct(public string $name, public int $n = 0) {} }
final class FDecl { public function __construct(public string $name) {} }
abstract class Stmt { public function __construct(public readonly string $kind) {} }
final class ClassStmt extends Stmt { public function __construct(public readonly Decl $decl) { parent::__construct('Class'); } }
final class FnStmt extends Stmt { public function __construct(public readonly FDecl $decl) { parent::__construct('Function'); } }
final class Holder { public Decl|FDecl|null $p = null; public ?Decl $q = null; }
final class Node2 { public ?Node2 $next = null; public function __construct(public int $v) {} }

final class L {
    /** @var array<string, Decl> */
    private array $classDecls = [];
    /** @var array<string, Decl> */
    private array $traitTable = [];
    public function erase(mixed $d): void { $this->traitTable['t'] = $d; }
    public function reg(Decl $d): void { $this->classDecls[$d->name] = $d; }
    /** @param Stmt[] $stmts */
    public function probe(array $stmts, string $label): void
    {
        foreach ($stmts as $b) {
            if ($b->kind !== 'Class') { continue; }
            $cdecl = $b->decl;
            $reg = $this->classDecls[$cdecl->name] ?? ($this->traitTable[$cdecl->name] ?? null);
            echo $label, ' strict: ';
            var_dump($reg === null || $reg === $cdecl, $cdecl === $reg, $reg !== $cdecl);
            echo $label, ' loose: ';
            var_dump($reg == $cdecl, $cdecl == $reg, $reg != $cdecl, $cdecl != $reg);
            echo $label, ' order: ', $reg <=> $cdecl, ' ', $cdecl <=> $reg, ' ';
            var_dump($reg < $cdecl, $cdecl > $reg, $reg <= $cdecl);
            echo $label, ' match: ', match ($cdecl) { $reg => 'hit', default => 'miss' }, ' ',
                match ($reg) { $cdecl => 'hit', default => 'miss' }, "\n";
            $s1 = 'miss';
            switch ($cdecl) { case $reg: $s1 = 'hit'; break; }
            $s2 = 'miss';
            switch ($reg) { case $cdecl: $s2 = 'hit'; break; }
            echo $label, ' switch: ', $s1, ' ', $s2, "\n";
            $h = new Holder();
            $h->p = $cdecl;
            echo $label, ' holder: ';
            var_dump($h->p == $reg, $reg == $h->p, $h->p === $reg, $h->p <=> $reg);
        }
    }
}

$l = new L();
$d = new Decl('X', 1);
$l->erase(new Decl('t'));
$st = [new FnStmt(new FDecl('f')), new ClassStmt($d)];
$l->probe($st, 'unregistered');
$l->reg($d);
$l->probe($st, 'same');
$l->probe([new ClassStmt(new Decl('X', 1))], 'equal');
$l->probe([new ClassStmt(new Decl('X', 2))], 'differs');

// Plain obj<Decl> / ?Decl / union, no cell.
function plain(Decl $a, ?Decl $b, Stmt $s): void
{
    $u = $s->decl;
    var_dump($a == $b, $a != $b, $a === $b, $a <=> $b, $b <=> $a);
    var_dump($a == $u, $u == $a, $a <=> $u, $u != $a);
    echo match ($a) { $b => 'hit', default => 'miss' }, ' ';
    $sw = 'miss';
    switch ($a) { case $u: $sw = 'hit'; break; }
    echo $sw, "\n";
}
plain(new Decl('A', 1), new Decl('A', 1), new ClassStmt(new Decl('A', 1)));
plain(new Decl('A', 1), new Decl('A', 3), new ClassStmt(new Decl('B', 1)));
plain(new Decl('A', 1), null, new FnStmt(new FDecl('A')));
$same = new Decl('S', 9);
plain($same, $same, new ClassStmt($same));

// Ordering follows the properties in declaration order.
var_dump(new Decl('a', 5) <=> new Decl('b', 1), new Decl('b', 1) <=> new Decl('a', 5), new Decl('a', 1) < new Decl('a', 2));

// Null against an object-ish operand.
$hq = new Holder();
var_dump($hq->q == null, $hq->p == null, $hq->q <=> null);
$hq->q = new Decl('n');
var_dump($hq->q == null, $hq->q != null);

// Nested objects compare recursively.
$x = new Node2(1); $x->next = new Node2(2);
$y = new Node2(1); $y->next = new Node2(2);
$z = new Node2(1); $z->next = new Node2(3);
var_dump($x == $y, $x == $z, $x <=> $z);
