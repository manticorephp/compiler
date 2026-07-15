<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\BitOp;
use Compile\Mir\BitNot_;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayElement_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Spread_;
use Compile\Mir\Block;
use Compile\Mir\ClassDef;
use Compile\Mir\EnumDef;
use Compile\Mir\BoolConst;
use Compile\Mir\Break_;
use Compile\Mir\Call;
use Compile\Mir\Walk;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Continue_;
use Compile\Mir\Goto_;
use Compile\Mir\Label_;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
use Compile\Mir\FunctionDef;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Throw_;
use Compile\Mir\Yield_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\MethodCall_;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\NullConst;
use Compile\Mir\Param;
use Compile\Mir\Pass;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\StringConst;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\While_;
use Parser\Ast\Program;

/**
 * Statements → MIR nodes.
 *
 * A trait on the one {@see LowerFromAst} host — split by concern so a reader opens
 * the file for the thing they are looking at. State stays on the host.
 */
trait LowerStmts
{
    private function lowerTryCatch(\Parser\Ast\TryCatchStmt $stmt): Node
    {
        $tryBody = [];
        foreach ($stmt->try->statements as $s) { $tryBody[] = $this->lowerStmt($s); }
        $catches = [];
        foreach ($stmt->catches as $c) {
            $types = [];
            foreach ($c->types as $t) { $types[] = \ltrim($t, '\\'); }
            $body = [];
            foreach ($c->body->statements as $s) { $body[] = $this->lowerStmt($s); }
            $catches[] = new MirCatch($types, $c->name, $body);
        }
        $finallyBody = [];
        $hasFinally = $stmt->finally !== null;
        if ($hasFinally) {
            foreach ($stmt->finally->statements as $s) { $finallyBody[] = $this->lowerStmt($s); }
        }
        return new TryCatch_($tryBody, $catches, $finallyBody, $hasFinally, Type::void());
    }

    private function lowerBlockNode(\Parser\Ast\Block $block): Block
    {
        $stmts = [];
        foreach ($block->statements as $stmt) {
            $stmts[] = $this->lowerStmt($stmt);
        }
        return new Block($stmts, Type::void());
    }

    private function lowerStmt(\Parser\Ast\Stmt $stmt): Node
    {
        // Callable const-propagation is straight-line only: forget every
        // tracked variable across a control-flow boundary (a branch / loop may
        // rebind it on a path this linear scan can't see).
        $k = $stmt->kind;
        $cf = $k === 'If' || $k === 'While' || $k === 'For' || $k === 'DoWhile'
            || $k === 'Switch' || $k === 'Foreach' || $k === 'TryCatch';
        if ($cf) { $this->constCallables = []; }
        // Isolate `#[RefOut]` auto-viv inits produced WHILE lowering THIS
        // statement (a nested statement saves/restores its own, so an init from
        // an `if (preg_match(…,$m))` condition flushes at the `if`, not inside
        // its body). Flush them right before the statement that uses them.
        $savedInits = $this->pendingCallInits;
        $this->pendingCallInits = [];
        $node = $this->lowerStmtInner($stmt);
        $myInits = $this->pendingCallInits;
        $this->pendingCallInits = $savedInits;
        if ($cf) { $this->constCallables = []; }
        if ($node->line === 0) { $node->line = $stmt->span->line; }
        if ($myInits !== []) {
            $myInits[] = $node;
            return new Block($myInits, Type::void());
        }
        return $node;
    }

