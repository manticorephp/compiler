<?php
// T5: subclass-only field read through a base-typed receiver. A mixed
// array of ClassStmt|FuncStmt types as obj<Stmt> (common base), so
// `$stmt->decl` must resolve the subclass field offset, not slot 0/16.
abstract class Stmt { public function __construct(public string $kind) {} }
class ClassDecl { public function __construct(public string $name, public int $line) {} }
class FuncDecl  { public function __construct(public string $fname) {} }
class ClassStmt extends Stmt { public function __construct(public ClassDecl $decl) { parent::__construct('Class'); } }
class FuncStmt  extends Stmt { public function __construct(public FuncDecl  $decl) { parent::__construct('Func'); } }

$stmts = [new ClassStmt(new ClassDecl("Foo", 10)), new FuncStmt(new FuncDecl("bar")), new ClassStmt(new ClassDecl("Baz", 20))];
foreach ($stmts as $stmt) {
    if ($stmt->kind === 'Class') {
        $decl = $stmt->decl;
        echo $decl->name, ":", $decl->line, "\n";
    }
}
