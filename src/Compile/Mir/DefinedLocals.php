<?php

namespace Compile\Mir;

/**
 * The one owner of "what defines a local" in a MIR function body.
 *
 * Two passes need this set and must never disagree about it: {@see
 * \Compile\Mir\Passes\Verify} rejects a read of a local nothing defines, and
 * {@see \Compile\Mir\Passes\VivifyRefArgs} synthesizes the entry init for a
 * local whose ONLY definition is a by-ref argument position. If the two kept
 * their own kind lists, a new definition kind taught to one and not the other
 * would either resurrect the false "dangling local" or double-init a slot that
 * already had a value — the same disease as `preallocateLocals`' hand-written
 * list and `InlineClosures`' substitution list.
 *
 * Recursion is deliberately part of the rules, not an afterthought: a
 * definition-site kind is intercepted here BEFORE the generic {@see Walk}
 * descent, exactly as it was when this lived inside `Verify`.
 */
final class DefinedLocals
{
    /** @var array<string, true> */
    private array $defined = [];

    /**
     * Locals defined anywhere in `$fn` — parameters plus every definition site
     * in the body. Flow-insensitive by design: both consumers ask "is there a
     * definition at all", never "is it defined HERE".
     *
     * @return array<string, true>
     */
    public static function collect(FunctionDef $fn): array
    {
        $c = new self();
        foreach ($fn->params as $p) {
            $c->defined[$p->name] = true;
        }
        $c->walk($fn->body);
        return $c->defined;
    }

    /** Narrow to the concrete class so a field read uses ITS offsets. */
    private static function asRefCell(Node $n): RefCell_ { return $n; }

    private function walk(Node $n): void
    {
        $k = $n->kind;

        if ($k === Node::KIND_STORE_LOCAL) {
            $sl = $n;
            $this->defined[$sl->name] = true;
            $this->walk($sl->value);
            return;
        }
        if ($k === Node::KIND_INCDEC) {
            $this->defined[$n->name] = true;
            return;
        }
        if ($k === Node::KIND_REF_ALIAS) {
            $ra = $n;
            $this->defined[$ra->target] = true;
            return;
        }
        if ($k === Node::KIND_REF_BIND) {
            $rb = $n;
            $this->defined[$rb->target] = true;
            $this->walk($rb->call);
            return;
        }
        if ($k === Node::KIND_REF_ADDR) {
            $ra = $n;
            $this->defined[$ra->target] = true;
            $this->walk($ra->lvalue);
            return;
        }
        if ($k === Node::KIND_REF_CELL) {
            // Taking a reference DEFINES its target, as php does: after
            // `$r = [&$undef];` the name exists and is null. The lvalue is
            // deliberately not walked as a use — it is being bound, not read.
            // ⚠ TYPED receiver — `lvalue` is this class's FIRST field and
            // RefAddr_'s SECOND, so a Node-typed read picks the wrong offset.
            $rc = self::asRefCell($n);
            $lv = $rc->refSource;
            if ($lv->kind === Node::KIND_LOAD_LOCAL) {
                $this->defined[$lv->name] = true;
                return;
            }
            $this->walk($lv);
            return;
        }
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $sld = $n;
            $this->defined[$sld->name] = true;
            if ($sld->init !== null) { $this->walk($sld->init); }
            return;
        }
        if ($k === Node::KIND_FOREACH) {
            $fe = $n;
            $this->defined[$fe->valueVar] = true;
            if ($fe->keyVar !== null) { $this->defined[$fe->keyVar] = true; }
            $this->walk($fe->array);
            $this->walk($fe->body);
            return;
        }
        if ($k === Node::KIND_TRY_CATCH) {
            $tc = $n;
            foreach ($tc->tryBody as $s) { $this->walk($s); }
            foreach ($tc->catches as $c) {
                if ($c->var !== null) { $this->defined[$c->var] = true; }
                foreach ($c->body as $s) { $this->walk($s); }
            }
            foreach ($tc->finallyBody as $s) { $this->walk($s); }
            return;
        }

        foreach (Walk::children($n) as $c) { $this->walk($c); }
    }
}