    private function lowerStmtInner(\Parser\Ast\Stmt $stmt): Node
    {
        if ($stmt->kind === 'Expression') {
            $lowered = $this->lowerExpr($stmt->expr);
            // Inline `/** @var T $x */` on a local binding seeds the slot type
            // (a per-declaration annotation InferTypes honors — element types a
            // bare `array` local can't carry, e.g. `@var array<string, Type>`).
            if ($stmt->docComment !== null && $lowered instanceof StoreLocal) {
                $vt = $this->docTagType($stmt->docComment, '@var', $lowered->name);
                if ($vt === null) { $vt = $this->docTagType($stmt->docComment, '@var', ''); }
                if ($vt !== null) {
                    $lowered->declaredType = $this->lowerTypeHint($vt);
                    // `/** @var Box<float> $b */ $b = new Box();` — the binding is
                    // written on the SLOT (PHP has no syntax for it on `new`), so
                    // this is the one place the two meet, and the only one that
                    // owns BOTH: construct the reified class here, and type the
                    // slot as it. {@see LowerReify}
                    $this->reifiedNew($lowered);
                }
            }
            return $lowered;
        }
        if ($stmt->kind === 'Echo') {
            $items = [];
            foreach ($stmt->exprs as $e) {
                $items[] = $this->lowerExpr($e);
            }
            return new Echo_($items, Type::void());
        }
        if ($stmt->kind === 'Return') {
            $value = $stmt->value === null ? null : $this->lowerExpr($stmt->value);
            return new Return_($value, Type::void());
        }
        if ($stmt->kind === 'If')       { return $this->lowerIf($stmt); }
        if ($stmt->kind === 'While')    { return $this->lowerWhile($stmt); }
        if ($stmt->kind === 'For')      { return $this->lowerFor($stmt); }
        if ($stmt->kind === 'DoWhile')  { return $this->lowerDoWhile($stmt); }
        if ($stmt->kind === 'Switch')   { return $this->lowerSwitch($stmt); }
        if ($stmt->kind === 'Foreach')  { return $this->lowerForeach($stmt); }
        if ($stmt->kind === 'Break')    { return new Break_((int)($stmt->level ?? 1)); }
        if ($stmt->kind === 'Continue') { return new Continue_((int)($stmt->level ?? 1)); }
        if ($stmt->kind === 'Goto')     { return new Goto_($stmt->label, Type::void()); }
        if ($stmt->kind === 'Label')    { return new Label_($stmt->name, Type::void()); }
        if ($stmt->kind === 'StaticLocal') { return $this->lowerStaticLocal($stmt); }
        if ($stmt->kind === 'Global') { return $this->lowerGlobal($stmt); }
        if ($stmt->kind === 'Throw') { return new Throw_($this->lowerExpr($stmt->expr), Type::void()); }
        if ($stmt->kind === 'TryCatch') { return $this->lowerTryCatch($stmt); }
        throw new \RuntimeException(
            'MIR.lower: unsupported statement kind ' . $stmt->kind
        );
    }

    /**
     * Desugar `if cond { a } elseif c1 { b1 } elseif c2 { b2 } else { e }`
     * into `if cond { a } else { if c1 { b1 } else { if c2 { b2 } else { e } } }`.
     * Keeps the IR shape uniform and analysis straightforward.
     */
    private function lowerIf(\Parser\Ast\IfStmt $stmt): If_
    {
        $cond  = $this->lowerExpr($stmt->condition);
        $then  = $this->lowerBlockNode($stmt->then);
        $else_ = $stmt->else === null ? null : $this->lowerBlockNode($stmt->else);
        $elseifs = $stmt->elseifs;
        for ($i = \count($elseifs) - 1; $i >= 0; $i = $i - 1) {
            $pair = $elseifs[$i];
            $nested = new If_(
                $this->lowerExpr($pair->condition),
                $this->lowerBlockNode($pair->body),
                $else_,
            );
            $else_ = new Block([$nested], Type::void());
        }
        return new If_($cond, $then, $else_);
    }

    private function lowerWhile(\Parser\Ast\WhileStmt $stmt): While_
    {
        $cond = $this->lowerExpr($stmt->condition);
        $body = $this->lowerBlockNode($stmt->body);
        return new While_($cond, $body);
    }

    private function lowerFor(\Parser\Ast\ForStmt $stmt): For_
    {
        $init = $this->lowerForClause($stmt->init);
        $cond = $stmt->condition === null ? null : $this->lowerExpr($stmt->condition);
        $step = $this->lowerForClause($stmt->update);
        $body = $this->lowerBlockNode($stmt->body);
        return new For_($init, $cond, $step, $body);
    }

    /**
     * Lower a for-clause expression list: null when empty, the single node
     * when one, else a Block evaluating each in sequence (side effects only).
     *
     * @param \Parser\Ast\Expr[] $exprs
     */
    private function lowerForClause(array $exprs): ?Node
    {
        if (\count($exprs) === 0) { return null; }
        if (\count($exprs) === 1) { return $this->lowerExpr($exprs[0]); }
        $stmts = [];
        foreach ($exprs as $e) { $stmts[] = $this->lowerExpr($e); }
        return new Block($stmts, Type::void());
    }

