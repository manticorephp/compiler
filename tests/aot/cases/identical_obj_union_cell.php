<?php

// `===` between a static object UNION (a field read off a base class whose
// subclasses declare it with different classes) and a boxed cell compared the
// raw pointer against the NaN-boxed word: never identical.
final class Decl { public function __construct(public string $name) {} }
final class FDecl { public function __construct(public string $name) {} }
abstract class Stmt { public function __construct(public readonly string $kind) {} }
final class ClassStmt extends Stmt { public function __construct(public readonly Decl $decl) { parent::__construct('Class'); } }
final class FnStmt extends Stmt { public function __construct(public readonly FDecl $decl) { parent::__construct('Function'); } }
final class L {
    /** @var array<string, Decl> */
    private array $classDecls = [];
    /** @var array<string, Decl> */
    private array $traitTable = [];
    public function erase(mixed $d): void { $this->traitTable['t'] = $d; }
    public function reg(Decl $d): void { $this->classDecls[$d->name] = $d; }
    /** @param Stmt[] $stmts */
    public function same(array $stmts): void
    {
        foreach ($stmts as $b) {
            if ($b->kind !== 'Class') { continue; }
            $cdecl = $b->decl;
            $reg = $this->classDecls[$cdecl->name] ?? ($this->traitTable[$cdecl->name] ?? null);
            var_dump($reg === null || $reg === $cdecl, $cdecl === $reg, $reg !== $cdecl);
        }
    }
}
$l = new L();
$d = new Decl('X');
$l->erase(new Decl('t'));
$st = [new FnStmt(new FDecl('f')), new ClassStmt($d)];
$l->same($st);
$l->reg($d);
$l->same($st);
$l->same([new ClassStmt(new Decl('X'))]);
