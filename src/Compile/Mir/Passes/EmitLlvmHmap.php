<?php

namespace Compile\Mir\Passes;

use Compile\MemoryAbi;
use Compile\Mir\HmapInline;
use Compile\Mir\Node;
use Compile\Mir\Type;

/**
 * `__mc_hmap_*` builtins that a key's static type lets us emit without boxing
 * the key into a cell ({@see HmapInline}). Anything else returns null and the
 * caller falls back to the erased {@see EmitLlvmBuiltins::biNbuf} path.
 */
trait EmitLlvmHmap
{
    /** @param Node[] $args */
    private function emitHmapBuiltin(string $op, array $args): ?string
    {
        if ($op !== 'find' || \count($args) !== 2) { return null; }
        $r = HmapInline::keyRepr($args[1]);
        if ($r === '') { return null; }
        $this->hmapNeeds();
        $out = $this->emitIntArg($args[0]);
        $h = $this->lastValue;
        $after = '';
        $out .= $this->hmapProbe($h, $args[1], $r, $after);
        return $out . $after;
    }

    private function hmapNeeds(): void
    {
        $this->rt->needsHmap = true;
        $this->rt->needsTagged = true;
        $this->rt->needsRc = true;
        $this->rt->needsStrRc = true;
    }

    /** `__mir_hmap_find_<r>(h, key)` into lastValue; `$after` drops a fresh key temp once the probe has read it. */
    private function hmapProbe(string $h, Node $key, string $r, string &$after): string
    {
        $out = '';
        if ($r === 'i') {
            $out .= $this->emitIntArg($key);
            $karg = 'i64 ' . $this->lastValue;
        } else {
            $out .= $this->emitNode($key);
            $out .= $this->boxToCell($key->type, $key);
            $cell = $this->lastValue;
            $after .= $this->cellBoxTempDrop($key->type, $cell, $key);
            $m = $this->ssa->allocReg();
            $p = $this->ssa->allocReg();
            $out .= '  ' . $m . ' = and i64 ' . $cell . ', ' . (string)MemoryAbi::CELL_PAYLOAD_MASK . "\n";
            $out .= '  ' . $p . ' = inttoptr i64 ' . $m . " to ptr\n";
            $karg = 'ptr ' . $p;
        }
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . ' = call i64 @__mir_hmap_find_' . $r . '(i64 ' . $h . ', ' . $karg . ")\n";
        $this->lastValue = $res;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * A Map/Set `get` / `has` / `set` (and the `[]`, `isset`, `[]=` forms)
     * with a typed key, run in place: the probe and the entry read without the
     * facade's frame. A miss or a bad key runs the real method, which owns
     * every error and the default argument. `$borrow`: an `$m[$k]` read, a
     * BORROW like every element read (the table keeps the value alive). Its
     * slow arm never returns: `offsetGet` passes `get` no default, so a miss or
     * a bad key throws (Map is final). Otherwise the result is the +1 the
     * method's `return` hands out. null when the shape does not apply.
     */
    private function emitHmapCall(\Compile\Mir\MethodCall_ $mc, bool $borrow = false): ?string
    {
        $op = HmapInline::call($mc);
        if ($op === null) { return null; }
        $cls = \ltrim((string)($mc->object->type->class ?? ''), '\\');
        if (!isset($this->classes[$cls])) { return null; }
        $off = $this->classes[$cls]->propertyOffset('__mcbuf');
        if ($off < 0) { return null; }
        $this->hmapNeeds();
        $key = $mc->args[0];
        $r = HmapInline::keyRepr($key);
        $out = $this->emitNode($mc->object);
        $out .= $this->coerceToPtr();
        $hp = $this->ssa->allocReg();
        $out .= '  ' . $hp . ' = getelementptr inbounds i8, ptr ' . $this->lastValue . ', i64 ' . (string)$off . "\n";
        $h = $this->ssa->allocReg();
        $out .= '  ' . $h . ' = load i64, ptr ' . $hp . $this->nbufTbaa(false) . "\n";
        if ($op === 'set') { return $out . $this->hmapSet($h, $key, $mc->args[1], $cls); }
        $after = '';
        $out .= $this->hmapProbe($h, $key, $r, $after);
        $e = $this->lastValue;
        $out .= $after;
        $fastL = $this->ssa->allocLabel('hm.fast');
        $slowL = $this->ssa->allocLabel('hm.slow');
        $endL = $this->ssa->allocLabel('hm.end');
        $ok = $this->ssa->allocReg();
        if ($op === 'has') {
            $out .= '  ' . $ok . ' = icmp ne i64 ' . $e . ", -2\n";
        } else {
            $out .= '  ' . $ok . ' = icmp sge i64 ' . $e . ", 0\n";
        }
        // The slow arm first: its result type is the merge slot's.
        $slow = $slowL . ":\n" . $this->emitMethodCallInner($mc);
        if ($borrow) {
            $ty = $this->lastValueType;
            $slow .= $this->nbufNoReturn();
        } elseif ($op === 'has') {
            $slow .= $this->coerceToI64();
            $nz = $this->ssa->allocReg();
            $slow .= '  ' . $nz . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
            $zx = $this->ssa->allocReg();
            $slow .= '  ' . $zx . ' = zext i1 ' . $nz . " to i64\n";
            $this->lastValue = $zx;
            $this->lastValueType = 'i64';
            $ty = 'i64';
        } else {
            $ty = $this->lastValueType;
        }
        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . ' = alloca ' . $ty . "\n";
        if (!$borrow) {
            $slow .= '  store ' . $ty . ' ' . $this->lastValue . ', ptr ' . $slot . "\n";
            $slow .= '  br label %' . $endL . "\n";
        }
        $out .= '  br i1 ' . $ok . ', label %' . $fastL . ', label %' . $slowL . "\n";
        $out .= $fastL . ":\n";
        if ($op === 'has') {
            $hit = $this->ssa->allocReg();
            $out .= '  ' . $hit . ' = icmp sge i64 ' . $e . ", 0\n";
            $v = $this->ssa->allocReg();
            $out .= '  ' . $v . ' = zext i1 ' . $hit . " to i64\n";
            $this->lastValue = $v;
            $this->lastValueType = 'i64';
        } else {
            $out .= $this->hmapValue($h, $e, $mc->type, $borrow);
            $out .= $this->coerceTo($ty);
        }
        $out .= '  store ' . $ty . ' ' . $this->lastValue . ', ptr ' . $slot . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $slow;
        $out .= $endL . ":\n";
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . ' = load ' . $ty . ', ptr ' . $slot . "\n";
        $this->lastValue = $res;
        $this->lastValueType = $ty;
        if ($op === 'get' && $this->hmapIsCell($mc->type)) {
            if ($borrow) { $this->markCellOpaque($res); } else { $this->markCellBoxed($res); }
        }
        return $out;
    }

    /**
     * `foreach` over a Map / Set / Vec, walking the native storage the way the
     * class's `getIterator()` Generator does — same order, same epoch check
     * after the body, Set's implicit 0.. keys, Vec's per-step handle and length
     * re-read — without the Generator frame. The loop variables take the +1
     * cells `__mir_hmap_key/val` / `__mir_nbuf_get_c` hand out, as they take
     * `current()`'s; the subject is held (+1) in the iterator slot for the
     * loop's life, so every exit gives it back as it gives back an iterator
     * ({@see EmitLlvmControl::releaseAggIterSlot}, the slot's Own drop on an
     * exception). null when the shape does not apply.
     */
    private function emitForeachDs(\Compile\Mir\Foreach_ $fe, string $iterSlot): ?string
    {
        $kind = HmapInline::foreachKind($fe);
        if ($kind === '') { return null; }
        // By reference: the Generator path iterates silently (#140), so the
        // throw comes first, ahead of every bail-out. A null subject walks
        // nothing, as a null array does.
        if ($fe->byRef) {
            $out = $this->emitNode($fe->array);
            $out .= $this->coerceToI64();
            $nz = $this->ssa->allocReg();
            $out .= '  ' . $nz . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
            $thrL = $this->ssa->allocLabel('feds.byref');
            $doneL = $this->ssa->allocLabel('feds.byref.end');
            $out .= '  br i1 ' . $nz . ', label %' . $thrL . ', label %' . $doneL . "\n" . $thrL . ":\n";
            $out .= $this->ehMarkRaise($this->emitNode(new \Compile\Mir\Call('Manticore\\Ds\\__iter_byref', [], Type::null_())), $fe->ownLive);
            $out .= '  br label %' . $doneL . "\n" . $doneL . ":\n";
            $this->lastValue = '0';
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($this->foreachBodyYields($fe->body)) { return null; }
        $vt = $fe->iterValueType;
        if ($vt !== null && $vt->kind !== Type::KIND_CELL && $vt->kind !== Type::KIND_UNKNOWN) { return null; }
        $cls = \ltrim((string)($fe->array->type->class ?? ''), '\\');
        if (!isset($this->classes[$cls])) { return null; }
        $off = $this->classes[$cls]->propertyOffset('__mcbuf');
        if ($off < 0) { return null; }
        $out = $this->emitNode($fe->array);
        $this->hmapNeeds();
        if ($kind === 'vec') { $this->rt->needsBuf = true; }
        $out .= $this->coerceToI64();
        $subj = $this->lastValue;
        if ($fe->ownDropIter !== null) { $out .= $this->ownDropIr($iterSlot, $fe->ownDropIter); }
        $out .= $this->rcRetainReg($subj, 'obj');
        $out .= '  store i64 ' . $subj . ', ptr ' . $iterSlot . "\n";
        $this->cf->pushAggIter($iterSlot, false);
        $condL = $this->ssa->allocLabel('feds.cond');
        $bodyL = $this->ssa->allocLabel('feds.body');
        $stepL = $this->ssa->allocLabel('feds.step');
        $endL = $this->ssa->allocLabel('feds.end');
        $pos = $this->ssa->allocReg();
        $out .= '  ' . $pos . " = alloca i64\n  store i64 0, ptr " . $pos . "\n";
        // A null subject (`?Map`) walks nothing, as a null array does.
        $nz = $this->ssa->allocReg();
        $goL = $this->ssa->allocLabel('feds.go');
        $out .= '  ' . $nz . ' = icmp ne i64 ' . $subj . ", 0\n";
        $out .= '  br i1 ' . $nz . ', label %' . $goL . ', label %' . $endL . "\n" . $goL . ":\n";
        $wantKey = $fe->keyVar !== null;
        if ($kind === 'vec') {
            $out .= '  br label %' . $condL . "\n" . $condL . ":\n";
            $i = $this->ssa->allocReg();
            $out .= '  ' . $i . ' = load i64, ptr ' . $pos . "\n";
            $out .= $this->dsHandle($iterSlot, $off);
            $h = $this->lastValue;
            $n = $this->ssa->allocReg();
            $out .= '  ' . $n . ' = call i64 @__mir_nbuf_len(i64 ' . $h . ")\n";
            $c = $this->ssa->allocReg();
            $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $n . "\n";
            $out .= '  br i1 ' . $c . ', label %' . $bodyL . ', label %' . $endL . "\n" . $bodyL . ":\n";
            $v = $this->ssa->allocReg();
            $out .= '  ' . $v . ' = call i64 @__mir_nbuf_get_c(i64 ' . $h . ', i64 ' . $i . ")\n";
            $out .= $this->foreachBindValue($fe, $v);
            if ($wantKey) { $out .= $this->dsIntKey($fe, $i); }
            $out .= $this->emitForeachBodyArm($fe, $endL, $stepL, true);
            $out .= $stepL . ":\n";
            $i0 = $this->ssa->allocReg();
            $i1 = $this->ssa->allocReg();
            $out .= '  ' . $i0 . ' = load i64, ptr ' . $pos . "\n";
            $out .= '  ' . $i1 . ' = add i64 ' . $i0 . ", 1\n  store i64 " . $i1 . ', ptr ' . $pos . "\n";
            $out .= '  br label %' . $condL . "\n";
        } else {
            // Set: the Generator's implicit keys 0, 1, 2… count in `$cnt`.
            $cnt = $this->ssa->allocReg();
            $out .= '  ' . $cnt . " = alloca i64\n  store i64 0, ptr " . $cnt . "\n";
            $out .= $this->dsHandle($iterSlot, $off);
            $h = $this->lastValue;
            $ep = $this->ssa->allocReg();
            $out .= '  ' . $ep . ' = call i64 @__mir_hmap_epoch(i64 ' . $h . ")\n";
            $e0 = $this->ssa->allocReg();
            $out .= '  ' . $e0 . ' = call i64 @__mir_hmap_next(i64 ' . $h . ", i64 0)\n";
            $out .= '  store i64 ' . $e0 . ', ptr ' . $pos . "\n";
            $out .= '  br label %' . $condL . "\n" . $condL . ":\n";
            $e = $this->ssa->allocReg();
            $out .= '  ' . $e . ' = load i64, ptr ' . $pos . "\n";
            $c = $this->ssa->allocReg();
            $out .= '  ' . $c . ' = icmp sge i64 ' . $e . ", 0\n";
            $out .= '  br i1 ' . $c . ', label %' . $bodyL . ', label %' . $endL . "\n" . $bodyL . ":\n";
            $k = $this->ssa->allocReg();
            if ($kind === 'set') {
                $out .= '  ' . $k . ' = call i64 @__mir_hmap_key(i64 ' . $h . ', i64 ' . $e . ")\n";
                $out .= $this->foreachBindValue($fe, $k);
                if ($wantKey) {
                    $ix = $this->ssa->allocReg();
                    $out .= '  ' . $ix . ' = load i64, ptr ' . $cnt . "\n";
                    $out .= $this->dsIntKey($fe, $ix);
                }
            } else {
                if ($wantKey) { $out .= '  ' . $k . ' = call i64 @__mir_hmap_key(i64 ' . $h . ', i64 ' . $e . ")\n"; }
                $v = $this->ssa->allocReg();
                $out .= '  ' . $v . ' = call i64 @__mir_hmap_val(i64 ' . $h . ', i64 ' . $e . ")\n";
                $out .= $this->foreachBindValue($fe, $v);
                if ($wantKey) { $out .= $this->foreachBindKey($fe, $k); }
            }
            $out .= $this->emitForeachBodyArm($fe, $endL, $stepL, true);
            $out .= $stepL . ":\n";
            $ep2 = $this->ssa->allocReg();
            $out .= '  ' . $ep2 . ' = call i64 @__mir_hmap_epoch(i64 ' . $h . ")\n";
            $moved = $this->ssa->allocReg();
            $out .= '  ' . $moved . ' = icmp ne i64 ' . $ep2 . ', ' . $ep . "\n";
            $modL = $this->ssa->allocLabel('feds.mod');
            $nextL = $this->ssa->allocLabel('feds.next');
            $out .= '  br i1 ' . $moved . ', label %' . $modL . ', label %' . $nextL . "\n" . $modL . ":\n";
            $out .= $this->ehMarkRaise($this->emitNode(new \Compile\Mir\Call('Manticore\\Ds\\__iter_modified',
                [new \Compile\Mir\StringConst($kind === 'set' ? 'Set' : 'Map', Type::string_())], Type::null_())), $fe->ownLive);
            $out .= '  br label %' . $nextL . "\n" . $nextL . ":\n";
            $ec = $this->ssa->allocReg();
            $out .= '  ' . $ec . ' = load i64, ptr ' . $pos . "\n";
            $e1 = $this->ssa->allocReg();
            $out .= '  ' . $e1 . ' = add i64 ' . $ec . ", 1\n";
            $en = $this->ssa->allocReg();
            $out .= '  ' . $en . ' = call i64 @__mir_hmap_next(i64 ' . $h . ', i64 ' . $e1 . ")\n";
            $out .= '  store i64 ' . $en . ', ptr ' . $pos . "\n";
            if ($wantKey && $kind === 'set') {
                $c0 = $this->ssa->allocReg();
                $c1 = $this->ssa->allocReg();
                $out .= '  ' . $c0 . ' = load i64, ptr ' . $cnt . "\n";
                $out .= '  ' . $c1 . ' = add i64 ' . $c0 . ", 1\n  store i64 " . $c1 . ', ptr ' . $cnt . "\n";
            }
            $out .= '  br label %' . $condL . "\n";
        }
        $out .= $endL . ":\n";
        $this->cf->popAggIter();
        $out .= $this->releaseAggIterSlot($iterSlot, false);
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** The `__mcbuf` handle of the subject held in `$iterSlot`, into lastValue. */
    private function dsHandle(string $iterSlot, int $off): string
    {
        $w = $this->ssa->allocReg();
        $out = '  ' . $w . ' = load i64, ptr ' . $iterSlot . "\n";
        $p = $this->ssa->allocReg();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $w . " to ptr\n";
        $hp = $this->ssa->allocReg();
        $out .= '  ' . $hp . ' = getelementptr inbounds i8, ptr ' . $p . ', i64 ' . (string)$off . "\n";
        $h = $this->ssa->allocReg();
        $out .= '  ' . $h . ' = load i64, ptr ' . $hp . $this->nbufTbaa(false) . "\n";
        $this->lastValue = $h;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Bind the int `$i` to the key variable as the cell the Generator's key is. */
    private function dsIntKey(\Compile\Mir\Foreach_ $fe, string $i): string
    {
        $this->lastValue = $i;
        $this->lastValueType = 'i64';
        $out = $this->boxToCell(Type::int_());
        return $out . $this->foreachBindKey($fe, $this->lastValue);
    }

    private function hmapIsCell(Type $t): bool
    {
        $k = $t->kind;
        return $k !== Type::KIND_INT && $k !== Type::KIND_FLOAT && $k !== Type::KIND_BOOL
            && $k !== Type::KIND_STRING && $k !== Type::KIND_ARRAY && $k !== Type::KIND_OBJ
            && $k !== Type::KIND_CLOSURE;
    }

    /** Entry `$e`'s value as `$t`: a borrow of the slot word, or the +1 `__mir_hmap_val` takes. */
    private function hmapValue(string $h, string $e, Type $t, bool $borrow): string
    {
        $v = $this->ssa->allocReg();
        if ($borrow) {
            $ep = $this->ssa->allocReg();
            $out = '  ' . $ep . ' = call ptr @__mir_hmap_ent(i64 ' . $h . ', i64 ' . $e . ")\n";
            $vp = $this->ssa->allocReg();
            $out .= '  ' . $vp . ' = getelementptr inbounds i8, ptr ' . $ep . ', i64 ' . (string)MemoryAbi::HMAP_ENTRY_VAL . "\n";
            $out .= '  ' . $v . ' = load i64, ptr ' . $vp . "\n";
        } else {
            $out = '  ' . $v . ' = call i64 @__mir_hmap_val(i64 ' . $h . ', i64 ' . $e . ")\n";
        }
        $this->lastValue = $v;
        $this->lastValueType = 'i64';
        if ($this->hmapIsCell($t)) { return $out; }
        $out .= $this->unboxCellToType($t);
        $k = $t->kind;
        // A scalar leaves the cell: the +1 (a boxed big int's) is ours to give back.
        if (!$borrow && ($k === Type::KIND_INT || $k === Type::KIND_FLOAT || $k === Type::KIND_BOOL)) {
            $out .= '  call void @__mir_cell_drop(i64 ' . $v . ")\n";
        }
        return $out;
    }

    /**
     * `set($k, $v)` / `$m[$k] = $v`: `__mir_hmap_put` retains what it keeps,
     * so the fresh key and value temps are dropped after it like any call
     * argument. -2 (a null behind a typed key) throws the facade's TypeError.
     */
    private function hmapSet(string $h, Node $key, Node $value, string $cls): string
    {
        $out = $this->emitNode($key);
        $out .= $this->boxToCell($key->type, $key);
        $kc = $this->lastValue;
        $after = $this->cellBoxTempDrop($key->type, $kc, $key);
        $out .= $this->emitNode($value);
        $out .= $this->boxToCell($value->type, $value);
        $vc = $this->lastValue;
        $after .= $this->cellBoxTempDrop($value->type, $vc, $value);
        $rc = $this->ssa->allocReg();
        $out .= '  ' . $rc . ' = call i64 @__mir_hmap_put(i64 ' . $h . ', i64 ' . $kc . ', i64 ' . $vc . ")\n" . $after;
        $bad = $this->ssa->allocReg();
        $out .= '  ' . $bad . ' = icmp eq i64 ' . $rc . ", -2\n";
        $slowL = $this->ssa->allocLabel('hm.slow');
        $endL = $this->ssa->allocLabel('hm.end');
        $out .= '  br i1 ' . $bad . ', label %' . $slowL . ', label %' . $endL . "\n";
        $out .= $slowL . ":\n";
        $out .= $this->emitNode(new \Compile\Mir\Call('Manticore\\Ds\\__hkey',
            [$key, new \Compile\Mir\StringConst(HmapInline::base($cls), Type::string_())], Type::cell()));
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }
}