    private function lowerForeach(\Parser\Ast\ForeachStmt $stmt): Node
    {
        $array = $this->lowerExpr($stmt->expr);
        $keyVar = null;
        if ($stmt->key !== null) { $keyVar = $stmt->key->name; }
        // List-destructuring value pattern — `foreach ($x as [$a, $b])` /
        // `foreach ($x as ['k' => $v])`: the value binding can't name the
        // pattern's several targets, so bind each element to a synthetic local
        // and destructure it into the pattern at the top of the body.
        if ($stmt->value->kind === 'ArrayLit') {
            $tmp = '__fe_val_' . (string)$this->destrCounter;
            $this->destrCounter = $this->destrCounter + 1;
            $destr = $this->lowerDestructure($stmt->value, new LoadLocal($tmp, Type::unknown()));
            $body = $this->lowerBlockNode($stmt->body);
            $stmts = [$destr];
            foreach ($body->stmts as $s) { $stmts[] = $s; }
            return $this->hoistForeachSubject(
                new Foreach_($array, $keyVar, $tmp, $stmt->valueByRef, new Block($stmts, Type::void())));
        }
        $valueVar = $stmt->value->name;
        $body = $this->lowerBlockNode($stmt->body);
        return $this->hoistForeachSubject(
            new Foreach_($array, $keyVar, $valueVar, $stmt->valueByRef, $body));
    }

    /**
     * Bind a CALL subject to a synthetic local: `foreach (f() as $v)` becomes
     * `$__fe_subj_N = f(); foreach ($__fe_subj_N as $v)`.
     *
     * A call hands back a +1 owned value (the return convention), but a subject
     * written inline is a TEMP with no owner — no store to release at scope
     * exit, and emitForeach never released it either, so every iteration of an
     * enclosing loop leaked the whole container. `Walk::children` alone (64 call
     * sites, all `foreach (Walk::children($n) as $c)`) leaked ~30M arrays in a
     * self-build. Naming the temp puts it back on the one path that already owns
     * this: InsertMemoryOps sees a plain owned store and releases it — and a
     * re-entered foreach releases the previous value on the overwrite.
     *
     * Only call kinds are hoisted. A borrow producer (local / property / element
     * read) is owned elsewhere and must NOT be released here, and an array
     * literal is arena-confined (the arena reclaims it).
     */
    private function hoistForeachSubject(Foreach_ $fe): Node
    {
        $k = $fe->array->kind;
        if ($k !== Node::KIND_CALL && $k !== Node::KIND_METHOD_CALL
            && $k !== Node::KIND_STATIC_CALL && $k !== Node::KIND_INVOKE) {
            return $fe;
        }
        $tmp = LowerFromAst::FE_SUBJ_PREFIX . (string)$this->destrCounter;
        $this->destrCounter = $this->destrCounter + 1;
        $store = new StoreLocal($tmp, $fe->array, Type::void());
        $fe->array = new LoadLocal($tmp, Type::unknown());
        return new Block([$store, $fe], Type::void());
    }

    private function lowerSwitch(\Parser\Ast\SwitchStmt $stmt): Switch_
    {
        $subject = $this->lowerExpr($stmt->expr);
        $arms = [];
        foreach ($stmt->cases as $arm) {
            $val = null;
            if ($arm->value !== null) { $val = $this->lowerExpr($arm->value); }
            $body = [];
            foreach ($arm->body as $s) { $body[] = $this->lowerStmt($s); }
            $arms[] = new SwitchArm_($val, $body);
        }
        return new Switch_($subject, $arms);
    }

    /**
     * `global $a, $b;` → bind each name to the shared module cell
     * `@g_<name>`. Modeled as an init-less {@see StaticLocalDecl_} so
     * EmitLlvm's `globalBackedLocals` routing handles reads/writes; the
     * name is also recorded on the module so `__main` shares the cell.
     */
    private function lowerGlobal(\Parser\Ast\GlobalStmt $stmt): Node
    {
        $nodes = [];
        foreach ($stmt->names as $name) {
            $cell = '@g_' . $name;
            $this->module->addGlobalCell($cell, new IntConst(0, Type::int_()));
            $this->module->addGlobalVarName($name);
            $nodes[] = new StaticLocalDecl_($name, $cell, '', null, Type::int_());
        }
        return new Block($nodes, Type::void());
    }
}
