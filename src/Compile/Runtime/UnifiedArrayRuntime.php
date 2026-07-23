<?php

namespace Compile\Runtime;

use Codegen\Llvm\Block;
use Codegen\Llvm\FunctionDef;
use Codegen\Llvm\Module;
use Codegen\Llvm\Type;
use Codegen\Llvm\Value;
use Compile\Debug;
use Compile\MemoryAbi;

/**
 * Phase 3 unified PhpArray runtime (`docs/bootstrap/16`).
 *
 * Emits ONE array runtime that replaces the dual vec/assoc split: a
 * single 48-byte header (len/cap/next_int/rc/flags/buckets) with TWO
 * runtime modes — PACKED (contiguous i64 values, implicit int keys =
 * the vec fast path) and HASHED (24-byte entries + bucket index = the
 * assoc map). A buffer starts PACKED and is promoted to HASHED lazily
 * on the first string / sparse-int key. Because rc lives at
 * {@see MemoryAbi::ARRAY_RC_OFFSET} for EVERY array regardless of mode,
 * a value can never have its rc read at the wrong offset — the
 * structural kill for the vec/assoc layout heisenbug.
 *
 * The ONE array runtime (Stage 4: dual vec/assoc split deleted). Emits the
 * runtime only; codegen is not yet routed here (Stage 2). Depends only
 * on a {@see Module} + {@see RuntimeHost} + {@see MemoryAbi}. The caller
 * must declare `malloc` / `free` / `memset` / `memcpy` / `realloc` /
 * `strcmp` before {@see emitAll}.
 */
final class UnifiedArrayRuntime
{
    /**
     * HASHED arrays below this many entries use the linear scan (the
     * bucket index's alloc + build cost is not worth it for small maps).
     * At/above it, get/isset lazily build an open-addressed FNV index.
     */
    private const INDEX_THRESHOLD = 8;

    public function __construct(
        private Module $module,
        private RuntimeHost $host,
    ) {
    }

    /**
     * Emit the Stage 1 core: tagged alloc + alloc + retain + release.
     * get/set/append (with lazy PACKED→HASHED promotion), foreach,
     * count, cow and the element-drop walkers land in later increments.
     */
    public function emitAll(): void
    {
        $this->emitAllocTagged();
        $this->emitAlloc();
        $this->emitAllocHashed();
        $this->emitRetainVariant('__mir_array_retain', 'repr');
        $this->emitRetainVariant('__mir_array_retain_buf', '');
        $this->emitRetainVariant('__mir_array_retain_obj', 'obj');
        $this->emitRetainVariant('__mir_array_retain_str', 'str');
        $this->emitRetainVariant('__mir_array_retain_cell', 'cell');
        $this->emitRelease();
        $this->emitIsHashed();
        $this->emitGetInt();
        $this->emitGetStr();
        if (Debug::$emptyArraySingleton) { $this->emitDeimmortal(); }
        $this->emitPromote();
        $this->emitSetInt();
        $this->emitSetStr();
        $this->emitAppend();
        $this->emitCowVariant('__mir_array_cow', 'repr');
        $this->emitCowVariant('__mir_array_cow_obj', 'obj');
        $this->emitCowVariant('__mir_array_cow_str', 'str');
        $this->emitCowVariant('__mir_array_cow_cell', 'cell');
        $this->emitRefSlot();
        $this->emitRefSlotStr();
        $this->emitValueAt();
        $this->emitKeyAt();
        $this->emitKeyCellAt();
        $this->emitSpreadInto();
        $this->emitPop();
        $this->emitShift();
        $this->emitUnshift();
        $this->emitImplode();
        $this->emitImplodeInt();
        $this->emitIssetInt();
        $this->emitIssetStr();
        $this->emitUnsetStr();
        $this->emitUnsetInt();
        $this->emitCopy();
        $this->emitCopyDeep();
        $this->emitCopyCells();
        $this->emitHashStr();
        $this->emitStrCanonInt();
        $this->emitCkeyBoxInt();
        $this->emitCkeyUnboxInt();
        $this->emitIndexDrop();
        $this->emitIndexBuild();
        $this->emitIndexFind();
        $this->emitIndexAdd();
        $this->emitIndexUnset();
        $this->emitIndexRemove();
        $this->emitCompact();
        $this->emitLiveLen();
    }

    /**
     * `__mir_array_index_unset(arr, j) -> void` — surgically remove entry j
     * from the bucket index BEFORE the entry array memmove-compacts it away,
     * keeping the index valid across unset/pop/shift instead of dropping and
     * O(n)-rebuilding it on the next lookup (the drop made an unset+lookup
     * interleave O(n²)). Three steps: locate the slot holding j+1 (probe from
     * the entry key's home; a bounded miss degrades to index_drop, defensive),
     * classic linear-probe BACKSHIFT deletion (a following slot moves back
     * into the gap iff its home lies outside the emptied cyclic range — every
     * probe chain stays unbroken), then one O(nbuckets) sweep decrementing
     * slot values > j+1 (the memmove shifts those entries down one index).
     * No-op when no index is built. MUST be called before the memmove — the
     * entry keys are still in place for home recomputation.
     */
    private function emitIndexUnset(): void
    {
        $fn = $this->module->func('__mir_array_index_unset', Type::void());
        $arr = $fn->param(Type::ptr(), 'arr');
        $j = $fn->param(Type::i64(), 'j');
        $e = $fn->block('entry');
        $go = $fn->block('go');
        $hs = $fn->block('iu_hs');
        $hi = $fn->block('iu_hi');
        $linit = $fn->block('iu_linit');
        $loc = $fn->block('iu_loc');
        $lchk = $fn->block('iu_lchk');
        $lstep = $fn->block('iu_lstep');
        $bail = $fn->block('iu_bail');
        $bsInit = $fn->block('iu_bs_init');
        $bsStep = $fn->block('iu_bs_step');
        $bsHome = $fn->block('iu_bs_home');
        $bsHomeS = $fn->block('iu_bs_home_s');
        $bsHomeI = $fn->block('iu_bs_home_i');
        $bsCmp = $fn->block('iu_bs_cmp');
        $bsMove = $fn->block('iu_bs_move');
        $bsFin = $fn->block('iu_bs_fin');
        $swHead = $fn->block('iu_sw_head');
        $swBody = $fn->block('iu_sw_body');
        $swDec = $fn->block('iu_sw_dec');
        $swNext = $fn->block('iu_sw_next');
        $ret = $fn->block('iu_ret');

        $nb = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::ARRAY_NBUCKETS_OFFSET));
        $e->brIf($e->icmp('eq', $nb, Value::int(Type::i64(), 0)), $ret, $go);

        $buckets = $go->load(Type::ptr(), $this->hdr($go, $arr, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET));
        $mask = $go->sub($nb, Value::int(Type::i64(), 1));
        $want = $go->add($j, Value::int(Type::i64(), 1));
        $hSlot = $go->alloca(Type::i64(), 'iu_h');
        $sSlot = $go->alloca(Type::i64(), 'iu_s');
        $tSlot = $go->alloca(Type::i64(), 'iu_t');
        $cSlot = $go->alloca(Type::i64(), 'iu_c');
        $h2Slot = $go->alloca(Type::i64(), 'iu_h2');
        $kind = $go->load(Type::i64(), $this->entryAddr($go, $arr, $j, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $go->brIf($go->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $hs, $hi);
        $kp = $hs->load(Type::ptr(), $this->entryAddr($hs, $arr, $j, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $hs->store($hs->call('__mir_array_hash_str', Type::i64(), [$kp]), $hSlot);
        $hs->br($linit);
        $hi->store($hi->load(Type::i64(), $this->entryAddr($hi, $arr, $j, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET)), $hSlot);
        $hi->br($linit);
        $h0 = $linit->load(Type::i64(), $hSlot);
        $linit->store($linit->and_($h0, $mask), $sSlot);
        $linit->store(Value::int(Type::i64(), 0), $cSlot);
        $linit->br($loc);

        // Locate the slot holding j+1 (bounded: a full lap without a hit means
        // the index is inconsistent — drop it and let the next lookup rebuild).
        $c = $loc->load(Type::i64(), $cSlot);
        $loc->brIf($loc->icmp('sge', $c, $nb), $bail, $lchk);
        $s = $lchk->load(Type::i64(), $sSlot);
        $bv = $lchk->load(Type::i64(), $lchk->gep(Type::i64(), $buckets, [$s]));
        $lchk->brIf($lchk->icmp('eq', $bv, $want), $bsInit, $lstep);
        $sn = $lstep->load(Type::i64(), $sSlot);
        $lstep->store($lstep->and_($lstep->add($sn, Value::int(Type::i64(), 1)), $mask), $sSlot);
        $lstep->store($lstep->add($lstep->load(Type::i64(), $cSlot), Value::int(Type::i64(), 1)), $cSlot);
        $lstep->br($loc);
        $bail->call('__mir_array_index_drop', Type::void(), [$arr]);
        $bail->retVoid();

        // Backshift deletion from the located slot.
        $bsInit->store($bsInit->load(Type::i64(), $sSlot), $tSlot);
        $bsInit->br($bsStep);
        $t0 = $bsStep->load(Type::i64(), $tSlot);
        $t = $bsStep->and_($bsStep->add($t0, Value::int(Type::i64(), 1)), $mask);
        $bsStep->store($t, $tSlot);
        $bv2 = $bsStep->load(Type::i64(), $bsStep->gep(Type::i64(), $buckets, [$t]));
        $bsStep->brIf($bsStep->icmp('eq', $bv2, Value::int(Type::i64(), 0)), $bsFin, $bsHome);
        $k2 = $bsHome->sub($bv2, Value::int(Type::i64(), 1));
        $kind2 = $bsHome->load(Type::i64(), $this->entryAddr($bsHome, $arr, $k2, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $bsHome->brIf($bsHome->icmp('eq', $kind2, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $bsHomeS, $bsHomeI);
        $kp2 = $bsHomeS->load(Type::ptr(), $this->entryAddr($bsHomeS, $arr, $k2, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $bsHomeS->store($bsHomeS->call('__mir_array_hash_str', Type::i64(), [$kp2]), $h2Slot);
        $bsHomeS->br($bsCmp);
        $bsHomeI->store($bsHomeI->load(Type::i64(), $this->entryAddr($bsHomeI, $arr, $k2, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET)), $h2Slot);
        $bsHomeI->br($bsCmp);
        // Move back iff dist(home → t) >= dist(gap → t), i.e. the gap sits on
        // the probe path from this slot's home.
        $home = $bsCmp->and_($bsCmp->load(Type::i64(), $h2Slot), $mask);
        $tc = $bsCmp->load(Type::i64(), $tSlot);
        $sc = $bsCmp->load(Type::i64(), $sSlot);
        $dmk = $bsCmp->and_($bsCmp->sub($tc, $home), $mask);
        $dms = $bsCmp->and_($bsCmp->sub($tc, $sc), $mask);
        $bsCmp->brIf($bsCmp->icmp('uge', $dmk, $dms), $bsMove, $bsStep);
        $sm = $bsMove->load(Type::i64(), $sSlot);
        $bsMove->store($bv2, $bsMove->gep(Type::i64(), $buckets, [$sm]));
        $bsMove->store($bsMove->load(Type::i64(), $tSlot), $sSlot);
        $bsMove->br($bsStep);
        $sf = $bsFin->load(Type::i64(), $sSlot);
        $bsFin->store(Value::int(Type::i64(), 0), $bsFin->gep(Type::i64(), $buckets, [$sf]));
        $bsFin->store(Value::int(Type::i64(), 0), $cSlot);
        $bsFin->br($swHead);

        // Sweep: entries above j slide down one after the memmove.
        $c2 = $swHead->load(Type::i64(), $cSlot);
        $swHead->brIf($swHead->icmp('sge', $c2, $nb), $ret, $swBody);
        $bv3 = $swBody->load(Type::i64(), $swBody->gep(Type::i64(), $buckets, [$c2]));
        $swBody->brIf($swBody->icmp('sgt', $bv3, $want), $swDec, $swNext);
        $swDec->store($swDec->sub($bv3, Value::int(Type::i64(), 1)), $swDec->gep(Type::i64(), $buckets, [$c2]));
        $swDec->br($swNext);
        $swNext->store($swNext->add($swNext->load(Type::i64(), $cSlot), Value::int(Type::i64(), 1)), $cSlot);
        $swNext->br($swHead);
        $ret->retVoid();
    }

    /**
     * `__mir_array_index_remove(arr, j) -> void` — remove entry `j` from the
     * bucket index by classic linear-probe BACKSHIFT deletion, WITHOUT the
     * O(nbuckets) sweep {@see emitIndexUnset} does. Correct precisely because a
     * TOMBSTONE unset does NOT memmove the entry array, so no OTHER entry's
     * index changes — only j's slot must go. This is what makes a tombstone
     * unset O(1) (vs the compaction path's O(n) memmove + O(nbuckets) sweep).
     * No index built (nbuckets==0) → no-op.
     */
    private function emitIndexRemove(): void
    {
        $fn = $this->module->func('__mir_array_index_remove', Type::void());
        $arr = $fn->param(Type::ptr(), 'arr');
        $j = $fn->param(Type::i64(), 'j');
        $e = $fn->block('entry');
        $go = $fn->block('go');
        $hs = $fn->block('ir_hs');
        $hi = $fn->block('ir_hi');
        $linit = $fn->block('ir_linit');
        $loc = $fn->block('ir_loc');
        $lchk = $fn->block('ir_lchk');
        $lstep = $fn->block('ir_lstep');
        $bail = $fn->block('ir_bail');
        $bsInit = $fn->block('ir_bs_init');
        $bsStep = $fn->block('ir_bs_step');
        $bsHome = $fn->block('ir_bs_home');
        $bsHomeS = $fn->block('ir_bs_home_s');
        $bsHomeI = $fn->block('ir_bs_home_i');
        $bsCmp = $fn->block('ir_bs_cmp');
        $bsMove = $fn->block('ir_bs_move');
        $bsFin = $fn->block('ir_bs_fin');
        $ret = $fn->block('ir_ret');

        $nb = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::ARRAY_NBUCKETS_OFFSET));
        $e->brIf($e->icmp('eq', $nb, Value::int(Type::i64(), 0)), $ret, $go);

        $buckets = $go->load(Type::ptr(), $this->hdr($go, $arr, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET));
        $mask = $go->sub($nb, Value::int(Type::i64(), 1));
        $want = $go->add($j, Value::int(Type::i64(), 1));
        $hSlot = $go->alloca(Type::i64(), 'ir_h');
        $sSlot = $go->alloca(Type::i64(), 'ir_s');
        $tSlot = $go->alloca(Type::i64(), 'ir_t');
        $cSlot = $go->alloca(Type::i64(), 'ir_c');
        $h2Slot = $go->alloca(Type::i64(), 'ir_h2');
        $kind = $go->load(Type::i64(), $this->entryAddr($go, $arr, $j, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $go->brIf($go->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $hs, $hi);
        $kp = $hs->load(Type::ptr(), $this->entryAddr($hs, $arr, $j, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $hs->store($hs->call('__mir_array_hash_str', Type::i64(), [$kp]), $hSlot);
        $hs->br($linit);
        $hi->store($hi->load(Type::i64(), $this->entryAddr($hi, $arr, $j, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET)), $hSlot);
        $hi->br($linit);
        $h0 = $linit->load(Type::i64(), $hSlot);
        $linit->store($linit->and_($h0, $mask), $sSlot);
        $linit->store(Value::int(Type::i64(), 0), $cSlot);
        $linit->br($loc);

        $c = $loc->load(Type::i64(), $cSlot);
        $loc->brIf($loc->icmp('sge', $c, $nb), $bail, $lchk);
        $s = $lchk->load(Type::i64(), $sSlot);
        $bv = $lchk->load(Type::i64(), $lchk->gep(Type::i64(), $buckets, [$s]));
        $lchk->brIf($lchk->icmp('eq', $bv, $want), $bsInit, $lstep);
        $sn = $lstep->load(Type::i64(), $sSlot);
        $lstep->store($lstep->and_($lstep->add($sn, Value::int(Type::i64(), 1)), $mask), $sSlot);
        $lstep->store($lstep->add($lstep->load(Type::i64(), $cSlot), Value::int(Type::i64(), 1)), $cSlot);
        $lstep->br($loc);
        $bail->call('__mir_array_index_drop', Type::void(), [$arr]);
        $bail->retVoid();

        $bsInit->store($bsInit->load(Type::i64(), $sSlot), $tSlot);
        $bsInit->br($bsStep);
        $t0 = $bsStep->load(Type::i64(), $tSlot);
        $t = $bsStep->and_($bsStep->add($t0, Value::int(Type::i64(), 1)), $mask);
        $bsStep->store($t, $tSlot);
        $bv2 = $bsStep->load(Type::i64(), $bsStep->gep(Type::i64(), $buckets, [$t]));
        $bsStep->brIf($bsStep->icmp('eq', $bv2, Value::int(Type::i64(), 0)), $bsFin, $bsHome);
        $k2 = $bsHome->sub($bv2, Value::int(Type::i64(), 1));
        $kind2 = $bsHome->load(Type::i64(), $this->entryAddr($bsHome, $arr, $k2, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $bsHome->brIf($bsHome->icmp('eq', $kind2, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $bsHomeS, $bsHomeI);
        $kp2 = $bsHomeS->load(Type::ptr(), $this->entryAddr($bsHomeS, $arr, $k2, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $bsHomeS->store($bsHomeS->call('__mir_array_hash_str', Type::i64(), [$kp2]), $h2Slot);
        $bsHomeS->br($bsCmp);
        $bsHomeI->store($bsHomeI->load(Type::i64(), $this->entryAddr($bsHomeI, $arr, $k2, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET)), $h2Slot);
        $bsHomeI->br($bsCmp);
        $home = $bsCmp->and_($bsCmp->load(Type::i64(), $h2Slot), $mask);
        $tc = $bsCmp->load(Type::i64(), $tSlot);
        $sc = $bsCmp->load(Type::i64(), $sSlot);
        $dmk = $bsCmp->and_($bsCmp->sub($tc, $home), $mask);
        $dms = $bsCmp->and_($bsCmp->sub($tc, $sc), $mask);
        $bsCmp->brIf($bsCmp->icmp('uge', $dmk, $dms), $bsMove, $bsStep);
        $sm = $bsMove->load(Type::i64(), $sSlot);
        $bsMove->store($bv2, $bsMove->gep(Type::i64(), $buckets, [$sm]));
        $bsMove->store($bsMove->load(Type::i64(), $tSlot), $sSlot);
        $bsMove->br($bsStep);
        $sf = $bsFin->load(Type::i64(), $sSlot);
        $bsFin->store(Value::int(Type::i64(), 0), $bsFin->gep(Type::i64(), $buckets, [$sf]));
        $bsFin->br($ret);
        $ret->retVoid();
    }

    /**
     * `__mir_array_compact(arr) -> void` — remove TOMBSTONE (KIND_DELETED)
     * entries: copy each live entry down over the holes (order preserved), set
     * len = live count, clear the tombstone counter in the flags word, and drop
     * the bucket index (entry indices changed → rebuilt lazily on next lookup).
     * The dead entries' key/value bytes are overwritten without an rc drop —
     * the SAME Stage-3 leak the old memmove-unset already had (never worse). A
     * clean array (tombstone count 0) is left untouched by {@see emitLiveLen}.
     */
    private function emitCompact(): void
    {
        $this->module->declare('memcpy', Type::ptr(), [Type::ptr(), Type::ptr(), Type::i64()]);
        $fn = $this->module->func('__mir_array_compact', Type::void());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $head = $fn->block('c_head');
        $body = $fn->block('c_body');
        $live = $fn->block('c_live');
        $copy = $fn->block('c_copy');
        $adv = $fn->block('c_adv');
        $next = $fn->block('c_next');
        $done = $fn->block('c_done');
        $rz = $fn->block('c_rz');
        // Allocas live in ENTRY so they dominate the $done block (which the
        // null-check edge reaches without passing through $head).
        $rSlot = $e->alloca(Type::i64(), 'cr');   // read cursor
        $wSlot = $e->alloca(Type::i64(), 'cw');   // write cursor
        $e->brIf($e->icmp('eq', $arr, Value::null()), $rz, $head);
        $rz->retVoid();
        $len = $head->load(Type::i64(), $arr);
        $head->store(Value::int(Type::i64(), 0), $rSlot);
        $head->store(Value::int(Type::i64(), 0), $wSlot);
        $head->br($body);
        $r = $body->load(Type::i64(), $rSlot);
        $body->brIf($body->icmp('sge', $r, $len), $done, $live);
        $kind = $live->load(Type::i64(), $this->entryAddr($live, $arr, $r, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $live->brIf($live->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_DELETED)), $next, $copy);
        // Live entry: move it to the write cursor if they differ (memcpy the
        // whole 24-byte entry). Then advance the write cursor.
        $w = $copy->load(Type::i64(), $wSlot);
        $copy->brIf($copy->icmp('eq', $w, $r), $adv, $this->compactMove($fn, $copy, $arr, $w, $r, $adv));
        $adv->store($adv->add($adv->load(Type::i64(), $wSlot), Value::int(Type::i64(), 1)), $wSlot);
        $adv->br($next);
        $next->store($next->add($next->load(Type::i64(), $rSlot), Value::int(Type::i64(), 1)), $rSlot);
        $next->br($body);
        $wf = $done->load(Type::i64(), $wSlot);
        $done->store($wf, $arr);
        // Clear the tombstone counter (flags bits 8+), keep the low byte
        // (HASHED flag). Then drop the now-stale index.
        $flags = $done->load(Type::i64(), $this->hdr($done, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $done->store($done->and_($flags, Value::int(Type::i64(), 255)), $this->hdr($done, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $done->call('__mir_array_index_drop', Type::void(), [$arr]);
        $done->retVoid();
    }

    /** Helper block for {@see emitCompact}: memcpy entry `r` → `w`, then br to $adv. */
    private function compactMove(FunctionDef $fn, Block $from, Value $arr, Value $w, Value $r, Block $adv): Block
    {
        $mv = $fn->block($this->host->rtFreshLabel('c_move'));
        $dst = $this->entryAddr($mv, $arr, $w, 0);
        $src = $this->entryAddr($mv, $arr, $r, 0);
        $mv->call('memcpy', Type::ptr(), [$dst, $src, Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE)]);
        $mv->br($adv);
        return $mv;
    }

    /**
     * `__mir_array_live_len(arr) -> i64` — the physical entry count with holes
     * removed: if the array carries tombstones (flags bits 8+ nonzero) it is
     * COMPACTED first, then len is returned (now == live count). A clean array
     * (the overwhelming common case — no unset ever ran) returns len@0 directly
     * with only a flags load + branch. Every full ITERATION (foreach, json
     * walk, implode, spread, var_dump, array_keys/values, …) reads its bound
     * through here so it never visits a tombstone.
     */
    private function emitLiveLen(): void
    {
        $fn = $this->module->func('__mir_array_live_len', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $chk = $fn->block('chk');
        $comp = $fn->block('comp');
        $ret = $fn->block('ret');
        $z = $fn->block('z');
        // A non-array base reaching here is an ERASED value — a `mixed`/cell, an
        // undefined array-key read, or the empty-array zero word — that isn't an
        // array at runtime. php iterates/counts such a value as empty (foreach
        // warns and skips). Guard the header magic (0x7E66 high16 of RC_TAG):
        // anything else returns len 0 BEFORE the header is read, so a non-array
        // never reaches compact/index_drop (previously a wild bucket ptr was
        // free()d — an invalid free that glibc aborts on; macOS tolerated it).
        // Once per call (foreach/count/compare), not per iteration.
        $tagchk = $fn->block('ll_tagchk');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $z, $tagchk);
        $z->ret(Value::int(Type::i64(), 0));
        $llTag = $tagchk->load(Type::i64(), $this->hdr($tagchk, $arr, MemoryAbi::RC_TAG_OFFSET));
        $llHi = $tagchk->lshr($llTag, Value::int(Type::i64(), 48));
        $tagchk->brIf($tagchk->icmp('ne', $llHi, Value::int(Type::i64(), 0x7E66)), $z, $chk);
        $flags = $chk->load(Type::i64(), $this->hdr($chk, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $tomb = $chk->lshr($flags, Value::int(Type::i64(), 8));
        $chk->brIf($chk->icmp('eq', $tomb, Value::int(Type::i64(), 0)), $ret, $comp);
        $comp->call('__mir_array_compact', Type::void(), [$arr]);
        $comp->br($ret);
        $ret->ret($ret->load(Type::i64(), $arr));
    }

    /**
     * `__mir_array_hash_str(ptr key) -> i64` — FNV-1a 64-bit over the key's
     * `len@-16` bytes (binary-safe: an embedded NUL is hashed, not a
     * terminator), with __mir_strlen's libc fallback for a raw key. Agrees
     * with the __mir_str_eq key comparison (both len-based). NULL → 0. Raw
     * LLVM: FNV needs a WRAPPING `mul`, but the structured builder forces
     * `mul nsw` (overflow UB).
     */
    /**
     * `__mir_str_canon_int(key, out) -> i64`. 1 (and *out = the value) when the
     * key is a string php would normalise to an INT array key, else 0.
     *
     * php's rule, exactly: an optional '-', then either a lone "0" (never
     * "-0") or a digit run with no leading zero, and the whole thing must fit
     * in an int64. "01", "1.0", " 1", "+1", "1e2" and an out-of-range run all
     * stay string keys. The common case (a key whose first byte is not a digit
     * or '-') bails after one load.
     */
    private function emitStrCanonInt(): void
    {
        $ro = (string)MemoryAbi::STRING_RC_OFFSET;
        $lo = (string)MemoryAbi::STRING_LEN_OFFSET;
        $co = (string)MemoryAbi::STRING_CAP_OFFSET;
        $fn = $this->module->func('__mir_str_canon_int', Type::i64());
        $key = $fn->param(Type::ptr(), 'key');
        $out = $fn->param(Type::ptr(), 'out');
        $k = $key->operand;
        $o = $out->operand;

        $e = $fn->block('entry');
        $e->raw('  %isn = icmp eq ptr ' . $k . ', null');
        $e->raw('  br i1 %isn, label %z, label %hdrchk');
        // Same header heuristic __mir_array_hash_str uses: a RAW headerless key
        // must not have -16 read off it.
        $hc = $fn->block('hdrchk');
        $hc->raw('  %rcp = getelementptr inbounds i8, ptr ' . $k . ', i64 ' . $ro);
        $hc->raw('  %rc = load i64, ptr %rcp');
        $hc->raw('  %lp = getelementptr inbounds i8, ptr ' . $k . ', i64 ' . $lo);
        $hc->raw('  %hlen = load i64, ptr %lp');
        $hc->raw('  %cp = getelementptr inbounds i8, ptr ' . $k . ', i64 ' . $co);
        $hc->raw('  %cap = load i64, ptr %cp');
        $hc->raw('  %rlo = icmp slt i64 %rc, -1');
        $hc->raw('  %rhi = icmp sgt i64 %rc, 268435456');
        $hc->raw('  %llo = icmp slt i64 %hlen, 0');
        $hc->raw('  %lhi = icmp sgt i64 %hlen, %cap');
        $hc->raw('  %b1 = or i1 %rlo, %rhi');
        $hc->raw('  %b2 = or i1 %llo, %lhi');
        $hc->raw('  %bad = or i1 %b1, %b2');
        $hc->raw('  br i1 %bad, label %rawlen, label %hdrlen');
        $hl = $fn->block('hdrlen');
        $hl->raw('  br label %len');
        $rl = $fn->block('rawlen');
        $rl->raw('  %rawn = call i64 @strlen(ptr ' . $k . ')');
        $rl->raw('  br label %len');
        $len = $fn->block('len');
        $len->raw('  %n = phi i64 [ %hlen, %hdrlen ], [ %rawn, %rawlen ]');
        $len->raw('  %isempty = icmp sle i64 %n, 0');
        $len->raw('  br i1 %isempty, label %z, label %start');
        // Optional leading '-'; "-" alone is not a key.
        $st = $fn->block('start');
        $st->raw('  %c0 = load i8, ptr ' . $k);
        $st->raw('  %isneg = icmp eq i8 %c0, 45');
        $st->raw('  %i0 = select i1 %isneg, i64 1, i64 0');
        $st->raw('  %nodig = icmp sge i64 %i0, %n');
        $st->raw('  br i1 %nodig, label %z, label %firstdig');
        $fd = $fn->block('firstdig');
        $fd->raw('  %fp = getelementptr inbounds i8, ptr ' . $k . ', i64 %i0');
        $fd->raw('  %f = load i8, ptr %fp');
        $fd->raw('  %iszero = icmp eq i8 %f, 48');
        $fd->raw('  br i1 %iszero, label %zerocase, label %digits');
        // A leading zero is canonical only as the whole key "0" (not "-0").
        $zc = $fn->block('zerocase');
        $zc->raw('  %isone = icmp eq i64 %n, 1');
        $zc->raw('  %notneg = xor i1 %isneg, true');
        $zc->raw('  %ok0 = and i1 %isone, %notneg');
        $zc->raw('  br i1 %ok0, label %retzero, label %z');
        $rz = $fn->block('retzero');
        $rz->raw('  store i64 0, ptr ' . $o);
        $rz->raw('  ret i64 1');
        $dg = $fn->block('digits');
        $dg->raw('  %flo = icmp ult i8 %f, 49');
        $dg->raw('  %fhi = icmp ugt i8 %f, 57');
        $dg->raw('  %fbad = or i1 %flo, %fhi');
        $dg->raw('  br i1 %fbad, label %z, label %loopinit');
        $li = $fn->block('loopinit');
        $li->raw('  %acc.addr = alloca i64');
        $li->raw('  store i64 0, ptr %acc.addr');
        $li->raw('  %i.addr = alloca i64');
        $li->raw('  store i64 %i0, ptr %i.addr');
        $li->raw('  br label %loop');
        $lp = $fn->block('loop');
        $lp->raw('  %i = load i64, ptr %i.addr');
        $lp->raw('  %atend = icmp sge i64 %i, %n');
        $lp->raw('  br i1 %atend, label %fin, label %body');
        $bd = $fn->block('body');
        $bd->raw('  %bp = getelementptr inbounds i8, ptr ' . $k . ', i64 %i');
        $bd->raw('  %b = load i8, ptr %bp');
        $bd->raw('  %blo = icmp ult i8 %b, 48');
        $bd->raw('  %bhi = icmp ugt i8 %b, 57');
        $bd->raw('  %bbad = or i1 %blo, %bhi');
        $bd->raw('  br i1 %bbad, label %z, label %accum');
        // Overflow gate: 922337203685477580 is (2^63-1)/10; the last digit may
        // reach 8 only when negative, which lands exactly on PHP_INT_MIN.
        $ac = $fn->block('accum');
        $ac->raw('  %d8 = sub i8 %b, 48');
        $ac->raw('  %d = zext i8 %d8 to i64');
        $ac->raw('  %a = load i64, ptr %acc.addr');
        $ac->raw('  %ovf = icmp ugt i64 %a, 922337203685477580');
        $ac->raw('  br i1 %ovf, label %z, label %ovfchk');
        $oc = $fn->block('ovfchk');
        $oc->raw('  %ateq = icmp eq i64 %a, 922337203685477580');
        $oc->raw('  %lim = select i1 %isneg, i64 8, i64 7');
        $oc->raw('  %dhi = icmp ugt i64 %d, %lim');
        $oc->raw('  %bad2 = and i1 %ateq, %dhi');
        $oc->raw('  br i1 %bad2, label %z, label %step');
        $sp = $fn->block('step');
        $sp->raw('  %a10 = mul i64 %a, 10');
        $sp->raw('  %an = add i64 %a10, %d');
        $sp->raw('  store i64 %an, ptr %acc.addr');
        $sp->raw('  %inext = add i64 %i, 1');
        $sp->raw('  store i64 %inext, ptr %i.addr');
        $sp->raw('  br label %loop');
        // Negating the unsigned accumulator is what makes PHP_INT_MIN work.
        $fin = $fn->block('fin');
        $fin->raw('  %af = load i64, ptr %acc.addr');
        $fin->raw('  %negv = sub i64 0, %af');
        $fin->raw('  %vf = select i1 %isneg, i64 %negv, i64 %af');
        $fin->raw('  store i64 %vf, ptr ' . $o);
        $fin->raw('  ret i64 1');
        $z = $fn->block('z');
        $z->raw('  ret i64 0');
    }

    private function emitHashStr(): void
    {
        // FNV-1a over the content, with a per-string hash CACHE at
        // {@see MemoryAbi::STRING_HASH_OFFSET} (php's zend_string cache): a
        // repeated map key is hashed once. The cache is gated by a header
        // sanity check (the same rc/len/cap heuristic __mir_strlen uses) — a
        // RAW headerless key skips it (reading hash@-32 would be OOB) and hashes
        // uncached via libc strlen. The cache is WRITTEN only for a heap string
        // (rc > 0); an immortal literal (.rodata, rc=-1) is read-only so it
        // relies on its compile-time-baked hash, and an arena string (rc=-1)
        // recomputes (rare as a key). String literals never hash at runtime.
        $ro  = (string)MemoryAbi::STRING_RC_OFFSET;   // -8
        $lo  = (string)MemoryAbi::STRING_LEN_OFFSET;  // -16
        $co  = (string)MemoryAbi::STRING_CAP_OFFSET;  // -24
        $ho  = (string)MemoryAbi::STRING_HASH_OFFSET; // -32
        $fn = $this->module->func('__mir_array_hash_str', Type::i64());
        $key = $fn->param(Type::ptr(), 'key');
        $k = $key->operand;
        $e = $fn->block('entry');
        $e->raw('  %isn = icmp eq ptr ' . $k . ', null');
        $e->raw('  br i1 %isn, label %znull, label %hdrchk');
        $zn = $fn->block('znull');
        $zn->raw('  ret i64 0');
        // Header sanity: is this a real headered string (safe to touch -32)?
        $hc = $fn->block('hdrchk');
        $hc->raw('  %rcp = getelementptr inbounds i8, ptr ' . $k . ', i64 ' . $ro);
        $hc->raw('  %rc = load i64, ptr %rcp');
        $hc->raw('  %lp = getelementptr inbounds i8, ptr ' . $k . ', i64 ' . $lo);
        $hc->raw('  %hlen = load i64, ptr %lp');
        $hc->raw('  %cp = getelementptr inbounds i8, ptr ' . $k . ', i64 ' . $co);
        $hc->raw('  %cap = load i64, ptr %cp');
        $hc->raw('  %rlo = icmp slt i64 %rc, -1');
        $hc->raw('  %rhi = icmp sgt i64 %rc, 268435456');
        $hc->raw('  %llo = icmp slt i64 %hlen, 0');
        $hc->raw('  %lhi = icmp sgt i64 %hlen, %cap');
        $hc->raw('  %b1 = or i1 %rlo, %rhi');
        $hc->raw('  %b2 = or i1 %llo, %lhi');
        $hc->raw('  %bad = or i1 %b1, %b2');
        $hc->raw('  br i1 %bad, label %raw, label %hdr');
        // Headered: check the cache, else fall to FNV with len from the header.
        $hdr = $fn->block('hdr');
        $hdr->raw('  %hp = getelementptr inbounds i8, ptr ' . $k . ', i64 ' . $ho);
        $hdr->raw('  %cached = load i64, ptr %hp');
        $hdr->raw('  %hit = icmp ne i64 %cached, 0');
        $hdr->raw('  br i1 %hit, label %rethit, label %fnvh');
        $rh = $fn->block('rethit');
        $rh->raw('  ret i64 %cached');
        // Raw (headerless): libc strlen, hash uncached.
        $raw = $fn->block('raw');
        $raw->raw('  %rawlen = call i64 @strlen(ptr ' . $k . ')');
        $raw->raw('  br label %fnv');
        $fh = $fn->block('fnvh');
        $fh->raw('  br label %fnv');
        // Shared FNV-1a loop over %uselen bytes.
        $start = $fn->block('fnv');
        $start->raw('  %uselen = phi i64 [ %hlen, %fnvh ], [ %rawlen, %raw ]');
        $start->raw('  %h.addr = alloca i64');
        $start->raw('  store i64 -3750763034362895579, ptr %h.addr'); // FNV offset basis
        $start->raw('  %i.addr = alloca i64');
        $start->raw('  store i64 0, ptr %i.addr');
        $start->raw('  br label %loop');
        $loop = $fn->block('loop');
        $loop->raw('  %i = load i64, ptr %i.addr');
        $loop->raw('  %atend = icmp sge i64 %i, %uselen');
        $loop->raw('  br i1 %atend, label %done, label %body');
        $body = $fn->block('body');
        $body->raw('  %bptr = getelementptr inbounds i8, ptr ' . $k . ', i64 %i');
        $body->raw('  %b = load i8, ptr %bptr');
        $body->raw('  %bz = zext i8 %b to i64');
        $body->raw('  %h = load i64, ptr %h.addr');
        $body->raw('  %hx = xor i64 %h, %bz');
        $body->raw('  %hm = mul i64 %hx, 1099511628211'); // FNV prime, wrapping
        $body->raw('  store i64 %hm, ptr %h.addr');
        $body->raw('  %inext = add i64 %i, 1');
        $body->raw('  store i64 %inext, ptr %i.addr');
        $body->raw('  br label %loop');
        // Cache the result only for a headered HEAP string (rc > 0): never a
        // .rodata literal or arena string (both rc=-1, read-only / abandoned).
        $done = $fn->block('done');
        $done->raw('  %hf = load i64, ptr %h.addr');
        $done->raw('  %heap = icmp sgt i64 %rc, 0');
        $done->raw('  %notraw = icmp eq i1 %bad, false');
        $done->raw('  %docache = and i1 %heap, %notraw');
        $done->raw('  br i1 %docache, label %store, label %retf');
        $st = $fn->block('store');
        $st->raw('  %hp2 = getelementptr inbounds i8, ptr ' . $k . ', i64 ' . $ho);
        $st->raw('  store i64 %hf, ptr %hp2');
        $st->raw('  br label %retf');
        $rf = $fn->block('retf');
        $rf->raw('  ret i64 %hf');
    }

    /**
     * `__mir_array_index_drop(arr) -> void` — invalidate the bucket index
     * (free the side-array, zero nbuckets / buckets ptr). Called whenever
     * the entry set may change shape (a set that appends, an unset). The
     * index is rebuilt lazily on the next large-map lookup.
     */
    private function emitIndexDrop(): void
    {
        $fn = $this->module->func('__mir_array_index_drop', Type::void());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $chk = $fn->block('chk');
        $doFree = $fn->block('do_free');
        $ret = $fn->block('ret');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $ret, $chk);
        $nbAddr = $this->hdr($chk, $arr, MemoryAbi::ARRAY_NBUCKETS_OFFSET);
        $nb = $chk->load(Type::i64(), $nbAddr);
        $chk->brIf($chk->icmp('eq', $nb, Value::int(Type::i64(), 0)), $ret, $doFree);
        $bAddr = $this->hdr($doFree, $arr, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET);
        $b = $doFree->load(Type::ptr(), $bAddr);
        if (Debug::$arenaArrays) {
            // Arena arrays hold an arena-allocated bucket side-array — never
            // free() it; just invalidate the index (arena reclaims the buffer).
            $doFreeH = $fn->block('idrop_freeh');
            $zero = $fn->block('idrop_zero');
            $tag = $doFree->load(Type::i64(), $this->hdr($doFree, $arr, MemoryAbi::RC_TAG_OFFSET));
            $doFree->brIf($doFree->icmp('eq', $tag, Value::int(Type::i64(), MemoryAbi::ARRAY_TAG_ARENA)), $zero, $doFreeH);
            $doFreeH->call('free', Type::void(), [$b]);
            $doFreeH->br($zero);
            $zero->store(Value::int(Type::i64(), 0), $this->hdr($zero, $arr, MemoryAbi::ARRAY_NBUCKETS_OFFSET));
            $zero->store(Value::null(), $this->hdr($zero, $arr, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET));
            $zero->br($ret);
            $ret->retVoid();
            return;
        }
        $doFree->call('free', Type::void(), [$b]);
        $doFree->store(Value::int(Type::i64(), 0), $nbAddr);
        $doFree->store(Value::null(), $bAddr);
        $doFree->br($ret);
        $ret->retVoid();
    }

    /**
     * `__mir_array_index_build(arr) -> void` — (re)build the open-addressed
     * bucket index over the current HASHED entries. `nbuckets` = next power
     * of two >= max(16, len*2); each bucket holds `entry_index + 1` (0 =
     * empty). DELETED entries are skipped. Int keys hash to themselves
     * (dense after promote); string keys via FNV.
     */
    private function emitIndexBuild(): void
    {
        $fn = $this->module->func('__mir_array_index_build', Type::void());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $sizeIt = $fn->block('size_it');
        $hasOld = $fn->block('has_old');
        $caploop = $fn->block('caploop');
        $capgrow = $fn->block('capgrow');
        $capdone = $fn->block('capdone');
        $head = $fn->block('bhead');
        $bbody = $fn->block('bbody');
        $bkind = $fn->block('bkind');
        $bstr = $fn->block('bstr');
        $bint = $fn->block('bint');
        $probe = $fn->block('bprobe');
        $pscan = $fn->block('bpscan');
        $pstep = $fn->block('bpstep');
        $pput = $fn->block('bpput');
        $bnext = $fn->block('bnext');
        $done = $fn->block('bdone');

        $len = $e->load(Type::i64(), $arr);
        $oldb = $e->load(Type::ptr(), $this->hdr($e, $arr, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET));
        // Arena arrays: bucket side-array lives in the arena — never free() it,
        // and allocate a fresh index from the arena (old abandoned). $isA
        // dominates every block below (all reached from entry).
        $isA = null;
        $bkSlot = null;
        if (Debug::$arenaArrays) {
            $atag = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::RC_TAG_OFFSET));
            $isA = $e->icmp('eq', $atag, Value::int(Type::i64(), MemoryAbi::ARRAY_TAG_ARENA));
            $bkSlot = $e->alloca(Type::ptr(), 'bk_slot');
        }
        $e->brIf($e->icmp('ne', $oldb, Value::null()), $hasOld, $sizeIt);
        if (Debug::$arenaArrays) {
            $hfree = $fn->block('build_freeold');
            $hasOld->brIf($isA, $sizeIt, $hfree);
            $hfree->call('free', Type::void(), [$oldb]);
            $hfree->br($sizeIt);
        } else {
            $hasOld->call('free', Type::void(), [$oldb]);
            $hasOld->br($sizeIt);
        }

        $need = $sizeIt->mul($len, Value::int(Type::i64(), 2));
        $nbSlot = $sizeIt->alloca(Type::i64(), 'nb');
        $sizeIt->store(Value::int(Type::i64(), 16), $nbSlot);
        $sizeIt->br($caploop);
        $nbc = $caploop->load(Type::i64(), $nbSlot);
        $caploop->brIf($caploop->icmp('sge', $nbc, $need), $capdone, $capgrow);
        $capgrow->store($capgrow->shl($nbc, Value::int(Type::i64(), 1)), $nbSlot);
        $capgrow->br($caploop);

        $nb = $capdone->load(Type::i64(), $nbSlot);
        $bytes = $capdone->mul($nb, Value::int(Type::i64(), 8));
        if (Debug::$arenaArrays) {
            $bAr = $fn->block('build_bkarena');
            $bHp = $fn->block('build_bkheap');
            $bMg = $fn->block('build_bkdone');
            $capdone->brIf($isA, $bAr, $bHp);
            $bAr->store($bAr->call('__mir_arena_alloc', Type::ptr(), [$bytes]), $bkSlot);
            $bAr->br($bMg);
            $bHp->store($bHp->call('malloc', Type::ptr(), [$bytes]), $bkSlot);
            $bHp->br($bMg);
            $buckets = $bMg->load(Type::ptr(), $bkSlot);
            $capdone = $bMg;
        } else {
            $buckets = $capdone->call('malloc', Type::ptr(), [$bytes]);
        }
        $capdone->call('memset', Type::ptr(), [$buckets, Value::int(Type::i32(), 0), $bytes]);
        $capdone->store($nb, $this->hdr($capdone, $arr, MemoryAbi::ARRAY_NBUCKETS_OFFSET));
        $capdone->store($buckets, $this->hdr($capdone, $arr, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET));
        $mask = $capdone->sub($nb, Value::int(Type::i64(), 1));
        $iSlot = $capdone->alloca(Type::i64(), 'bi');
        $capdone->store(Value::int(Type::i64(), 0), $iSlot);
        $hSlot = $capdone->alloca(Type::i64(), 'bh');
        $sSlot = $capdone->alloca(Type::i64(), 'bs');
        $capdone->br($head);

        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $done, $bbody);
        $kind = $bbody->load(Type::i64(), $this->entryAddr($bbody, $arr, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        // DELETED (-1) entries are not indexed.
        $bbody->brIf($bbody->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_DELETED)), $bnext, $bkind);
        $bkind->brIf($bkind->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $bstr, $bint);
        $skp = $bstr->load(Type::ptr(), $this->entryAddr($bstr, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $bstr->store($bstr->call('__mir_array_hash_str', Type::i64(), [$skp]), $hSlot);
        $bstr->br($probe);
        $iki = $bint->load(Type::i64(), $this->entryAddr($bint, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $bint->store($iki, $hSlot);
        $bint->br($probe);

        $h = $probe->load(Type::i64(), $hSlot);
        $probe->store($probe->and_($h, $mask), $sSlot);
        $probe->br($pscan);
        $s = $pscan->load(Type::i64(), $sSlot);
        $slotAddr = $pscan->gep(Type::i64(), $buckets, [$s]);
        $bv = $pscan->load(Type::i64(), $slotAddr);
        $pscan->brIf($pscan->icmp('eq', $bv, Value::int(Type::i64(), 0)), $pput, $pstep);
        $pstep->store($pstep->and_($pstep->add($s, Value::int(Type::i64(), 1)), $mask), $sSlot);
        $pstep->br($pscan);
        // Store entry_index + 1 at the empty slot.
        $sput = $pput->load(Type::i64(), $sSlot);
        $putAddr = $pput->gep(Type::i64(), $buckets, [$sput]);
        $pput->store($pput->add($i, Value::int(Type::i64(), 1)), $putAddr);
        $pput->br($bnext);
        $bnext->store($bnext->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $bnext->br($head);
        $done->retVoid();
    }

    /**
     * `__mir_array_index_add(arr, j) -> void` — INCREMENTALLY add the just-
     * appended entry `j` to the bucket index, instead of dropping it. Keeps a
     * large string-keyed BUILD O(n) (a drop-on-every-append rebuilds the index
     * O(n) on the next lookup → O(n²)). No index yet (nbuckets==0) → no-op
     * (lazy-built on first lookup). Past ~0.7 load factor → drop (the next
     * lookup rebuilds bigger; capacities double, so amortized O(1) per append).
     */
    private function emitIndexAdd(): void
    {
        $fn = $this->module->func('__mir_array_index_add', Type::void());
        $arr = $fn->param(Type::ptr(), 'arr');
        $j = $fn->param(Type::i64(), 'j');
        $e = $fn->block('entry');
        $chk = $fn->block('chk');
        $full = $fn->block('full');
        $go = $fn->block('go');
        $kstr = $fn->block('kstr');
        $kint = $fn->block('kint');
        $probe = $fn->block('probe');
        $scan = $fn->block('scan');
        $step = $fn->block('step');
        $put = $fn->block('put');
        $ret = $fn->block('ret');

        $nbAddr = $this->hdr($e, $arr, MemoryAbi::ARRAY_NBUCKETS_OFFSET);
        $nb = $e->load(Type::i64(), $nbAddr);
        $e->brIf($e->icmp('eq', $nb, Value::int(Type::i64(), 0)), $ret, $chk);
        // Load factor: len*10 >= nb*7  (~0.7) → drop + lazy rebuild.
        $len = $chk->load(Type::i64(), $arr);
        $lhs = $chk->mul($len, Value::int(Type::i64(), 10));
        $rhs = $chk->mul($nb, Value::int(Type::i64(), 7));
        $chk->brIf($chk->icmp('sge', $lhs, $rhs), $full, $go);
        $full->call('__mir_array_index_drop', Type::void(), [$arr]);
        $full->br($ret);

        $buckets = $go->load(Type::ptr(), $this->hdr($go, $arr, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET));
        $mask = $go->sub($nb, Value::int(Type::i64(), 1));
        $hSlot = $go->alloca(Type::i64(), 'h');
        $sSlot = $go->alloca(Type::i64(), 's');
        $kind = $go->load(Type::i64(), $this->entryAddr($go, $arr, $j, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $go->brIf($go->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $kstr, $kint);
        $skp = $kstr->load(Type::ptr(), $this->entryAddr($kstr, $arr, $j, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kstr->store($kstr->call('__mir_array_hash_str', Type::i64(), [$skp]), $hSlot);
        $kstr->br($probe);
        $iki = $kint->load(Type::i64(), $this->entryAddr($kint, $arr, $j, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kint->store($iki, $hSlot);
        $kint->br($probe);

        $h = $probe->load(Type::i64(), $hSlot);
        $probe->store($probe->and_($h, $mask), $sSlot);
        $probe->br($scan);
        $s = $scan->load(Type::i64(), $sSlot);
        $slotAddr = $scan->gep(Type::i64(), $buckets, [$s]);
        $bv = $scan->load(Type::i64(), $slotAddr);
        $scan->brIf($scan->icmp('eq', $bv, Value::int(Type::i64(), 0)), $put, $step);
        $step->store($step->and_($step->add($s, Value::int(Type::i64(), 1)), $mask), $sSlot);
        $step->br($scan);
        $sput = $put->load(Type::i64(), $sSlot);
        $putAddr = $put->gep(Type::i64(), $buckets, [$sput]);
        $put->store($put->add($j, Value::int(Type::i64(), 1)), $putAddr);
        $put->br($ret);
        $ret->retVoid();
    }

    /**
     * `__mir_array_index_find(arr, wantKind, keyptr, keyint) -> i64` —
     * probe the bucket index for a key. Returns the entry index, `-1` on a
     * confirmed miss, or `-2` when the map is below {@see INDEX_THRESHOLD}
     * (caller should linear-scan). Builds the index lazily on first use.
     */
    private function emitIndexFind(): void
    {
        $fn = $this->module->func('__mir_array_index_find', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $wantKind = $fn->param(Type::i64(), 'wantKind');
        $keyptr = $fn->param(Type::ptr(), 'keyptr');
        $keyint = $fn->param(Type::i64(), 'keyint');
        // Precomputed string-key hash: when haveHash != 0, %hash is used instead
        // of calling __mir_array_hash_str — the compiler folds the FNV of a
        // LITERAL key at compile time, so a `$x["lit"]` access on a large map
        // skips the runtime hash scan. haveHash == 0 → compute as before.
        $hash = $fn->param(Type::i64(), 'hash');
        $haveHash = $fn->param(Type::i64(), 'haveHash');
        $e = $fn->block('entry');
        $big = $fn->block('big');
        $small = $fn->block('small');
        $needBuild = $fn->block('need_build');
        $build = $fn->block('build');
        $ready = $fn->block('ready');
        $hstr = $fn->block('hstr');
        $hstrHave = $fn->block('hstr_have');
        $hstrComp = $fn->block('hstr_comp');
        $hint = $fn->block('hint');
        $startp = $fn->block('startp');
        $head = $fn->block('fhead');
        $fkind = $fn->block('fkind');
        $fdisp = $fn->block('fdisp');
        $fstr = $fn->block('fstr');
        $fint = $fn->block('fint');
        $next = $fn->block('fnext');
        $hit = $fn->block('fhit');
        $miss = $fn->block('fmiss');

        $e->brIf($e->icmp('eq', $arr, Value::null()), $miss, $big);
        $len = $big->load(Type::i64(), $arr);
        $big->brIf($big->icmp('slt', $len, Value::int(Type::i64(), self::INDEX_THRESHOLD)), $small, $needBuild);
        $small->ret(Value::int(Type::i64(), -2));

        $nbAddr = $this->hdr($needBuild, $arr, MemoryAbi::ARRAY_NBUCKETS_OFFSET);
        $nb0 = $needBuild->load(Type::i64(), $nbAddr);
        $needBuild->brIf($needBuild->icmp('eq', $nb0, Value::int(Type::i64(), 0)), $build, $ready);
        $build->call('__mir_array_index_build', Type::void(), [$arr]);
        $build->br($ready);

        $nb = $ready->load(Type::i64(), $nbAddr);
        $buckets = $ready->load(Type::ptr(), $this->hdr($ready, $arr, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET));
        $mask = $ready->sub($nb, Value::int(Type::i64(), 1));
        $hSlot = $ready->alloca(Type::i64(), 'fh');
        $sSlot = $ready->alloca(Type::i64(), 'fs');
        $jSlot = $ready->alloca(Type::i64(), 'fj');
        $ready->brIf($ready->icmp('eq', $wantKind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $hstr, $hint);
        // Use the precomputed hash when provided, else compute it (only here —
        // after the small-map early-out, so a small map never hashes).
        $hstr->brIf($hstr->icmp('ne', $haveHash, Value::int(Type::i64(), 0)), $hstrHave, $hstrComp);
        $hstrHave->store($hash, $hSlot);
        $hstrHave->br($startp);
        $hstrComp->store($hstrComp->call('__mir_array_hash_str', Type::i64(), [$keyptr]), $hSlot);
        $hstrComp->br($startp);
        $hint->store($keyint, $hSlot);
        $hint->br($startp);

        $h = $startp->load(Type::i64(), $hSlot);
        $startp->store($startp->and_($h, $mask), $sSlot);
        $startp->br($head);

        $s = $head->load(Type::i64(), $sSlot);
        $slotAddr = $head->gep(Type::i64(), $buckets, [$s]);
        $bv = $head->load(Type::i64(), $slotAddr);
        $head->brIf($head->icmp('eq', $bv, Value::int(Type::i64(), 0)), $miss, $fkind);
        $j = $fkind->sub($bv, Value::int(Type::i64(), 1));
        $fkind->store($j, $jSlot);
        $ekind = $fkind->load(Type::i64(), $this->entryAddr($fkind, $arr, $j, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $fkind->brIf($fkind->icmp('ne', $ekind, $wantKind), $next, $fdisp);
        $fdisp->brIf($fdisp->icmp('eq', $wantKind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $fstr, $fint);
        $jS = $fstr->load(Type::i64(), $jSlot);
        $ek = $fstr->load(Type::ptr(), $this->entryAddr($fstr, $arr, $jS, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $fstr->brIf($fstr->call('__mir_str_eq', Type::i1(), [$ek, $keyptr]), $hit, $next);
        $jI = $fint->load(Type::i64(), $jSlot);
        $eki = $fint->load(Type::i64(), $this->entryAddr($fint, $arr, $jI, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $fint->brIf($fint->icmp('eq', $eki, $keyint), $hit, $next);
        $sn = $next->load(Type::i64(), $sSlot);
        $next->store($next->and_($next->add($sn, Value::int(Type::i64(), 1)), $mask), $sSlot);
        $next->br($head);
        $hit->ret($hit->load(Type::i64(), $jSlot));
        $miss->ret(Value::int(Type::i64(), -1));
    }

    /**
     * `__mir_array_copy(src) -> ptr` — unconditional value copy (PHP array
     * value semantics) of a unified array, preserving mode. Flat memcpy of
     * header + body (packed cap*8 / hashed cap*24), fresh rc=1, index reset.
     * Replaces `__mir_vec_copy` at the `$b = $a` / property-snapshot sites
     * under --array=unified (the vec-layout copy would read the wrong size
     * and miss the elements at the 56-byte unified header). Element values
     * stay shared (no per-element retain yet — Stage 3). NULL → NULL.
     */
    private function emitCopy(): void
    {
        $fn = $this->module->func('__mir_array_copy', Type::ptr());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $z = $fn->block('z');
        $go = $fn->block('go');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $z, $go);
        $z->ret(Value::null());
        $cap = $go->load(Type::i64(), $this->hdr($go, $arr, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $flags = $go->load(Type::i64(), $this->hdr($go, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $esz = $go->select($go->icmp('ne', $this->hashedBit($go, $flags), Value::int(Type::i64(), 0)),
            Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE),
            Value::int(Type::i64(), MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE));
        $bytes = $go->add($go->mul($cap, $esz), Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE));
        $copy = $go->call('__mir_alloc_array_tagged', Type::ptr(), [$bytes]);
        $go->call('memcpy', Type::ptr(), [$copy, $arr, $bytes]);
        $go->store(Value::int(Type::i64(), 1), $this->hdr($go, $copy, MemoryAbi::ARRAY_RC_OFFSET));
        $go->store(Value::int(Type::i64(), 0), $this->hdr($go, $copy, MemoryAbi::ARRAY_NBUCKETS_OFFSET));
        $go->store(Value::null(), $this->hdr($go, $copy, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET));
        $go->ret($copy);
    }

    /**
     * `__mir_array_copy_deep(arr, depth) -> ptr` — a value copy that clones
     * `depth` nested VEC levels. depth 0 is the flat `__mir_array_copy` (a
     * scalar/string/obj leaf element is correctly shared). depth>0 recurses into
     * each element (an inner vec) so `$x[0][] = …` on the copy can't reach the
     * caller's inner buffer. Element order is the packed slot order (0..len);
     * used only for VEC arrays (the copy-on-entry restricts to them). NULL→NULL.
     */
    private function emitCopyDeep(): void
    {
        $fn = $this->module->func('__mir_array_copy_deep', Type::ptr());
        $arr = $fn->param(Type::ptr(), 'arr');
        $depth = $fn->param(Type::i64(), 'depth');
        $e = $fn->block('entry');
        $z = $fn->block('z');
        $go = $fn->block('go');
        $head = $fn->block('head');
        $body = $fn->block('body');
        $ret = $fn->block('ret');

        $e->brIf($e->icmp('eq', $arr, Value::null()), $z, $go);
        $z->ret(Value::null());

        // Compact tombstones out of the source so the copy has no holes and the
        // per-element deep-copy walk visits only live entries.
        $go->call('__mir_array_live_len', Type::i64(), [$arr]);
        $copy0 = $go->call('__mir_array_copy', Type::ptr(), [$arr]);
        $copySlot = $go->alloca(Type::ptr(), 'copy');
        $go->store($copy0, $copySlot);
        $iSlot = $go->alloca(Type::i64(), 'i');
        $go->store(Value::int(Type::i64(), 0), $iSlot);
        $go->brIf($go->icmp('sle', $depth, Value::int(Type::i64(), 0)), $ret, $head);

        $hc = $head->load(Type::ptr(), $copySlot);
        $len = $head->load(Type::i64(), $hc);   // logical length at offset 0
        $hi = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $hi, $len), $ret, $body);

        $bc = $body->load(Type::ptr(), $copySlot);
        $bi = $body->load(Type::i64(), $iSlot);
        $v = $body->call('__mir_array_value_at', Type::i64(), [$bc, $bi]);
        $vp = $body->inttoptr($v, Type::ptr());
        $v2 = $body->call('__mir_array_copy_deep', Type::ptr(),
            [$vp, $body->sub($depth, Value::int(Type::i64(), 1))]);
        $v2i = $body->ptrtoint($v2, Type::i64());
        $nc = $body->call('__mir_array_set_int', Type::ptr(), [$bc, $bi, $v2i]);
        $body->store($nc, $copySlot);
        $body->store($body->add($bi, Value::int(Type::i64(), 1)), $iSlot);
        $body->br($head);

        $ret->ret($ret->load(Type::ptr(), $copySlot));
    }

    /**
     * `__mir_array_copy_cells(arr) -> ptr` — a value copy of a vec[cell] /
     * assoc[*,cell] whose elements are all NaN-boxed: flat-copies the outer, then
     * separates every boxed-ARRAY element (tag nibble 7) with a flat inner copy
     * so a nested `$x[$k][] = …` on a heterogeneous `[[1,2], "s"]` can't reach the
     * caller's inner buffer. Only valid for a cell-element array — a raw vec can't
     * be tag-inspected (a large/negative int could masquerade as a boxed ptr).
     * One inner level (a doubly-nested cell array still shares below it). NULL→NULL.
     */
    private function emitCopyCells(): void
    {
        $fn = $this->module->func('__mir_array_copy_cells', Type::ptr());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $z = $fn->block('z');
        $go = $fn->block('go');
        $head = $fn->block('head');
        $body = $fn->block('body');
        $isarr = $fn->block('isarr');
        $doarr = $fn->block('doarr');
        $cont = $fn->block('cont');
        $ret = $fn->block('ret');

        $e->brIf($e->icmp('eq', $arr, Value::null()), $z, $go);
        $z->ret(Value::null());

        // Compact the source first (no holes in the copy / the cell walk).
        $go->call('__mir_array_live_len', Type::i64(), [$arr]);
        $copy0 = $go->call('__mir_array_copy', Type::ptr(), [$arr]);
        $copySlot = $go->alloca(Type::ptr(), 'copy');
        $go->store($copy0, $copySlot);
        $iSlot = $go->alloca(Type::i64(), 'i');
        $go->store(Value::int(Type::i64(), 0), $iSlot);
        $go->br($head);

        $hc = $head->load(Type::ptr(), $copySlot);
        $len = $head->load(Type::i64(), $hc);
        $hi = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $hi, $len), $ret, $body);

        $bc = $body->load(Type::ptr(), $copySlot);
        $bi = $body->load(Type::i64(), $iSlot);
        $v = $body->call('__mir_array_value_at', Type::i64(), [$bc, $bi]);
        // A tagged cell has header bits above the double range; a raw scalar
        // falls through unchanged (shared — correct for int/string/obj cells).
        $body->brIf($body->icmp('ugt', $v, Value::int(Type::i64(), -4503599627370496)), $isarr, $cont);
        // nibble [48:52] == 7 → array payload.
        $nib = $isarr->and_($isarr->lshr($v, Value::int(Type::i64(), 48)), Value::int(Type::i64(), 15));
        $isarr->brIf($isarr->icmp('eq', $nib, Value::int(Type::i64(), 7)), $doarr, $cont);
        $ip = $doarr->inttoptr($doarr->and_($v, Value::int(Type::i64(), 281474976710655)), Type::ptr());
        $icp = $doarr->call('__mir_array_copy', Type::ptr(), [$ip]);
        // Re-box the fresh (non-null) copy as an ARRAY cell inline — the same
        // encoding as `__manticore_box_array`: (ptr & PAYLOAD_MASK) | ARRAY tag.
        // Inlined so `copy_cells` doesn't depend on that helper being emitted.
        $iv = $doarr->or_(
            $doarr->and_($doarr->ptrtoint($icp, Type::i64()), Value::int(Type::i64(), 281474976710655)),
            Value::int(Type::i64(), -2533274790395904),
        );
        $bc2 = $doarr->load(Type::ptr(), $copySlot);
        $nc = $doarr->call('__mir_array_set_int', Type::ptr(), [$bc2, $bi, $iv]);
        $doarr->store($nc, $copySlot);
        $doarr->br($cont);

        $cont->store($cont->add($cont->load(Type::i64(), $iSlot), Value::int(Type::i64(), 1)), $iSlot);
        $cont->br($head);

        $ret->ret($ret->load(Type::ptr(), $copySlot));
    }

    /** (flags & ARRAY_FLAG_HASHED) — the HASHED-mode bit isolated from the
     *  repr/tombstone bits sharing the flags word. Use this for every
     *  mode test (`!= 0` ⇒ HASHED, `== 0` ⇒ PACKED); a bare `flags != 0`
     *  would misread a PACKED array once repr bits are stamped. */
    private function hashedBit(Block $b, Value $flags): Value
    {
        return $b->and_($flags, Value::int(Type::i64(), MemoryAbi::ARRAY_FLAG_HASHED));
    }

    /** PACKED → 8, HASHED → 24 (element/entry stride). */
    private function elemSize(Block $b, Value $arr): Value
    {
        $flags = $b->load(Type::i64(), $this->hdr($b, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        return $b->select(
            $b->icmp('ne', $this->hashedBit($b, $flags), Value::int(Type::i64(), 0)),
            Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE),
            Value::int(Type::i64(), MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE),
        );
    }

    // ── small shared GEP/load helpers ─────────────────────────────

    private function hdr(\Codegen\Llvm\Block $b, Value $arr, int $off): Value
    {
        return $b->gep(Type::i8(), $arr, [Value::int(Type::i64(), $off)]);
    }

    /** Address of packed value slot i: data + HEADER + i*8. */
    private function packedSlot(\Codegen\Llvm\Block $b, Value $arr, Value $i): Value
    {
        $off = $b->add(
            $b->mul($i, Value::int(Type::i64(), MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE)),
            Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE),
        );
        return $b->gep(Type::i8(), $arr, [$off]);
    }

    /**
     * Effective probe hash for a linear scan: the call site's folded literal
     * hash when haveHash != 0, else the probe's own header cache at
     * {@see MemoryAbi::STRING_HASH_OFFSET} (0 = uncomputed → prefilter
     * disabled for this probe). Key must be nonnull (every string, heap or
     * .rodata or arena, carries a readable 32-byte header).
     */
    private function scanProbeHash(Block $b, Value $key, Value $hash, Value $haveHash): Value
    {
        $cached = $b->load(Type::i64(), $this->hdr($b, $key, MemoryAbi::STRING_HASH_OFFSET));
        return $b->select(
            $b->icmp('ne', $haveHash, Value::int(Type::i64(), 0)),
            $hash,
            $cached,
        );
    }

    /**
     * Linear-scan hash prefilter: in $b (entry key $tk already nonnull),
     * branch to $skip when both the effective probe hash and the entry key's
     * cached header hash are known (nonzero) and differ — the keys cannot be
     * equal, so $match's __mir_str_eq call is skipped. Either side 0 falls
     * through to $match: 0 is always "uncomputed", never a hash value worth
     * trusting (str_alloc zeroes the field in every path, literals bake a
     * compile-time FNV; a true FNV of 0 merely disables the filter).
     */
    private function hashPrefilter(Block $b, Value $tk, Value $effSlot, Block $match, Block $skip): void
    {
        $eff = $b->load(Type::i64(), $effSlot);
        $cached = $b->load(Type::i64(), $this->hdr($b, $tk, MemoryAbi::STRING_HASH_OFFSET));
        $known = $b->and_(
            $b->icmp('ne', $eff, Value::int(Type::i64(), 0)),
            $b->icmp('ne', $cached, Value::int(Type::i64(), 0)),
        );
        $mism = $b->and_($known, $b->icmp('ne', $cached, $eff));
        $b->brIf($mism, $skip, $match);
    }

    /** Byte offset of hashed entry i field: HEADER + i*24 + field. */
    private function entryAddr(\Codegen\Llvm\Block $b, Value $arr, Value $i, int $field): Value
    {
        $off = $b->add(
            $b->add(
                $b->mul($i, Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE)),
                Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE),
            ),
            Value::int(Type::i64(), $field),
        );
        return $b->gep(Type::i8(), $arr, [$off]);
    }

    /**
     * `__mir_alloc_array_tagged(size) -> ptr` — malloc `size + 8`, write
     * {@see MemoryAbi::ARRAY_TAG_MAGIC} at the base, return `base + 8`
     * (the data ptr). Mirror of `__mir_alloc_assoc_tagged` with the
     * array sentinel, so the rc helpers self-route on `ptr-8`.
     */
    private function emitAllocTagged(): void
    {
        $fn = $this->module->func('__mir_alloc_array_tagged', Type::ptr());
        $size = $fn->param(Type::i64(), 'size');
        $b = $fn->block('entry');
        $total = $b->add($size, Value::int(Type::i64(), 8));
        $base = $b->call('malloc', Type::ptr(), [$total]);
        $b->store(Value::int(Type::i64(), MemoryAbi::ARRAY_TAG_MAGIC), $base);
        if (Debug::$profile) {
            $b->call('__prof_bump', Type::void(), [Value::int(Type::i64(), 14)]);
        }
        $data = $b->gep(Type::i8(), $base, [Value::int(Type::i64(), 8)]);
        $b->ret($data);
    }

    /**
     * `__mir_array_alloc(cap) -> ptr` — allocate a PACKED array with
     * room for `cap` i64 value slots. Header zeroed, capacity set,
     * rc = 1, flags = 0 (PACKED). `cap < 0` is clamped to 0 (empty
     * stub; the first append grows it).
     */
    private function emitAlloc(): void
    {
        $fn = $this->module->func('__mir_array_alloc', Type::ptr());
        $capIn = $fn->param(Type::i64(), 'cap');
        $b = $fn->block('entry');
        $neg = $b->icmp('slt', $capIn, Value::int(Type::i64(), 0));
        $cap = $b->select($neg, Value::int(Type::i64(), 0), $capIn);
        if (Debug::$profile) {
            $isEmpty = $b->icmp('eq', $capIn, Value::int(Type::i64(), 0));
            $bump = $fn->block('prof_empty');
            $cont = $fn->block('prof_cont');
            $b->brIf($isEmpty, $bump, $cont);
            $bump->call('__prof_bump', Type::void(), [Value::int(Type::i64(), 15)]);
            $bump->br($cont);
            $b = $cont;
        }
        $bytes = $b->add(
            $b->mul($cap, Value::int(Type::i64(), MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE)),
            Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE),
        );
        $arr = $b->call('__mir_alloc_array_tagged', Type::ptr(), [$bytes]);
        $b->call('memset', Type::ptr(), [
            $arr,
            Value::int(Type::i32(), 0),
            Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE),
        ]);
        $b->store(
            $cap,
            $b->gep(Type::i8(), $arr, [Value::int(Type::i64(), MemoryAbi::ARRAY_CAPACITY_OFFSET)]),
        );
        $b->store(
            Value::int(Type::i64(), 1),
            $b->gep(Type::i8(), $arr, [Value::int(Type::i64(), MemoryAbi::ARRAY_RC_OFFSET)]),
        );
        $b->ret($arr);
    }

    /**
     * `__mir_array_retain[_obj|_str|_cell](arr)` — rc += 1. No-op on NULL.
     * Tag-guarded: a buffer carrying any sentinel other than
     * {@see MemoryAbi::ARRAY_TAG_MAGIC} at `ptr-8` is not a unified array →
     * bail before touching rc@24.
     *
     * The flavored variants additionally CO-OWN what the matching
     * {@see emitReleaseVariant} drops — hashed string keys always, element
     * values per `$valueFlavor`. Retain used to be buffer-only for every array
     * flavor while release dropped elements, so a second owner of a `Node[]`
     * (`return $this->stmts;` — the +1 borrow-return convention) dropped every
     * child on its release without ever having retained one: the tree's nodes
     * were freed under it. Retain must undo exactly what release does.
     */
    private function emitRetainVariant(string $symbol, string $valueFlavor): void
    {
        $fn = $this->module->func($symbol, Type::void());
        $arr = $fn->param(Type::ptr(), 'arr');
        $entry = $fn->block('entry');
        $cont = $fn->block('cont');
        $skipNull = $fn->block('skip_null');
        $bail = $fn->block('bail');
        $bump = $fn->block('bump');

        $entry->brIf($entry->icmp('eq', $arr, Value::null()), $skipNull, $cont);
        $skipNull->retVoid();

        $tag = $cont->load(Type::i64(), $cont->gep(Type::i8(), $arr, [Value::int(Type::i64(), MemoryAbi::RC_TAG_OFFSET)]));
        $cont->brIf($cont->icmp('eq', $tag, Value::int(Type::i64(), MemoryAbi::ARRAY_TAG_MAGIC)), $bump, $bail);
        $bail->retVoid();

        $rcAddr = $bump->gep(Type::i8(), $arr, [Value::int(Type::i64(), MemoryAbi::ARRAY_RC_OFFSET)]);
        $cur = $bump->load(Type::i64(), $rcAddr);
        if (Debug::$verify) {
            $ok = $fn->block('rc_ok');
            $bad = $fn->block('rc_bad');
            $bump->brIf($bump->icmp('sle', $cur, Value::int(Type::i64(), 0)), $bad, $ok);
            $fmt = $this->module->anonString(
                "[VERIFY] array_retain: rc <= 0 (retaining freed buffer) arr=%p rc=%lld\n"
            );
            $bad->call('dprintf', Type::i32(), [Value::int(Type::i32(), 2), $fmt, $arr, $cur], null, '(i32, ptr, ...)');
            $bad->call('abort', Type::void(), []);
            $bad->unreachable();
            $bump = $ok;
            $rcAddr = $bump->gep(Type::i8(), $arr, [Value::int(Type::i64(), MemoryAbi::ARRAY_RC_OFFSET)]);
        }
        $bump->store($bump->add($cur, Value::int(Type::i64(), 1)), $rcAddr);

        // ── co-own exactly what the matching release will drop ──
        $ret = $fn->block('rt_ret');
        $len = $bump->load(Type::i64(), $arr);
        $flags = $bump->load(Type::i64(), $this->hdr($bump, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $isH = $bump->icmp('ne', $this->hashedBit($bump, $flags), Value::int(Type::i64(), 0));
        $iSlot = $bump->alloca(Type::i64(), 'ri');
        $bump->store(Value::int(Type::i64(), 0), $iSlot);
        // 'repr' mode (the plain __mir_array_retain): co-own each boxed-cell
        // element iff the runtime repr bits are set — the mirror of the plain
        // release, so an erased cell-array's second owner co-owns exactly what
        // that owner's release will drop.
        $repr = ($valueFlavor === 'repr');
        $reprBits = $repr ? $bump->and_($flags, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_MASK)) : null;
        $hasCells = $repr ? $bump->icmp('ne', $reprBits, Value::int(Type::i64(), 0)) : null;
        $hhead = $fn->block('rt_hhead');
        if ($valueFlavor === '') {
            $bump->brIf($isH, $hhead, $ret);
        } elseif ($repr) {
            $phead = $fn->block('rt_phead');
            $pbody = $fn->block('rt_pbody');
            $pgate = $fn->block('rt_pgate');
            $bump->brIf($isH, $hhead, $pgate);
            $pgate->brIf($hasCells, $phead, $ret);
            $pi = $phead->load(Type::i64(), $iSlot);
            $phead->brIf($phead->icmp('sge', $pi, $len), $ret, $pbody);
            $pv = $pbody->load(Type::i64(), $this->packedSlot($pbody, $arr, $pi));
            $pbody->call('__mir_retain_by_repr', Type::void(), [$pv, $reprBits]);
            $pbody->store($pbody->add($pi, Value::int(Type::i64(), 1)), $iSlot);
            $pbody->br($phead);
        } else {
            $phead = $fn->block('rt_phead');
            $pbody = $fn->block('rt_pbody');
            $bump->brIf($isH, $hhead, $phead);
            $pi = $phead->load(Type::i64(), $iSlot);
            $phead->brIf($phead->icmp('sge', $pi, $len), $ret, $pbody);
            $pv = $pbody->load(Type::i64(), $this->packedSlot($pbody, $arr, $pi));
            $pbody = $this->emitRetainValue($fn, $pbody, $pv, $valueFlavor, 'rtp');
            $pbody->store($pbody->add($pi, Value::int(Type::i64(), 1)), $iSlot);
            $pbody->br($phead);
        }
        $hbody = $fn->block('rt_hbody');
        $hkey  = $fn->block('rt_hkey');
        $hval  = $fn->block('rt_hval');
        $hi = $hhead->load(Type::i64(), $iSlot);
        $hhead->brIf($hhead->icmp('sge', $hi, $len), $ret, $hbody);
        $kind = $hbody->load(Type::i64(), $this->entryAddr($hbody, $arr, $hi, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $hbody->brIf($hbody->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $hkey, $hval);
        $kp = $hkey->load(Type::ptr(), $this->entryAddr($hkey, $arr, $hi, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $hkey->call('__mir_rc_retain_str', Type::void(), [$kp]);
        $hkey->br($hval);
        if ($repr) {
            $hadv = $fn->block('rt_hadv');
            $hvret = $fn->block('rt_hvret');
            $hval->brIf($hasCells, $hvret, $hadv);
            $vv = $hvret->load(Type::i64(), $this->entryAddr($hvret, $arr, $hi, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
            $hvret->call('__mir_retain_by_repr', Type::void(), [$vv, $reprBits]);
            $hvret->br($hadv);
            $hadv->store($hadv->add($hi, Value::int(Type::i64(), 1)), $iSlot);
            $hadv->br($hhead);
        } else {
            if ($valueFlavor !== '') {
                $vv = $hval->load(Type::i64(), $this->entryAddr($hval, $arr, $hi, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
                $hval = $this->emitRetainValue($fn, $hval, $vv, $valueFlavor, 'rth');
            }
            $hval->store($hval->add($hi, Value::int(Type::i64(), 1)), $iSlot);
            $hval->br($hhead);
        }

        $ret->retVoid();
    }

    /**
     * `__mir_array_release(arr)` — rc -= 1; free at zero. Scalar values
     * (drops only the HASHED string keys, which `__mir_array_set_str`
     * retained). `_obj` / `_str` variants additionally drop the element
     * VALUES. The codegen picks the variant by the array's static element
     * type (Stage 3); a wrong static kind can't corrupt — the value-flavor
     * only decides which rc helper runs on each slot, and the walk itself
     * is mode-driven (packed slots vs hashed entries) at runtime.
     */
    private function emitRelease(): void
    {
        $this->emitCellDrop();
        $this->emitCellRetain();
        $this->emitDropByRepr();
        $this->emitRetainByRepr();
        $this->emitReleaseVariant('__mir_array_release', 'repr');
        // Buffer-only: drop the buffer (+ hashed string keys) but NEVER the
        // element values, ignoring the repr bits. Used for a container passed
        // BY VALUE to a callee (elementSharedLocals) — the callee co-owns and
        // drops the shared element refs, so the caller must not (the parser
        // $args double-free).
        $this->emitReleaseVariant('__mir_array_release_buf', '');
        $this->emitReleaseVariant('__mir_array_release_obj', 'obj');
        $this->emitReleaseVariant('__mir_array_release_str', 'str');
        $this->emitReleaseVariant('__mir_array_release_cell', 'cell');
    }

    /**
     * `__mir_cell_drop(v)` — release the rc payload NaN-boxed into a cell
     * (heterogeneous / `mixed` array element). Dispatches on the 4-bit tag:
     *   tag 4 (string ptr)  → __mir_rc_release_str (self-guards null / immortal)
     *   tag 8 (object ptr)  → __mir_rc_release, but ONLY when the payload is a
     *                         real heap object: guarded on (a) payload > 0xFFFF
     *                         so a boxed enum ORDINAL (a tiny int, no header) is
     *                         never dereferenced, and (b) RC_TAG_MAGIC at ptr-8
     *                         so a #[Struct]/closure ptr (no rc header) is left
     *                         alone. Both are consistent with the retain side
     *                         ({@see EmitLlvm::retainCellPayload} co-owns exactly
     *                         string / obj / union), so drop is symmetric.
     * Arrays (tag 7) → __mir_array_release_cell: a nested array boxed into a cell
     * is always a cell-array (boxToCell rebuilds a concrete one to vec[cell] /
     * boxes an already-cell one by ptr), so release_cell recursively drops its
     * cell elements (via __mir_cell_drop) and frees the buffer at rc 0. It
     * self-guards on ARRAY_TAG_MAGIC. The retain side ({@see
     * EmitLlvm::retainCellPayload}) co-owns a BORROWED cell-array so this release
     * balances (a rebuilt concrete array is a fresh +1 the cell owns outright).
     * Scalars are no-ops.
     */
    private function emitCellDrop(): void
    {
        $fn = $this->module->func('__mir_cell_drop', Type::void());
        $v = $fn->param(Type::i64(), 'v');
        $entry = $fn->block('entry');
        $tagged = $fn->block('tagged');
        $chkobj = $fn->block('chkobj');
        $chkarr = $fn->block('chkarr');
        $doarr = $fn->block('doarr');
        $dostr = $fn->block('dostr');
        $doobj = $fn->block('doobj');
        $chkmagic = $fn->block('chkmagic');
        $dorel = $fn->block('dorel');
        $done = $fn->block('done');

        // istag: a tagged cell has header bits > 0xFFF0000000000000; a raw
        // double (finite / ±Inf / NaN) falls through as a scalar → skip.
        $istag = $entry->icmp('ugt', $v, Value::int(Type::i64(), -4503599627370496));
        $entry->brIf($istag, $tagged, $done);

        $nib = $tagged->and_($tagged->lshr($v, Value::int(Type::i64(), 48)), Value::int(Type::i64(), 15));
        $tagged->brIf($tagged->icmp('eq', $nib, Value::int(Type::i64(), 4)), $dostr, $chkobj);

        // tag 4: string payload.
        $sp = $dostr->inttoptr($dostr->and_($v, Value::int(Type::i64(), 281474976710655)), Type::ptr());
        $dostr->call('__mir_rc_release_str', Type::void(), [$sp]);
        $dostr->br($done);

        // tag 8: object payload (guarded).
        $chkobj->brIf($chkobj->icmp('eq', $nib, Value::int(Type::i64(), 8)), $doobj, $chkarr);
        $op = $doobj->and_($v, Value::int(Type::i64(), 281474976710655));
        $doobj->brIf($doobj->icmp('ugt', $op, Value::int(Type::i64(), 65535)), $chkmagic, $done);
        $opp = $chkmagic->inttoptr($op, Type::ptr());
        $hdr = $chkmagic->load(Type::i64(), $chkmagic->gep(Type::i8(), $opp, [Value::int(Type::i64(), -8)]));
        $chkmagic->brIf($chkmagic->icmp('eq', $hdr, Value::int(Type::i64(), MemoryAbi::RC_TAG_MAGIC)), $dorel, $done);
        $dorel->call('__mir_rc_release', Type::void(), [$opp]);
        $dorel->br($done);

        // tag 7: nested array → recursive cell release (self-guards ARRAY_MAGIC).
        $chkarr->brIf($chkarr->icmp('eq', $nib, Value::int(Type::i64(), 7)), $doarr, $done);
        $ap = $doarr->inttoptr($doarr->and_($v, Value::int(Type::i64(), 281474976710655)), Type::ptr());
        $doarr->call('__mir_array_release_cell', Type::void(), [$ap]);
        $doarr->br($done);

        $done->retVoid();
    }

    /** Drop one rc value `v` (i64) as obj / str / cell. No-op for ''. */
    private function emitDropValue(Block $b, Value $v, string $flavor): void
    {
        if ($flavor === '') { return; }
        if ($flavor === 'cell') { $b->call('__mir_cell_drop', Type::void(), [$v]); return; }
        $p = $b->inttoptr($v, Type::ptr());
        $fn = $flavor === 'str' ? '__mir_rc_release_str' : '__mir_rc_release';
        $b->call($fn, Type::void(), [$p]);
    }

    /**
     * Co-own one rc value `v` (i64) as obj / str / cell — the mirror of
     * {@see emitDropValue}. No-op for ''.
     *
     * Guarded on `v > 0xFFFF`, exactly as {@see emitCellDrop} guards its object
     * payload: a slot whose STATIC element type says obj/string can still hold a
     * bare scalar at runtime (an erased `array<string,bool>` reaching an _obj
     * flavor), and an rc helper on a `1` reads its header at address -7. The
     * release side has always had the same exposure and simply never
     * dereferenced first; the retain does, so it must guard.
     */
    private function emitRetainValue(FunctionDef $fn, Block $b, Value $v, string $flavor, string $tag): Block
    {
        if ($flavor === '') { return $b; }
        if ($flavor === 'cell') { $b->call('__mir_cell_retain', Type::void(), [$v]); return $b; }
        $fnName = $flavor === 'str' ? '__mir_rc_retain_str' : '__mir_rc_retain';
        $doit = $fn->block('rv_do_' . $tag);
        $skip = $fn->block('rv_skip_' . $tag);
        $b->brIf($b->icmp('ugt', $v, Value::int(Type::i64(), 65535)), $doit, $skip);
        $p = $doit->inttoptr($v, Type::ptr());
        $doit->call($fnName, Type::void(), [$p]);
        $doit->br($skip);
        return $skip;
    }

    /**
     * `__mir_drop_by_repr(val, repr)` — drop one element by its runtime repr
     * code (the flags word's element-repr bits, masked but still shifted):
     * STR → string rc, OBJ → object rc (guarded), ARR → nested array release,
     * CELL → tag-dispatch drop. RAW / anything else → no-op. This is how the
     * plain repr-mode release drops an ERASED homogeneous array's elements
     * without a compile-time flavor guess.
     */
    private function emitDropByRepr(): void
    {
        $fn = $this->module->func('__mir_drop_by_repr', Type::void());
        $val = $fn->param(Type::i64(), 'val');
        $repr = $fn->param(Type::i64(), 'repr');
        $e = $fn->block('entry');
        $chkobj = $fn->block('chkobj');
        $chkarr = $fn->block('chkarr');
        $chkcell = $fn->block('chkcell');
        $dostr = $fn->block('dostr');
        $doobj = $fn->block('doobj');
        $doobjr = $fn->block('doobjr');
        $doarr = $fn->block('doarr');
        $docell = $fn->block('docell');
        $done = $fn->block('done');
        $e->brIf($e->icmp('eq', $repr, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_STR)), $dostr, $chkobj);
        $dostr->call('__mir_rc_release_str', Type::void(), [$dostr->inttoptr($val, Type::ptr())]);
        $dostr->br($done);
        $chkobj->brIf($chkobj->icmp('eq', $repr, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_OBJ)), $doobj, $chkarr);
        // A raw scalar sentinel (an erased obj slot holding a bare int) has no rc
        // header — guard before the deref, exactly as emitRetainValue does.
        $doobj->brIf($doobj->icmp('ugt', $val, Value::int(Type::i64(), 65535)), $doobjr, $done);
        $doobjr->call('__mir_rc_release', Type::void(), [$doobjr->inttoptr($val, Type::ptr())]);
        $doobjr->br($done);
        $chkarr->brIf($chkarr->icmp('eq', $repr, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_ARR)), $doarr, $chkcell);
        // __mir_array_release self-guards ARRAY_TAG_MAGIC (safe on a non-array).
        $doarr->call('__mir_array_release', Type::void(), [$doarr->inttoptr($val, Type::ptr())]);
        $doarr->br($done);
        $chkcell->brIf($chkcell->icmp('eq', $repr, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_CELL)), $docell, $done);
        $docell->call('__mir_cell_drop', Type::void(), [$val]);
        $docell->br($done);
        $done->retVoid();
    }

    /** `__mir_retain_by_repr(val, repr)` — co-own one element by its repr code,
     *  the exact mirror of {@see emitDropByRepr}. */
    private function emitRetainByRepr(): void
    {
        $fn = $this->module->func('__mir_retain_by_repr', Type::void());
        $val = $fn->param(Type::i64(), 'val');
        $repr = $fn->param(Type::i64(), 'repr');
        $e = $fn->block('entry');
        $chkobj = $fn->block('chkobj');
        $chkarr = $fn->block('chkarr');
        $chkcell = $fn->block('chkcell');
        $dostr = $fn->block('dostr');
        $doobj = $fn->block('doobj');
        $doobjr = $fn->block('doobjr');
        $doarr = $fn->block('doarr');
        $docell = $fn->block('docell');
        $done = $fn->block('done');
        $e->brIf($e->icmp('eq', $repr, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_STR)), $dostr, $chkobj);
        $dostr->call('__mir_rc_retain_str', Type::void(), [$dostr->inttoptr($val, Type::ptr())]);
        $dostr->br($done);
        $chkobj->brIf($chkobj->icmp('eq', $repr, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_OBJ)), $doobj, $chkarr);
        $doobj->brIf($doobj->icmp('ugt', $val, Value::int(Type::i64(), 65535)), $doobjr, $done);
        $doobjr->call('__mir_rc_retain', Type::void(), [$doobjr->inttoptr($val, Type::ptr())]);
        $doobjr->br($done);
        $chkarr->brIf($chkarr->icmp('eq', $repr, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_ARR)), $doarr, $chkcell);
        $doarr->call('__mir_array_retain', Type::void(), [$doarr->inttoptr($val, Type::ptr())]);
        $doarr->br($done);
        $chkcell->brIf($chkcell->icmp('eq', $repr, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_CELL)), $docell, $done);
        $docell->call('__mir_cell_retain', Type::void(), [$val]);
        $docell->br($done);
        $done->retVoid();
    }

    /**
     * `__mir_cell_retain(v)` — the exact mirror of {@see emitCellDrop}: co-own
     * the rc payload NaN-boxed into a cell, with the same tag dispatch and the
     * same guards (enum ordinal / #[Struct] / closure have no rc header and are
     * left alone). Used by the cow variants, which must retain precisely what
     * the matching release variant drops.
     */
    private function emitCellRetain(): void
    {
        $fn = $this->module->func('__mir_cell_retain', Type::void());
        $v = $fn->param(Type::i64(), 'v');
        $entry = $fn->block('entry');
        $tagged = $fn->block('tagged');
        $chkobj = $fn->block('chkobj');
        $chkarr = $fn->block('chkarr');
        $doarr = $fn->block('doarr');
        $dostr = $fn->block('dostr');
        $doobj = $fn->block('doobj');
        $chkmagic = $fn->block('chkmagic');
        $doret = $fn->block('doret');
        $done = $fn->block('done');

        $istag = $entry->icmp('ugt', $v, Value::int(Type::i64(), -4503599627370496));
        $entry->brIf($istag, $tagged, $done);

        $nib = $tagged->and_($tagged->lshr($v, Value::int(Type::i64(), 48)), Value::int(Type::i64(), 15));
        $tagged->brIf($tagged->icmp('eq', $nib, Value::int(Type::i64(), 4)), $dostr, $chkobj);

        $sp = $dostr->inttoptr($dostr->and_($v, Value::int(Type::i64(), 281474976710655)), Type::ptr());
        $dostr->call('__mir_rc_retain_str', Type::void(), [$sp]);
        $dostr->br($done);

        $chkobj->brIf($chkobj->icmp('eq', $nib, Value::int(Type::i64(), 8)), $doobj, $chkarr);
        $op = $doobj->and_($v, Value::int(Type::i64(), 281474976710655));
        $doobj->brIf($doobj->icmp('ugt', $op, Value::int(Type::i64(), 65535)), $chkmagic, $done);
        $opp = $chkmagic->inttoptr($op, Type::ptr());
        $hdr = $chkmagic->load(Type::i64(), $chkmagic->gep(Type::i8(), $opp, [Value::int(Type::i64(), -8)]));
        $chkmagic->brIf($chkmagic->icmp('eq', $hdr, Value::int(Type::i64(), MemoryAbi::RC_TAG_MAGIC)), $doret, $done);
        $doret->call('__mir_rc_retain', Type::void(), [$opp]);
        $doret->br($done);

        $chkarr->brIf($chkarr->icmp('eq', $nib, Value::int(Type::i64(), 7)), $doarr, $done);
        $ap = $doarr->inttoptr($doarr->and_($v, Value::int(Type::i64(), 281474976710655)), Type::ptr());
        $doarr->call('__mir_array_retain', Type::void(), [$ap]);
        $doarr->br($done);

        $done->retVoid();
    }

    private function emitReleaseVariant(string $symbol, string $valueFlavor): void
    {
        $fn = $this->module->func($symbol, Type::void());
        $arr = $fn->param(Type::ptr(), 'arr');
        $entry = $fn->block('entry');
        $cont = $fn->block('cont');
        $skipNull = $fn->block('skip_null');
        $bail = $fn->block('bail');
        $dec = $fn->block('dec');
        $free = $fn->block('free');
        $keep = $fn->block('keep');

        $entry->brIf($entry->icmp('eq', $arr, Value::null()), $skipNull, $cont);
        $skipNull->retVoid();

        $tag = $cont->load(Type::i64(), $cont->gep(Type::i8(), $arr, [Value::int(Type::i64(), MemoryAbi::RC_TAG_OFFSET)]));
        $cont->brIf($cont->icmp('eq', $tag, Value::int(Type::i64(), MemoryAbi::ARRAY_TAG_MAGIC)), $dec, $bail);
        $bail->retVoid();

        $rcAddr = $dec->gep(Type::i8(), $arr, [Value::int(Type::i64(), MemoryAbi::ARRAY_RC_OFFSET)]);
        $cur = $dec->load(Type::i64(), $rcAddr);
        if (Debug::$verify) {
            $ok = $fn->block('rc_ok');
            $bad = $fn->block('rc_bad');
            $dec->brIf($dec->icmp('sle', $cur, Value::int(Type::i64(), 0)), $bad, $ok);
            $fmt = $this->module->anonString(
                "[VERIFY] array_release: rc <= 0 (double release / UAF) arr=%p rc=%lld\n"
            );
            $bad->call('dprintf', Type::i32(), [Value::int(Type::i32(), 2), $fmt, $arr, $cur], null, '(i32, ptr, ...)');
            $bad->call('abort', Type::void(), []);
            $bad->unreachable();
            $dec = $ok;
            $rcAddr = $dec->gep(Type::i8(), $arr, [Value::int(Type::i64(), MemoryAbi::ARRAY_RC_OFFSET)]);
            $cur = $dec->load(Type::i64(), $rcAddr);
        }
        $next = $dec->sub($cur, Value::int(Type::i64(), 1));
        $dec->store($next, $rcAddr);
        $dec->brIf($dec->icmp('sle', $next, Value::int(Type::i64(), 0)), $free, $keep);
        $keep->retVoid();

        // ── free path: drop elements (mode-driven), then free buffer ──
        $freeb = $fn->block('freeb');
        $len = $free->load(Type::i64(), $arr);
        $flags = $free->load(Type::i64(), $this->hdr($free, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $isH = $free->icmp('ne', $this->hashedBit($free, $flags), Value::int(Type::i64(), 0));
        $iSlot = $free->alloca(Type::i64(), 'di');
        $free->store(Value::int(Type::i64(), 0), $iSlot);

        // 'repr' mode (the plain __mir_array_release): the element repr is read
        // from the flags word at runtime — bits set ⇒ elements are boxed cells,
        // dropped by tag dispatch (__mir_cell_drop). Bits clear ⇒ raw scalars,
        // nothing to drop. This is how an ERASED array (typed vec[unknown] at
        // every static site) still frees its elements: the array records its own
        // repr, independent of which alias releases it.
        $repr = ($valueFlavor === 'repr');
        $reprBits = $repr ? $free->and_($flags, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_MASK)) : null;
        $hasCells = $repr ? $free->icmp('ne', $reprBits, Value::int(Type::i64(), 0)) : null;

        $hhead = $fn->block('hhead');
        if ($valueFlavor === '') {
            // PACKED scalar: nothing to drop → straight to free. HASHED: drop keys.
            $free->brIf($isH, $hhead, $freeb);
        } elseif ($repr) {
            // PACKED: walk slots only when a repr is stamped. HASHED: always walk
            // keys; the per-value drop below is gated on the repr.
            $phead = $fn->block('phead');
            $pbody = $fn->block('pbody');
            $pgate = $fn->block('pgate');
            $free->brIf($isH, $hhead, $pgate);
            $pgate->brIf($hasCells, $phead, $freeb);
            $pi = $phead->load(Type::i64(), $iSlot);
            $phead->brIf($phead->icmp('sge', $pi, $len), $freeb, $pbody);
            $pv = $pbody->load(Type::i64(), $this->packedSlot($pbody, $arr, $pi));
            $pbody->call('__mir_drop_by_repr', Type::void(), [$pv, $reprBits]);
            $pbody->store($pbody->add($pi, Value::int(Type::i64(), 1)), $iSlot);
            $pbody->br($phead);
        } else {
            // PACKED: drop each value. HASHED: drop keys + values.
            $phead = $fn->block('phead');
            $pbody = $fn->block('pbody');
            $free->brIf($isH, $hhead, $phead);
            $pi = $phead->load(Type::i64(), $iSlot);
            $phead->brIf($phead->icmp('sge', $pi, $len), $freeb, $pbody);
            $pv = $pbody->load(Type::i64(), $this->packedSlot($pbody, $arr, $pi));
            $this->emitDropValue($pbody, $pv, $valueFlavor);
            $pbody->store($pbody->add($pi, Value::int(Type::i64(), 1)), $iSlot);
            $pbody->br($phead);
        }

        // HASHED walk: drop string keys (always) + values (if flavor).
        // A TOMBSTONE (KIND_DELETED) entry is skipped entirely — its key/value
        // were abandoned at unset (the pre-existing Stage-3 leak), so dropping
        // them here would be a double-free (compaction may already have run) or
        // an rc drop of an overwritten slot.
        $hbody = $fn->block('hbody');
        $hlive = $fn->block('hlive');
        $hkey  = $fn->block('hkey');
        $hval  = $fn->block('hval');
        $hskip = $fn->block('hskip');
        $hadv  = $fn->block('hadv');
        $hi = $hhead->load(Type::i64(), $iSlot);
        $hhead->brIf($hhead->icmp('sge', $hi, $len), $freeb, $hbody);
        $kind = $hbody->load(Type::i64(), $this->entryAddr($hbody, $arr, $hi, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $hbody->brIf($hbody->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_DELETED)), $hskip, $hlive);
        $hskip->br($hadv);
        $hlive->brIf($hlive->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $hkey, $hval);
        $kp = $hkey->load(Type::ptr(), $this->entryAddr($hkey, $arr, $hi, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $hkey->call('__mir_rc_release_str', Type::void(), [$kp]);
        $hkey->br($hval);
        if ($repr) {
            $hvdrop = $fn->block('hvdrop');
            $hval->brIf($hasCells, $hvdrop, $hadv);
            $vv = $hvdrop->load(Type::i64(), $this->entryAddr($hvdrop, $arr, $hi, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
            $hvdrop->call('__mir_drop_by_repr', Type::void(), [$vv, $reprBits]);
            $hvdrop->br($hadv);
        } else {
            if ($valueFlavor !== '') {
                $vv = $hval->load(Type::i64(), $this->entryAddr($hval, $arr, $hi, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
                $this->emitDropValue($hval, $vv, $valueFlavor);
            }
            $hval->br($hadv);
        }
        $hadv->store($hadv->add($hi, Value::int(Type::i64(), 1)), $iSlot);
        $hadv->br($hhead);

        // Free the hashed bucket side-array if present (PACKED has null here).
        $bptr = $freeb->load(Type::ptr(), $this->hdr($freeb, $arr, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET));
        $hasBuckets = $fn->block('has_buckets');
        $doFree = $fn->block('do_free');
        $freeb->brIf($freeb->icmp('ne', $bptr, Value::null()), $hasBuckets, $doFree);
        $hasBuckets->call('free', Type::void(), [$bptr]);
        $hasBuckets->br($doFree);
        // Free the tagged base (ptr-8), not the data ptr.
        $base = $doFree->gep(Type::i8(), $arr, [Value::int(Type::i64(), MemoryAbi::RC_TAG_OFFSET)]);
        $doFree->call('free', Type::void(), [$base]);
        $doFree->retVoid();
    }

    /**
     * `__mir_array_is_hashed(arr) -> i64` — 1 if HASHED, 0 if PACKED or
     * NULL. (Stage 1 uses only flags bit 0, so flags != 0 == HASHED.)
     */
    private function emitIsHashed(): void
    {
        $fn = $this->module->func('__mir_array_is_hashed', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $z = $fn->block('z');
        $chk = $fn->block('chk');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $z, $chk);
        $z->ret(Value::int(Type::i64(), 0));
        $flags = $chk->load(Type::i64(), $this->hdr($chk, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $chk->ret($chk->zext($chk->icmp('ne', $this->hashedBit($chk, $flags), Value::int(Type::i64(), 0)), Type::i64()));
    }

    /**
     * `__mir_array_get_int(arr, idx) -> i64`. PACKED: bounds-checked
     * slot load. HASHED: linear scan for a KIND_INT entry with key==idx.
     * Miss / NULL / OOB → 0.
     */
    private function emitGetInt(): void
    {
        $fn = $this->module->func('__mir_array_get_int', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $idx = $fn->param(Type::i64(), 'idx');
        $e = $fn->block('entry');
        $retzero = $fn->block('retzero');
        $chk = $fn->block('chk');
        $packed = $fn->block('packed');
        $pload = $fn->block('pload');
        $doidx = $fn->block('doidx');
        $chkmiss = $fn->block('chkmiss');
        $idxhit = $fn->block('idxhit');
        $head = $fn->block('head');
        $body = $fn->block('body');
        $kok = $fn->block('kind_ok');
        $next = $fn->block('next');
        $hit = $fn->block('hit');

        $e->brIf($e->icmp('eq', $arr, Value::null()), $retzero, $chk);
        $flags = $chk->load(Type::i64(), $this->hdr($chk, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $len = $chk->load(Type::i64(), $arr);
        $iSlot = $chk->alloca(Type::i64(), 'i');
        $rSlot = $chk->alloca(Type::i64(), 'r');
        $chk->store(Value::int(Type::i64(), 0), $iSlot);
        $chk->brIf($chk->icmp('ne', $this->hashedBit($chk, $flags), Value::int(Type::i64(), 0)), $doidx, $packed);

        // PACKED: idx in [0,len) ?
        $oobLo = $packed->icmp('slt', $idx, Value::int(Type::i64(), 0));
        $oobHi = $packed->icmp('sge', $idx, $len);
        $packed->brIf($packed->or_($oobLo, $oobHi), $retzero, $pload);
        $pload->ret($pload->load(Type::i64(), $this->packedSlot($pload, $arr, $idx)));

        // HASHED index fast path: -2 → linear, -1 → miss, else hit.
        $rf = $doidx->call('__mir_array_index_find', Type::i64(),
            [$arr, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_INT), Value::null(), $idx, Value::int(Type::i64(), 0), Value::int(Type::i64(), 0)]);
        $doidx->store($rf, $rSlot);
        $doidx->brIf($doidx->icmp('eq', $rf, Value::int(Type::i64(), -2)), $head, $chkmiss);
        $chkmiss->brIf($chkmiss->icmp('eq', $chkmiss->load(Type::i64(), $rSlot), Value::int(Type::i64(), -1)), $retzero, $idxhit);
        $ij = $idxhit->load(Type::i64(), $rSlot);
        $idxhit->ret($idxhit->load(Type::i64(), $this->entryAddr($idxhit, $arr, $ij, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET)));

        // HASHED linear scan for int key.
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $retzero, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $arr, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_INT)), $next, $kok);
        $k = $kok->load(Type::i64(), $this->entryAddr($kok, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->icmp('eq', $k, $idx), $hit, $next);
        $next->store($next->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $next->br($head);
        $hit->ret($hit->load(Type::i64(), $this->entryAddr($hit, $arr, $i, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET)));
        $retzero->ret(Value::int(Type::i64(), 0));
    }

    /**
     * `__mir_array_get_str(arr, key) -> i64`. PACKED has no string keys
     * → 0. HASHED: linear strcmp scan. Miss / NULL → 0.
     */
    private function emitGetStr(): void
    {
        $fn = $this->module->func('__mir_array_get_str', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $key = $fn->param(Type::ptr(), 'key');
        $hash = $fn->param(Type::i64(), 'hash');
        $haveHash = $fn->param(Type::i64(), 'haveHash');
        $e = $fn->block('entry');
        $retzero = $fn->block('retzero');
        $chk = $fn->block('chk');
        $gate = $fn->block('gate');
        $doidx = $fn->block('doidx');
        $chkmiss = $fn->block('chkmiss');
        $idxhit = $fn->block('idxhit');
        $preh = $fn->block('preh');
        $head = $fn->block('head');
        $body = $fn->block('body');
        $kok = $fn->block('kind_ok');
        $hpre = $fn->block('hpre');
        $cmp = $fn->block('cmp');
        $next = $fn->block('next');
        $hit = $fn->block('hit');

        $e->brIf($e->icmp('eq', $arr, Value::null()), $retzero, $chk);
        $flags = $chk->load(Type::i64(), $this->hdr($chk, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $len = $chk->load(Type::i64(), $arr);
        $iSlot = $chk->alloca(Type::i64(), 'i');
        $rSlot = $chk->alloca(Type::i64(), 'r');
        $effSlot = $chk->alloca(Type::i64(), 'effh');
        $chk->store(Value::int(Type::i64(), 0), $iSlot);
        $chk->brIf($chk->icmp('eq', $this->hashedBit($chk, $flags), Value::int(Type::i64(), 0)), $retzero, $gate);

        // Index fast path: a null key never matches; else find returns -2
        // (small map → linear scan), -1 (miss), or the entry index (hit).
        $gate->brIf($gate->icmp('eq', $key, Value::null()), $retzero, $doidx);
        $rf = $doidx->call('__mir_array_index_find', Type::i64(),
            [$arr, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING), $key, Value::int(Type::i64(), 0), $hash, $haveHash]);
        $doidx->store($rf, $rSlot);
        $doidx->brIf($doidx->icmp('eq', $rf, Value::int(Type::i64(), -2)), $preh, $chkmiss);
        $chkmiss->brIf($chkmiss->icmp('eq', $chkmiss->load(Type::i64(), $rSlot), Value::int(Type::i64(), -1)), $retzero, $idxhit);
        $ij = $idxhit->load(Type::i64(), $rSlot);
        $idxhit->ret($idxhit->load(Type::i64(), $this->entryAddr($idxhit, $arr, $ij, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET)));

        $preh->store($this->scanProbeHash($preh, $key, $hash, $haveHash), $effSlot);
        $preh->br($head);
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $retzero, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $arr, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $next, $kok);
        $tk = $kok->load(Type::ptr(), $this->entryAddr($kok, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->or_($kok->icmp('eq', $tk, Value::null()), $kok->icmp('eq', $key, Value::null())), $next, $hpre);
        $this->hashPrefilter($hpre, $tk, $effSlot, $cmp, $next);
        $cmp->brIf($cmp->call('__mir_str_eq', Type::i1(), [$tk, $key]), $hit, $next);
        $next->store($next->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $next->br($head);
        $hit->ret($hit->load(Type::i64(), $this->entryAddr($hit, $arr, $i, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET)));
        $retzero->ret(Value::int(Type::i64(), 0));
    }

    /**
     * `__mir_array_alloc_hashed(cap) -> ptr` — allocate an array that is ALREADY
     * hashed, with room for `cap` entries. Header zeroed, capacity set, rc = 1,
     * flags = HASHED, no bucket index yet (built lazily, exactly as after a
     * promote).
     *
     * A literal whose keys are string CONSTANTS (`["id" => …, "name" => …]`)
     * knows its shape at compile time, but was built packed and then promoted by
     * its first `set_str` — a second allocation, a copy of everything inserted so
     * far, and a free, on every construction. Allocating hashed up front lands in
     * the identical end state with one allocation. {@see emitPromote} stays the
     * path for an array that only turns out to need hashing at runtime.
     */
    private function emitAllocHashed(): void
    {
        $fn = $this->module->func('__mir_array_alloc_hashed', Type::ptr());
        $capIn = $fn->param(Type::i64(), 'cap');
        $b = $fn->block('entry');
        // Match promote's floor of 4 so a small literal has the same headroom.
        $neg = $b->icmp('slt', $capIn, Value::int(Type::i64(), 4));
        $cap = $b->select($neg, Value::int(Type::i64(), 4), $capIn);
        $bytes = $b->add(
            $b->mul($cap, Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE)),
            Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE),
        );
        $arr = $b->call('__mir_alloc_array_tagged', Type::ptr(), [$bytes]);
        $b->call('memset', Type::ptr(), [
            $arr,
            Value::int(Type::i32(), 0),
            Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE),
        ]);
        $b->store($cap, $this->hdr($b, $arr, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $b->store(Value::int(Type::i64(), 1), $this->hdr($b, $arr, MemoryAbi::ARRAY_RC_OFFSET));
        $b->store(
            Value::int(Type::i64(), MemoryAbi::ARRAY_FLAG_HASHED),
            $this->hdr($b, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET),
        );
        $b->ret($arr);
    }

    /**
     * `__mir_array_promote(arr) -> ptr` — PACKED → HASHED. Allocate a
     * fresh hashed buffer, copy each packed value v at index k as an
     * int-keyed entry {KIND_INT, k, v}, preserve len / rc / next_int,
     * free the old packed base, return the new buffer. Caller must hold
     * a non-shared (rc==1) PACKED buffer (set_str cows first).
     */
    private function emitPromote(): void
    {
        $fn = $this->module->func('__mir_array_promote', Type::ptr());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $head = $fn->block('head');
        $body = $fn->block('body');
        $done = $fn->block('done');

        $len = $e->load(Type::i64(), $arr);
        $rc = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::ARRAY_RC_OFFSET));
        $min = Value::int(Type::i64(), 4);
        $newcap = $e->select($e->icmp('slt', $len, $min), $min, $len);
        $bytes = $e->add(
            $e->mul($newcap, Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE)),
            Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE),
        );
        // An arena PACKED source stays arena after promotion (still confined) —
        // allocate the hashed buffer from the arena and DON'T free the old
        // (arena-abandoned; bulk-freed at scope exit). Detect via the source tag.
        if (Debug::$arenaArrays) {
            $pa = $fn->block('promote_arena');
            $ph = $fn->block('promote_heap');
            $pd = $fn->block('promote_alloced');
            $nuSlot = $e->alloca(Type::ptr(), 'nu_slot');
            $ptag = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::RC_TAG_OFFSET));
            $e->brIf($e->icmp('eq', $ptag, Value::int(Type::i64(), MemoryAbi::ARRAY_TAG_ARENA)), $pa, $ph);
            $pa->store($pa->call('__mir_alloc_array_tagged_arena', Type::ptr(), [$bytes]), $nuSlot);
            $pa->br($pd);
            $ph->store($ph->call('__mir_alloc_array_tagged', Type::ptr(), [$bytes]), $nuSlot);
            $ph->br($pd);
            $nu = $pd->load(Type::ptr(), $nuSlot);
            $e = $pd;
        } else {
            $nu = $e->call('__mir_alloc_array_tagged', Type::ptr(), [$bytes]);
        }
        $e->call('memset', Type::ptr(), [$nu, Value::int(Type::i32(), 0), Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE)]);
        $e->store($len, $nu);
        $e->store($newcap, $this->hdr($e, $nu, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $e->store($len, $this->hdr($e, $nu, MemoryAbi::ARRAY_NEXT_INT_OFFSET));
        $e->store($rc, $this->hdr($e, $nu, MemoryAbi::ARRAY_RC_OFFSET));
        // Carry the source's element-repr bits across the packed→hashed promote
        // (OR them onto HASHED) — else a stamped erased array loses its repr on
        // the first string-keyed insert and its release stops dropping.
        $srcFlags = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $srcRepr = $e->and_($srcFlags, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_MASK));
        $e->store($e->or_($srcRepr, Value::int(Type::i64(), MemoryAbi::ARRAY_FLAG_HASHED)), $this->hdr($e, $nu, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $iSlot = $e->alloca(Type::i64(), 'i');
        $e->store(Value::int(Type::i64(), 0), $iSlot);
        $e->br($head);

        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $done, $body);
        $pv = $body->load(Type::i64(), $this->packedSlot($body, $arr, $i));
        $body->store(Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_INT), $this->entryAddr($body, $nu, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->store($i, $this->entryAddr($body, $nu, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $body->store($pv, $this->entryAddr($body, $nu, $i, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
        $body->store($body->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $body->br($head);

        if (Debug::$arenaArrays) {
            // Never free() an arena base — the arena owns it. Skip on the
            // arena tag; the old buffer is abandoned until scope-exit reset.
            $skip = $fn->block('promote_skipfree');
            $dofree = $fn->block('promote_dofree');
            $dtag = $done->load(Type::i64(), $this->hdr($done, $arr, MemoryAbi::RC_TAG_OFFSET));
            $done->brIf($done->icmp('eq', $dtag, Value::int(Type::i64(), MemoryAbi::ARRAY_TAG_ARENA)), $skip, $dofree);
            $base = $dofree->gep(Type::i8(), $arr, [Value::int(Type::i64(), MemoryAbi::RC_TAG_OFFSET)]);
            $dofree->call('free', Type::void(), [$base]);
            $dofree->br($skip);
            $skip->ret($nu);
            return;
        }
        $base = $done->gep(Type::i8(), $arr, [Value::int(Type::i64(), MemoryAbi::RC_TAG_OFFSET)]);
        $done->call('free', Type::void(), [$base]);
        $done->ret($nu);
    }

    /**
     * `__mir_array_deimmortal(arr) -> ptr` — the immortal empty-array singleton
     * (rc saturated to {@see MemoryAbi::IMMORTAL_ARRAY_RC}) is never malloc'd, so
     * any in-place mutator that frees / reallocs / promotes it would corrupt a
     * value shared by every empty `[]` in the program (or abort in libmalloc).
     * The singleton is the ONLY immortal array and is ALWAYS empty, so
     * "separating" it is just a fresh `alloc(0)`. Called at the entry of every
     * in-place mutator ({@see emitSetInt} / {@see emitSetStr} / {@see emitUnshift})
     * so their result — which the caller stores back into the slot — is a private
     * rc=1 buffer while the singleton stays pristine. Real arrays (rc far below
     * the sentinel) pass straight through. Emitted only under
     * {@see \Compile\Debug::$emptyArraySingleton}.
     *
     * A NULL arr also returns a fresh `alloc(0)` — an int-keyed nested auto-viv
     * `$a[0][1]=v` reads the missing `$a[0]` as null, and set_int would else
     * dereference null (crash at flags@32). set_str already auto-allocates from
     * null; this gives set_int / unshift the same null-safety.
     */
    private function emitDeimmortal(): void
    {
        $fn = $this->module->func('__mir_array_deimmortal', Type::ptr());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $chk = $fn->block('chk');
        $fresh = $fn->block('fresh');
        $keep = $fn->block('keep');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $fresh, $chk);
        $rc = $chk->load(Type::i64(), $this->hdr($chk, $arr, MemoryAbi::ARRAY_RC_OFFSET));
        $chk->brIf(
            $chk->icmp('sgt', $rc, Value::int(Type::i64(), 1 << 61)),
            $fresh,
            $keep,
        );
        $fresh->ret($fresh->call('__mir_array_alloc', Type::ptr(), [Value::int(Type::i64(), 0)]));
        $keep->ret($arr);
    }

    /**
     * `__mir_array_set_int(arr, idx, val) -> ptr`. PACKED: in-bounds
     * overwrite, or append-at-len with grow, or (sparse idx > len)
     * promote to HASHED then int-insert. HASHED: int-keyed insert.
     * Returns the (possibly relocated / promoted) buffer.
     */
    private function emitSetInt(): void
    {
        $fn = $this->module->func('__mir_array_set_int', Type::ptr());
        $arr = $fn->param(Type::ptr(), 'arr');
        $idx = $fn->param(Type::i64(), 'idx');
        $val = $fn->param(Type::i64(), 'val');
        $e = $fn->block('entry');
        $packed = $fn->block('packed');
        $inb = $fn->block('inbounds');
        $atlen = $fn->block('at_len');
        $append = $fn->block('p_append');
        $grow = $fn->block('p_grow');
        $store = $fn->block('p_store');
        $sparse = $fn->block('sparse');
        $hashed = $fn->block('hashed');

        $arrSlot = $e->alloca(Type::ptr(), 'arr_slot');
        if (Debug::$emptyArraySingleton) { $arr = $e->call('__mir_array_deimmortal', Type::ptr(), [$arr]); }
        $e->store($arr, $arrSlot);
        // No index drop here: a PACKED array never carries an index, a HASHED
        // update keeps the entry set's shape, and the hashed append maintains
        // the index incrementally (index_add) — see emitHashedIntInsert.
        $flags = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $e->brIf($e->icmp('ne', $this->hashedBit($e, $flags), Value::int(Type::i64(), 0)), $hashed, $packed);

        // PACKED
        $len = $packed->load(Type::i64(), $arr);
        $packed->brIf($packed->icmp('slt', $idx, $len), $inb, $atlen);
        $inb->store($val, $this->packedSlot($inb, $arr, $idx));
        $inb->ret($arr);
        // idx >= len: append iff idx == len, else sparse-promote
        $atlen->brIf($atlen->icmp('eq', $idx, $len), $append, $sparse);
        // append: grow if full
        $cap = $append->load(Type::i64(), $this->hdr($append, $arr, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $append->brIf($append->icmp('sge', $len, $cap), $grow, $store);
        $double = $grow->mul($cap, Value::int(Type::i64(), 2));
        $newcap = $grow->select($grow->icmp('slt', $double, Value::int(Type::i64(), 4)), Value::int(Type::i64(), 4), $double);
        $newBytes = $grow->add($grow->mul($newcap, Value::int(Type::i64(), MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE)), Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE));
        $reloc = $grow->call('__mir_realloc_tagged', Type::ptr(), [$arr, $newBytes]);
        $grow->store($newcap, $this->hdr($grow, $reloc, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $grow->store($reloc, $arrSlot);
        $grow->br($store);
        $buf = $store->load(Type::ptr(), $arrSlot);
        $store->store($val, $this->packedSlot($store, $buf, $len));
        $nl = $store->add($len, Value::int(Type::i64(), 1));
        $store->store($nl, $buf);
        $store->store($nl, $this->hdr($store, $buf, MemoryAbi::ARRAY_NEXT_INT_OFFSET));
        $store->ret($buf);
        // sparse: promote then int-insert via hashed path
        $prom = $sparse->call('__mir_array_promote', Type::ptr(), [$arr]);
        $sparse->store($prom, $arrSlot);
        $sparse->br($hashed);

        $this->emitHashedIntInsert($fn, $hashed, $arrSlot, $idx, $val);
    }

    /**
     * Shared HASHED int-keyed insert tail: update on key hit, else
     * append a {KIND_INT, idx, val} entry (grow on full). `arrSlot`
     * holds the current hashed buffer. Terminates the function.
     */
    private function emitHashedIntInsert(FunctionDef $fn, \Codegen\Llvm\Block $head0, Value $arrSlot, Value $idx, Value $val): void
    {
        $iSlot = $head0->alloca(Type::i64(), 'hi');
        $head0->store(Value::int(Type::i64(), 0), $iSlot);
        $head = $fn->block($this->host->rtFreshLabel('hi_head'));
        $body = $fn->block($this->host->rtFreshLabel('hi_body'));
        $kok = $fn->block($this->host->rtFreshLabel('hi_kok'));
        $upd = $fn->block($this->host->rtFreshLabel('hi_upd'));
        $next = $fn->block($this->host->rtFreshLabel('hi_next'));
        $app = $fn->block($this->host->rtFreshLabel('hi_app'));
        $grow = $fn->block($this->host->rtFreshLabel('hi_grow'));
        $store = $fn->block($this->host->rtFreshLabel('hi_store'));
        // Fast append (zend_hash_index_add_new style): a key at/above next_int is
        // guaranteed absent (next_int = 1 + the max int key ever inserted), so
        // skip the O(n) existence scan and append directly — this is what makes a
        // sparse-int build (array_filter, increasing-key fill) O(n) not O(n²). A
        // lower key may already exist → bucket-index locate (-2 small → linear
        // scan; -1 absent → append; hit → update in place, index untouched).
        $ihx = $fn->block($this->host->rtFreshLabel('hi_ihx'));
        $ihr = $fn->block($this->host->rtFreshLabel('hi_ihr'));
        $ihu = $fn->block($this->host->rtFreshLabel('hi_ihu'));
        $cur0 = $head0->load(Type::ptr(), $arrSlot);
        $ni0 = $head0->load(Type::i64(), $this->hdr($head0, $cur0, MemoryAbi::ARRAY_NEXT_INT_OFFSET));
        $head0->brIf($head0->icmp('sge', $idx, $ni0), $app, $ihx);
        $rfH = $ihx->call('__mir_array_index_find', Type::i64(),
            [$cur0, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_INT), Value::null(), $idx,
             Value::int(Type::i64(), 0), Value::int(Type::i64(), 0)]);
        $ihx->brIf($ihx->icmp('eq', $rfH, Value::int(Type::i64(), -2)), $head, $ihr);
        $ihr->brIf($ihr->icmp('slt', $rfH, Value::int(Type::i64(), 0)), $app, $ihu);
        $ihu->store($val, $this->entryAddr($ihu, $cur0, $rfH, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
        $ihu->ret($cur0);

        $cur = $head->load(Type::ptr(), $arrSlot);
        $len = $head->load(Type::i64(), $cur);
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $app, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $cur, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_INT)), $next, $kok);
        $k = $kok->load(Type::i64(), $this->entryAddr($kok, $cur, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->icmp('eq', $k, $idx), $upd, $next);
        $upd->store($val, $this->entryAddr($upd, $cur, $i, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
        $upd->ret($cur);
        $next->store($next->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $next->br($head);

        // append — reached from the linear-scan end ($head) AND the fast-append
        // early-out (head0, which bypasses $head), so load cur/len fresh from
        // arrSlot rather than reusing $head's SSA values (which don't dominate
        // the early-out edge).
        $acur = $app->load(Type::ptr(), $arrSlot);
        $alen = $app->load(Type::i64(), $acur);
        $cap = $app->load(Type::i64(), $this->hdr($app, $acur, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $app->brIf($app->icmp('sge', $alen, $cap), $grow, $store);
        $double = $grow->mul($cap, Value::int(Type::i64(), 2));
        $newcap = $grow->select($grow->icmp('slt', $double, Value::int(Type::i64(), 4)), Value::int(Type::i64(), 4), $double);
        $newBytes = $grow->add($grow->mul($newcap, Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE)), Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE));
        $reloc = $grow->call('__mir_realloc_tagged', Type::ptr(), [$acur, $newBytes]);
        $grow->store($newcap, $this->hdr($grow, $reloc, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $grow->store($reloc, $arrSlot);
        $grow->br($store);
        $buf = $store->load(Type::ptr(), $arrSlot);
        $blen = $store->load(Type::i64(), $buf);
        $store->store(Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_INT), $this->entryAddr($store, $buf, $blen, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $store->store($idx, $this->entryAddr($store, $buf, $blen, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $store->store($val, $this->entryAddr($store, $buf, $blen, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
        $store->store($store->add($blen, Value::int(Type::i64(), 1)), $buf);
        // Maintain next_int = max(next_int, idx+1) so the fast-append early-out
        // above stays correct for the following inserts.
        $ni = $store->load(Type::i64(), $this->hdr($store, $buf, MemoryAbi::ARRAY_NEXT_INT_OFFSET));
        $cand = $store->add($idx, Value::int(Type::i64(), 1));
        $newni = $store->select($store->icmp('sgt', $cand, $ni), $cand, $ni);
        $store->store($newni, $this->hdr($store, $buf, MemoryAbi::ARRAY_NEXT_INT_OFFSET));
        // Maintain the bucket index incrementally, like set_str's append (no-op
        // when no index is built; drops past load factor for a bigger rebuild).
        $store->call('__mir_array_index_add', Type::void(), [$buf, $blen]);
        $store->ret($buf);
    }

    /**
     * `__mir_array_set_str(arr, key, val) -> ptr`. PACKED is promoted to
     * HASHED first. HASHED: locate the key via the bucket index (O(1) on a
     * large map; linear scan below {@see INDEX_THRESHOLD}); update in place on
     * a hit (index stays valid — no drop), else append a {KIND_STRING, key,
     * val} entry (grow on full) and invalidate the index (shape changed),
     * co-owning the key (guarded retain — immortal keys no-op). Returns the
     * buffer.
     *
     * The index is dropped ONLY on append, not on every set — an update keeps
     * the entry set's shape, so 3M updates over a stable key set stay O(1)
     * each instead of an O(n) linear scan (the assoc hot-path fix).
     */
    private function emitSetStr(): void
    {
        $fn = $this->module->func('__mir_array_set_str', Type::ptr());
        $arr = $fn->param(Type::ptr(), 'arr');
        $key = $fn->param(Type::ptr(), 'key');
        $val = $fn->param(Type::i64(), 'val');
        $hash = $fn->param(Type::i64(), 'hash');
        $haveHash = $fn->param(Type::i64(), 'haveHash');
        $e = $fn->block('entry');
        $maybeProm = $fn->block('maybe_promote');
        $doProm = $fn->block('do_promote');
        $idxtry = $fn->block('s_idxtry');
        $idxchk = $fn->block('s_idxchk');
        $idxupd = $fn->block('s_idxupd');
        $preh = $fn->block('s_preh');
        $head = $fn->block('s_head');
        $body = $fn->block('s_body');
        $kok = $fn->block('s_kok');
        $hpre = $fn->block('s_hpre');
        $cmp = $fn->block('s_cmp');
        $upd = $fn->block('s_upd');
        $next = $fn->block('s_next');
        $app = $fn->block('s_app');
        $grow = $fn->block('s_grow');
        $store = $fn->block('s_store');

        $fresh = $fn->block('s_fresh');
        $chk = $fn->block('s_chk');
        $arrSlot = $e->alloca(Type::ptr(), 'arr_slot');
        $iSlot = $e->alloca(Type::i64(), 'i');
        $rSlot = $e->alloca(Type::i64(), 'r');
        $effSlot = $e->alloca(Type::i64(), 'effh');
        if (Debug::$emptyArraySingleton) { $arr = $e->call('__mir_array_deimmortal', Type::ptr(), [$arr]); }
        $e->store($arr, $arrSlot);
        $e->store(Value::int(Type::i64(), 0), $iSlot);
        // NULL-safe: a null arr (lazy dynprop bag) allocates a fresh packed
        // array first, so set_str doubles as the from-scratch builder.
        $e->brIf($e->icmp('eq', $arr, Value::null()), $fresh, $chk);
        $f = $fresh->call('__mir_array_alloc', Type::ptr(), [Value::int(Type::i64(), 0)]);
        $fresh->store($f, $arrSlot);
        $fresh->br($chk);
        $a0 = $chk->load(Type::ptr(), $arrSlot);
        $flags = $chk->load(Type::i64(), $this->hdr($chk, $a0, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $chk->brIf($chk->icmp('ne', $this->hashedBit($chk, $flags), Value::int(Type::i64(), 0)), $idxtry, $maybeProm);
        // PACKED → promote (rc==1 expected; set_str callers cow upstream)
        $maybeProm->br($doProm);
        $a1 = $doProm->load(Type::ptr(), $arrSlot);
        $prom = $doProm->call('__mir_array_promote', Type::ptr(), [$a1]);
        $doProm->store($prom, $arrSlot);
        $doProm->br($idxtry);

        // Index fast path: -2 (small map) → linear scan; -1 (miss) → append;
        // else (hit) → update the located entry in place, leaving the index
        // intact (no shape change). A null key can't match → straight to append.
        $cidx = $idxtry->load(Type::ptr(), $arrSlot);
        $idxtry->brIf($idxtry->icmp('eq', $key, Value::null()), $app, $idxchk);
        $rf = $idxchk->call('__mir_array_index_find', Type::i64(),
            [$cidx, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING), $key, Value::int(Type::i64(), 0), $hash, $haveHash]);
        $idxchk->store($rf, $rSlot);
        $idxchk->brIf($idxchk->icmp('eq', $rf, Value::int(Type::i64(), -2)), $preh, $idxupd);
        $rv = $idxupd->load(Type::i64(), $rSlot);
        $idxupd->brIf($idxupd->icmp('eq', $rv, Value::int(Type::i64(), -1)), $app, $upd);

        $preh->store($this->scanProbeHash($preh, $key, $hash, $haveHash), $effSlot);
        $preh->br($head);
        $cur = $head->load(Type::ptr(), $arrSlot);
        $len = $head->load(Type::i64(), $cur);
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $app, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $cur, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $next, $kok);
        $tk = $kok->load(Type::ptr(), $this->entryAddr($kok, $cur, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->or_($kok->icmp('eq', $tk, Value::null()), $kok->icmp('eq', $key, Value::null())), $next, $hpre);
        $this->hashPrefilter($hpre, $tk, $effSlot, $cmp, $next);
        // Linear-scan hit: stash the found index in rSlot and converge on $upd
        // (shared with the index-find hit path).
        $cmp->store($i, $rSlot);
        $cmp->brIf($cmp->call('__mir_str_eq', Type::i1(), [$tk, $key]), $upd, $next);
        // Update in place at the located entry (rSlot: index-find hit OR linear
        // hit). No index drop — the entry set's shape is unchanged.
        $ucur = $upd->load(Type::ptr(), $arrSlot);
        $uidx = $upd->load(Type::i64(), $rSlot);
        $upd->store($val, $this->entryAddr($upd, $ucur, $uidx, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
        $upd->ret($ucur);
        $next->store($next->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $next->br($head);

        // append a new string entry. Reached from head (linear-scan end),
        // idxupd (index miss), or idxtry (null key) — so load cur/len fresh
        // from arrSlot rather than reusing head's SSA values. The new entry is
        // added to the bucket index INCREMENTALLY after the store (index_add) —
        // a drop-on-every-append would rebuild O(n) per insert → O(n²) build.
        $acur = $app->load(Type::ptr(), $arrSlot);
        $alen = $app->load(Type::i64(), $acur);
        $cap = $app->load(Type::i64(), $this->hdr($app, $acur, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $app->brIf($app->icmp('sge', $alen, $cap), $grow, $store);
        $double = $grow->mul($cap, Value::int(Type::i64(), 2));
        $newcap = $grow->select($grow->icmp('slt', $double, Value::int(Type::i64(), 4)), Value::int(Type::i64(), 4), $double);
        $newBytes = $grow->add($grow->mul($newcap, Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE)), Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE));
        $reloc = $grow->call('__mir_realloc_tagged', Type::ptr(), [$acur, $newBytes]);
        $grow->store($newcap, $this->hdr($grow, $reloc, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $grow->store($reloc, $arrSlot);
        $grow->br($store);
        $buf = $store->load(Type::ptr(), $arrSlot);
        $blen = $store->load(Type::i64(), $buf);
        $store->call('__mir_rc_retain_str', Type::void(), [$key]);
        $store->store(Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING), $this->entryAddr($store, $buf, $blen, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $store->store($key, $this->entryAddr($store, $buf, $blen, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $store->store($val, $this->entryAddr($store, $buf, $blen, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
        $store->store($store->add($blen, Value::int(Type::i64(), 1)), $buf);
        // Add the new entry to the bucket index incrementally (no-op if no index
        // is built yet; drops past load factor) — keeps a large build O(n).
        $store->call('__mir_array_index_add', Type::void(), [$buf, $blen]);
        $store->ret($buf);
    }

    /**
     * `__mir_array_append(arr, val) -> ptr` — `$a[] = val`. PACKED:
     * append at len (via set_int). HASHED: insert at the next implicit
     * int key. Returns the buffer.
     */
    private function emitAppend(): void
    {
        $fn = $this->module->func('__mir_array_append', Type::ptr());
        $arr = $fn->param(Type::ptr(), 'arr');
        $val = $fn->param(Type::i64(), 'val');
        $e = $fn->block('entry');
        $packed = $fn->block('packed');
        $hashed = $fn->block('hashed');
        // Null-safe (and singleton-safe) like set_int/set_str: a nested append
        // `$a[k][] = v` reads the missing `$a[k]` as null, and the flags load
        // below would dereference it. deimmortal(null) returns a fresh empty.
        if (Debug::$emptyArraySingleton) { $arr = $e->call('__mir_array_deimmortal', Type::ptr(), [$arr]); }
        $flags = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $e->brIf($e->icmp('ne', $this->hashedBit($e, $flags), Value::int(Type::i64(), 0)), $hashed, $packed);
        $len = $packed->load(Type::i64(), $arr);
        $packed->ret($packed->call('__mir_array_set_int', Type::ptr(), [$arr, $len, $val]));
        $ni = $hashed->load(Type::i64(), $this->hdr($hashed, $arr, MemoryAbi::ARRAY_NEXT_INT_OFFSET));
        $hashed->store($hashed->add($ni, Value::int(Type::i64(), 1)), $this->hdr($hashed, $arr, MemoryAbi::ARRAY_NEXT_INT_OFFSET));
        $hashed->ret($hashed->call('__mir_array_set_int', Type::ptr(), [$arr, $ni, $val]));
    }

    /**
     * `__mir_array_cow[_obj|_str|_cell](arr) -> ptr` — clone when shared
     * (rc > 1), drop the source rc, return the rc=1 clone; else return arr.
     * Flat memcpy of header + body (packed cap*8 or hashed cap*24).
     *
     * The clone then DEEP-RETAINS exactly what the matching
     * {@see emitReleaseVariant} will drop — hashed string KEYS always, and
     * element VALUES per `$valueFlavor`. Without that the two buffers shared
     * every key/value at +0 and both owners dropped them: a double release
     * that only ever corrupted the freelist somewhere far away (the string rc
     * path had no rc<=0 guard, so nothing named it). It surfaced the moment a
     * borrowed ASSOC return started being +1-retained, which is what makes a
     * `$t = $pool->all(); … $pool->intern(x)` pair two real owners.
     */
    private function emitCowVariant(string $symbol, string $valueFlavor): void
    {
        $fn = $this->module->func($symbol, Type::ptr());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $chk = $fn->block('chk');
        $clone = $fn->block('clone');
        $keep = $fn->block('keep');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $keep, $chk);
        $rcAddr = $this->hdr($chk, $arr, MemoryAbi::ARRAY_RC_OFFSET);
        $rc = $chk->load(Type::i64(), $rcAddr);
        $chk->brIf($chk->icmp('sle', $rc, Value::int(Type::i64(), 1)), $keep, $clone);

        $len = $clone->load(Type::i64(), $arr);
        $cap = $clone->load(Type::i64(), $this->hdr($clone, $arr, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $flags = $clone->load(Type::i64(), $this->hdr($clone, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $isH = $clone->icmp('ne', $this->hashedBit($clone, $flags), Value::int(Type::i64(), 0));
        $esz = $clone->select($isH,
            Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE),
            Value::int(Type::i64(), MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE));
        $bytes = $clone->add($clone->mul($cap, $esz), Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE));
        $copy = $clone->call('__mir_alloc_array_tagged', Type::ptr(), [$bytes]);
        $clone->call('memcpy', Type::ptr(), [$copy, $arr, $bytes]);
        $clone->store(Value::int(Type::i64(), 1), $this->hdr($clone, $copy, MemoryAbi::ARRAY_RC_OFFSET));
        $clone->store(Value::int(Type::i64(), 0), $this->hdr($clone, $copy, MemoryAbi::ARRAY_NBUCKETS_OFFSET));
        $clone->store(Value::null(), $this->hdr($clone, $copy, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET));
        $clone->store($clone->sub($rc, Value::int(Type::i64(), 1)), $rcAddr);

        // ── co-own everything the clone now shares with the source ──
        $ret = $fn->block('cow_ret');
        $iSlot = $clone->alloca(Type::i64(), 'ci');
        $clone->store(Value::int(Type::i64(), 0), $iSlot);
        // 'repr' mode (the plain __mir_array_cow): the clone shares each boxed
        // cell with the source, co-owned iff the runtime repr bits are set. The
        // flags word (incl. the repr bits) rode across in the memcpy above.
        $repr = ($valueFlavor === 'repr');
        $reprBits = $repr ? $clone->and_($flags, Value::int(Type::i64(), MemoryAbi::ARRAY_REPR_MASK)) : null;
        $hasCells = $repr ? $clone->icmp('ne', $reprBits, Value::int(Type::i64(), 0)) : null;
        $hhead = $fn->block('cow_hhead');
        if ($valueFlavor === '') {
            // PACKED scalars share nothing rc'd → done. HASHED: retain keys.
            $clone->brIf($isH, $hhead, $ret);
        } elseif ($repr) {
            $phead = $fn->block('cow_phead');
            $pbody = $fn->block('cow_pbody');
            $pgate = $fn->block('cow_pgate');
            $clone->brIf($isH, $hhead, $pgate);
            $pgate->brIf($hasCells, $phead, $ret);
            $pi = $phead->load(Type::i64(), $iSlot);
            $phead->brIf($phead->icmp('sge', $pi, $len), $ret, $pbody);
            $pv = $pbody->load(Type::i64(), $this->packedSlot($pbody, $copy, $pi));
            $pbody->call('__mir_retain_by_repr', Type::void(), [$pv, $reprBits]);
            $pbody->store($pbody->add($pi, Value::int(Type::i64(), 1)), $iSlot);
            $pbody->br($phead);
        } else {
            $phead = $fn->block('cow_phead');
            $pbody = $fn->block('cow_pbody');
            $clone->brIf($isH, $hhead, $phead);
            $pi = $phead->load(Type::i64(), $iSlot);
            $phead->brIf($phead->icmp('sge', $pi, $len), $ret, $pbody);
            $pv = $pbody->load(Type::i64(), $this->packedSlot($pbody, $copy, $pi));
            $pbody = $this->emitRetainValue($fn, $pbody, $pv, $valueFlavor, 'cowp');
            $pbody->store($pbody->add($pi, Value::int(Type::i64(), 1)), $iSlot);
            $pbody->br($phead);
        }

        $hbody = $fn->block('cow_hbody');
        $hkey  = $fn->block('cow_hkey');
        $hval  = $fn->block('cow_hval');
        $hi = $hhead->load(Type::i64(), $iSlot);
        $hhead->brIf($hhead->icmp('sge', $hi, $len), $ret, $hbody);
        $kind = $hbody->load(Type::i64(), $this->entryAddr($hbody, $copy, $hi, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $hbody->brIf($hbody->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $hkey, $hval);
        $kp = $hkey->load(Type::ptr(), $this->entryAddr($hkey, $copy, $hi, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $hkey->call('__mir_rc_retain_str', Type::void(), [$kp]);
        $hkey->br($hval);
        if ($repr) {
            $hadv = $fn->block('cow_hadv');
            $hvret = $fn->block('cow_hvret');
            $hval->brIf($hasCells, $hvret, $hadv);
            $vv = $hvret->load(Type::i64(), $this->entryAddr($hvret, $copy, $hi, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
            $hvret->call('__mir_retain_by_repr', Type::void(), [$vv, $reprBits]);
            $hvret->br($hadv);
            $hadv->store($hadv->add($hi, Value::int(Type::i64(), 1)), $iSlot);
            $hadv->br($hhead);
        } else {
            if ($valueFlavor !== '') {
                $vv = $hval->load(Type::i64(), $this->entryAddr($hval, $copy, $hi, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
                $hval = $this->emitRetainValue($fn, $hval, $vv, $valueFlavor, 'cowh');
            }
            $hval->store($hval->add($hi, Value::int(Type::i64(), 1)), $iSlot);
            $hval->br($hhead);
        }

        $ret->ret($copy);
        $keep->ret($arr);
    }

    /**
     * `__mir_array_ref_slot(slotAddr, key) -> ptr` — the ADDRESS of an int-keyed
     * element's i64 value cell, for by-reference element access (`f($a[$k])`,
     * `$r = &$a[$k]`). `slotAddr` is the address of the i64 cell holding the
     * array pointer (a local's alloca or an object field): the array is
     * COW-detached and the private buffer stored back there, so writes through
     * the returned address are visible in the caller's array and don't clobber a
     * shared copy. A missing key is auto-vivified to 0 (PHP by-ref semantics);
     * set_int may relocate/promote the buffer, so the base is reloaded from
     * slotAddr before addressing. A NULL array / unreachable miss returns a
     * throwaway scratch cell (write discarded, non-crashing).
     */
    private function emitRefSlot(): void
    {
        $scratch = $this->module->globalInt('__mir_ref_scratch', Type::i64(), 0, 'linkonce_odr');
        $fn = $this->module->func('__mir_array_ref_slot', Type::ptr());
        $slotAddr = $fn->param(Type::ptr(), 'slotAddr');
        $key = $fn->param(Type::i64(), 'key');
        $e = $fn->block('entry');
        $live = $fn->block('live');
        $vivify = $fn->block('vivify');
        $locate = $fn->block('locate');
        $packed = $fn->block('packed');
        $hashed = $fn->block('hashed');
        $idxres = $fn->block('idxres');
        $idxhit = $fn->block('idxhit');
        $head = $fn->block('head');
        $body = $fn->block('body');
        $kok = $fn->block('kind_ok');
        $next = $fn->block('next');
        $hit = $fn->block('hit');
        $miss = $fn->block('miss');

        // Load & COW the base; a null array degrades to the scratch cell.
        $b0i = $e->load(Type::i64(), $slotAddr);
        $b0 = $e->inttoptr($b0i, Type::ptr());
        $e->brIf($e->icmp('eq', $b0, Value::null()), $miss, $live);

        $cow = $live->call('__mir_array_cow', Type::ptr(), [$b0]);
        $live->store($live->ptrtoint($cow, Type::i64()), $slotAddr);
        $present = $live->call('__mir_array_isset_int', Type::i64(), [$cow, $key]);
        $live->brIf($live->icmp('eq', $present, Value::int(Type::i64(), 0)), $vivify, $locate);

        // Auto-vivify the absent key to 0 (may relocate / promote to hashed).
        $sv = $vivify->call('__mir_array_set_int', Type::ptr(),
            [$cow, $key, Value::int(Type::i64(), 0)]);
        $vivify->store($vivify->ptrtoint($sv, Type::i64()), $slotAddr);
        $vivify->br($locate);

        // Reload the (possibly relocated) base, then address by mode.
        $bi = $locate->load(Type::i64(), $slotAddr);
        $b = $locate->inttoptr($bi, Type::ptr());
        $flags = $locate->load(Type::i64(), $this->hdr($locate, $b, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $locate->brIf($locate->icmp('ne', $this->hashedBit($locate, $flags), Value::int(Type::i64(), 0)), $hashed, $packed);

        $packed->ret($this->packedSlot($packed, $b, $key));

        // HASHED: bucket-index lookup first (-2 = small map → linear scan;
        // -1 can't normally happen — the key was just vivified — but a miss
        // safely degrades to the scratch cell). Large maps stop paying an
        // O(n) walk per reference.
        $len = $hashed->load(Type::i64(), $b);
        $iSlot = $hashed->alloca(Type::i64(), 'i');
        $hashed->store(Value::int(Type::i64(), 0), $iSlot);
        $rfI = $hashed->call('__mir_array_index_find', Type::i64(),
            [$b, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_INT), Value::null(), $key,
             Value::int(Type::i64(), 0), Value::int(Type::i64(), 0)]);
        $hashed->brIf($hashed->icmp('eq', $rfI, Value::int(Type::i64(), -2)), $head, $idxres);
        $idxres->brIf($idxres->icmp('slt', $rfI, Value::int(Type::i64(), 0)), $miss, $idxhit);
        $idxhit->ret($this->entryAddr($idxhit, $b, $rfI, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $miss, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $b, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_INT)), $next, $kok);
        $kk = $kok->load(Type::i64(), $this->entryAddr($kok, $b, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->icmp('eq', $kk, $key), $hit, $next);
        $next->store($next->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $next->br($head);
        $hit->ret($this->entryAddr($hit, $b,
            $hit->load(Type::i64(), $iSlot), MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));

        $miss->ret($scratch);
    }

    /**
     * `__mir_array_ref_slot_str(slotAddr, key) -> ptr` — the string-keyed analogue
     * of {@see emitRefSlot}. COW-detach the array at *slotAddr, auto-vivify the
     * string key to 0 if absent, then linear-scan the (always-HASHED) entries for
     * the KIND_STRING entry whose key `__mir_str_eq`s `key` and return its i64
     * value-cell address. NULL array / miss → a throwaway scratch cell.
     */
    private function emitRefSlotStr(): void
    {
        // Reference (not redefine) the scratch cell emitted by {@see emitRefSlot}.
        $scratch = Value::global(Type::ptr(), '__mir_ref_scratch');
        $fn = $this->module->func('__mir_array_ref_slot_str', Type::ptr());
        $slotAddr = $fn->param(Type::ptr(), 'slotAddr');
        $key = $fn->param(Type::ptr(), 'key');
        $e = $fn->block('entry');
        $live = $fn->block('live');
        $vivify = $fn->block('vivify');
        $locate = $fn->block('locate');
        $idxchk = $fn->block('idxchk');
        $idxres = $fn->block('idxres');
        $idxhit = $fn->block('idxhit');
        $preh = $fn->block('preh');
        $head = $fn->block('head');
        $body = $fn->block('body');
        $kok = $fn->block('kind_ok');
        $hpre = $fn->block('hpre');
        $cmp = $fn->block('cmp');
        $next = $fn->block('next');
        $hit = $fn->block('hit');
        $miss = $fn->block('miss');

        $b0i = $e->load(Type::i64(), $slotAddr);
        $b0 = $e->inttoptr($b0i, Type::ptr());
        $e->brIf($e->icmp('eq', $b0, Value::null()), $miss, $live);

        $cow = $live->call('__mir_array_cow', Type::ptr(), [$b0]);
        $live->store($live->ptrtoint($cow, Type::i64()), $slotAddr);
        $present = $live->call('__mir_array_isset_str', Type::i64(),
            [$cow, $key, Value::int(Type::i64(), 0), Value::int(Type::i64(), 0)]);
        $live->brIf($live->icmp('eq', $present, Value::int(Type::i64(), 0)), $vivify, $locate);

        $sv = $vivify->call('__mir_array_set_str', Type::ptr(),
            [$cow, $key, Value::int(Type::i64(), 0), Value::int(Type::i64(), 0), Value::int(Type::i64(), 0)]);
        $vivify->store($vivify->ptrtoint($sv, Type::i64()), $slotAddr);
        $vivify->br($locate);

        // Bucket-index lookup first (null key → straight to the linear scan,
        // matching the old behaviour); -2 = small map → hash-prefiltered scan.
        $bi = $locate->load(Type::i64(), $slotAddr);
        $b = $locate->inttoptr($bi, Type::ptr());
        $len = $locate->load(Type::i64(), $b);
        $iSlot = $locate->alloca(Type::i64(), 'i');
        $effSlot = $locate->alloca(Type::i64(), 'effh');
        $locate->store(Value::int(Type::i64(), 0), $iSlot);
        $locate->store(Value::int(Type::i64(), 0), $effSlot);
        $locate->brIf($locate->icmp('eq', $key, Value::null()), $head, $idxchk);
        $rfS = $idxchk->call('__mir_array_index_find', Type::i64(),
            [$b, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING), $key,
             Value::int(Type::i64(), 0), Value::int(Type::i64(), 0), Value::int(Type::i64(), 0)]);
        $idxchk->brIf($idxchk->icmp('eq', $rfS, Value::int(Type::i64(), -2)), $preh, $idxres);
        $idxres->brIf($idxres->icmp('slt', $rfS, Value::int(Type::i64(), 0)), $miss, $idxhit);
        $idxhit->ret($this->entryAddr($idxhit, $b, $rfS, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
        $preh->store($this->scanProbeHash($preh, $key,
            Value::int(Type::i64(), 0), Value::int(Type::i64(), 0)), $effSlot);
        $preh->br($head);
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $miss, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $b, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $next, $kok);
        $ek = $kok->load(Type::ptr(), $this->entryAddr($kok, $b, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->icmp('eq', $ek, Value::null()), $next, $hpre);
        $this->hashPrefilter($hpre, $ek, $effSlot, $cmp, $next);
        $cmp->brIf($cmp->call('__mir_str_eq', Type::i1(), [$ek, $key]), $hit, $next);
        $next->store($next->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $next->br($head);
        $hit->ret($this->entryAddr($hit, $b,
            $hit->load(Type::i64(), $iSlot), MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));

        $miss->ret($scratch);
    }

    /**
     * `__mir_array_value_at(arr, i) -> i64` — value at slot/entry i for
     * foreach. PACKED: packed slot; HASHED: entry value field.
     */
    private function emitValueAt(): void
    {
        $fn = $this->module->func('__mir_array_value_at', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $i = $fn->param(Type::i64(), 'i');
        $e = $fn->block('entry');
        $packed = $fn->block('packed');
        $hashed = $fn->block('hashed');
        $flags = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $e->brIf($e->icmp('ne', $this->hashedBit($e, $flags), Value::int(Type::i64(), 0)), $hashed, $packed);
        $packed->ret($packed->load(Type::i64(), $this->packedSlot($packed, $arr, $i)));
        $hashed->ret($hashed->load(Type::i64(), $this->entryAddr($hashed, $arr, $i, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET)));
    }

    /**
     * `__mir_array_key_at(arr, i) -> i64` — key at slot/entry i for
     * foreach. PACKED: the implicit int index i. HASHED: the entry key
     * field (int value, or a string ptr reinterpreted as i64).
     */
    private function emitKeyAt(): void
    {
        $fn = $this->module->func('__mir_array_key_at', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $i = $fn->param(Type::i64(), 'i');
        $e = $fn->block('entry');
        $packed = $fn->block('packed');
        $hashed = $fn->block('hashed');
        $flags = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $e->brIf($e->icmp('ne', $this->hashedBit($e, $flags), Value::int(Type::i64(), 0)), $hashed, $packed);
        $packed->ret($i);
        $hashed->ret($hashed->load(Type::i64(), $this->entryAddr($hashed, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET)));
    }

    /**
     * `__mir_array_key_cell_at(arr, i) -> i64` — like key_at, but the key is
     * returned NaN-boxed as a cell (PACKED/int key → box_int; HASHED string key
     * → box_ptr). foreach over a `mixed`/cell array needs a tagged key so a
     * downstream `$ks[] = $k` / echo dispatches by type (an int-keyed and a
     * string-keyed entry can't share a raw i64 carrier). Tag math is inlined
     * (PAYLOAD_MASK | tagBits), matching __manticore_box_int/box_ptr.
     */
    private function emitKeyCellAt(): void
    {
        $fn = $this->module->func('__mir_array_key_cell_at', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $i = $fn->param(Type::i64(), 'i');
        $e = $fn->block('entry');
        $packed = $fn->block('packed');
        $hashed = $fn->block('hashed');
        $hstr = $fn->block('hstr');
        $hint = $fn->block('hint');
        $mask = Value::int(Type::i64(), 281474976710655);
        $intTag = Value::int(Type::i64(), -4222124650659840);
        $ptrTag = Value::int(Type::i64(), -3377699720527872);
        $flags = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $e->brIf($e->icmp('ne', $this->hashedBit($e, $flags), Value::int(Type::i64(), 0)), $hashed, $packed);
        $packed->ret($packed->or_($packed->and_($i, $mask), $intTag));
        $kind = $hashed->load(Type::i64(), $this->entryAddr($hashed, $arr, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $hashed->brIf($hashed->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $hstr, $hint);
        $sk = $hstr->load(Type::i64(), $this->entryAddr($hstr, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $hstr->ret($hstr->or_($hstr->and_($sk, $mask), $ptrTag));
        // A HASHED int key may be ANY i64 — box it overflow-aware so a key
        // past 2^47 rides a heap bigint (nibble 5) instead of masking to 48
        // bits and reading back as a truncated/-1 value. (The packed arm above
        // keeps the inline mask: a packed key is an index 0..len-1, always in
        // range.) The de-cellify side in `cellKeyRuntime` mirrors this via
        // `__mir_ckey_unbox_int`.
        $ik = $hint->load(Type::i64(), $this->entryAddr($hint, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $hint->ret($hint->call('__mir_ckey_box_int', Type::i64(), [$ik]));
    }

    /**
     * `__mir_ckey_box_int(v) -> i64` / `__mir_ckey_unbox_int(v) -> i64` — the
     * overflow-aware int box/unbox used by the cell-KEY path, duplicated here
     * (rather than calling the `needsBoxInt`-gated `__manticore_box_int`)
     * because the array runtime is ALWAYS emitted and must not depend on a
     * gated helper. A key that fits signed-48 rides inline (tag 1); a larger
     * one is stored on the heap and tagged nibble 5, exactly like the general
     * int box. The heap word leaks — acceptable for a rare huge key, matching
     * the general box_int.
     */
    private function emitCkeyBoxInt(): void
    {
        $fn = $this->module->func('__mir_ckey_box_int', Type::i64());
        $fn->param(Type::i64(), 'v');
        $e = $fn->block('entry');
        $e->raw('  %s = shl i64 %v, 16');
        $e->raw('  %se = ashr i64 %s, 16');
        $e->raw('  %fits = icmp eq i64 %se, %v');
        $e->raw('  br i1 %fits, label %inl, label %heap');
        $inl = $fn->block('inl');
        $inl->raw('  %m = and i64 %v, 281474976710655');
        $inl->raw('  %b = or i64 %m, -4222124650659840');
        $inl->raw('  ret i64 %b');
        $heap = $fn->block('heap');
        $heap->raw('  %p = call ptr @malloc(i64 8)');
        $heap->raw('  store i64 %v, ptr %p');
        $heap->raw('  %pi = ptrtoint ptr %p to i64');
        $heap->raw('  %pm = and i64 %pi, 281474976710655');
        $heap->raw('  %pb = or i64 %pm, -3096224743817216');
        $heap->raw('  ret i64 %pb');
    }

    private function emitCkeyUnboxInt(): void
    {
        $fn = $this->module->func('__mir_ckey_unbox_int', Type::i64());
        $fn->param(Type::i64(), 'v');
        $e = $fn->block('entry');
        $e->raw('  %sh = lshr i64 %v, 48');
        $e->raw('  %nib = and i64 %sh, 15');
        $e->raw('  %big = icmp eq i64 %nib, 5');
        $e->raw('  br i1 %big, label %fromheap, label %inl');
        $fh = $fn->block('fromheap');
        $fh->raw('  %pm = and i64 %v, 281474976710655');
        $fh->raw('  %hp = inttoptr i64 %pm to ptr');
        $fh->raw('  %hv = load i64, ptr %hp');
        $fh->raw('  ret i64 %hv');
        $inl = $fn->block('inl');
        $inl->raw('  %s2 = shl i64 %v, 16');
        $inl->raw('  %r = ashr i64 %s2, 16');
        $inl->raw('  ret i64 %r');
    }

    /**
     * `__mir_array_spread_into(dst, src) -> ptr` — merge every entry of `src`
     * into `dst` with PHP array-spread key semantics (8.1+): STRING keys are
     * preserved (a later duplicate overwrites), INTEGER keys are renumbered
     * (appended). PACKED src is all-int → append; HASHED src dispatches per
     * entry KIND. Returns the (possibly relocated) dst buffer.
     */
    private function emitSpreadInto(): void
    {
        $fn = $this->module->func('__mir_array_spread_into', Type::ptr());
        $dst = $fn->param(Type::ptr(), 'dst');
        $src = $fn->param(Type::ptr(), 'src');
        $e = $fn->block('entry');
        $cond = $fn->block('sp_cond');
        $body = $fn->block('sp_body');
        $isStr = $fn->block('sp_isstr');
        $doStr = $fn->block('sp_str');
        $doInt = $fn->block('sp_int');
        $nextb = $fn->block('sp_next');
        $end = $fn->block('sp_end');
        $dSlot = $e->alloca(Type::ptr(), 'd');
        $iSlot = $e->alloca(Type::i64(), 'i');
        $e->store($dst, $dSlot);
        $e->store(Value::int(Type::i64(), 0), $iSlot);
        $e->brIf($e->icmp('eq', $src, Value::null()), $end, $cond);
        // live_len compacts out tombstones so the merge walks only live entries.
        $len = $cond->call('__mir_array_live_len', Type::i64(), [$src]);
        $i = $cond->load(Type::i64(), $iSlot);
        $cond->brIf($cond->icmp('sge', $i, $len), $end, $body);
        $iv = $body->load(Type::i64(), $iSlot);
        $val = $body->call('__mir_array_value_at', Type::i64(), [$src, $iv]);
        $flags = $body->load(Type::i64(), $this->hdr($body, $src, MemoryAbi::ARRAY_FLAGS_OFFSET));
        // packed (flags==0) → implicit int key → renumber; hashed → check KIND
        $body->brIf($body->icmp('eq', $this->hashedBit($body, $flags), Value::int(Type::i64(), 0)), $doInt, $isStr);
        $kind = $isStr->load(Type::i64(), $this->entryAddr($isStr, $src, $iv, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $isStr->brIf($isStr->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $doStr, $doInt);
        $key = $doStr->load(Type::ptr(), $this->entryAddr($doStr, $src, $iv, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $d1 = $doStr->load(Type::ptr(), $dSlot);
        $ns = $doStr->call('__mir_array_set_str', Type::ptr(),
            [$d1, $key, $val, Value::int(Type::i64(), 0), Value::int(Type::i64(), 0)]);
        $doStr->store($ns, $dSlot);
        $doStr->br($nextb);
        $d2 = $doInt->load(Type::ptr(), $dSlot);
        $na = $doInt->call('__mir_array_append', Type::ptr(), [$d2, $val]);
        $doInt->store($na, $dSlot);
        $doInt->br($nextb);
        $nextb->store($nextb->add($nextb->load(Type::i64(), $iSlot), Value::int(Type::i64(), 1)), $iSlot);
        $nextb->br($cond);
        $end->ret($end->load(Type::ptr(), $dSlot));
    }

    /**
     * `__mir_array_implode_int(sep, arr) -> ptr` — join RAW-int elements.
     * Two passes (digit-count sum for the exact allocation, then format), but
     * each element is loaded INLINE (packed slot / hashed value, mode selected
     * once) rather than through an out-of-line `__mir_array_value_at` CALL — the
     * old version paid FOUR calls per element (value_at ×2 + int_len + int_fmt),
     * making a vec[int] implode ~11× SLOWER than php (0.09×). Now 2 calls per
     * element (int_len + int_fmt), matching the fast json int path.
     */
    private function emitImplodeInt(): void
    {
        $fn = $this->module->func('__mir_array_implode_int', Type::ptr());
        $sep = $fn->param(Type::ptr(), 'sep');
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $empty = $fn->block('ii_empty');
        $init = $fn->block('ii_init');
        $sh = $fn->block('ii_sum_head');
        $sb = $fn->block('ii_sum_body');
        $al = $fn->block('ii_alloc');
        $ch = $fn->block('ii_cpy_head');
        $cb = $fn->block('ii_cpy_body');
        $csep = $fn->block('ii_cpy_sep');
        $cval = $fn->block('ii_cpy_val');
        $done = $fn->block('ii_done');

        // live_len compacts out tombstones (holes) first so the two passes see
        // a clean 0..len entry range.
        $len = $e->call('__mir_array_live_len', Type::i64(), [$arr]);
        // implode(sep, []) === "" — same empty guard as the string variant
        // (negative size math would route str_alloc to a wrapped tiny malloc).
        $e->brIf($e->icmp('sle', $len, Value::int(Type::i64(), 0)), $empty, $init);
        $eb = $empty->call('__mir_str_alloc', Type::ptr(), [Value::int(Type::i64(), 1)]);
        $empty->store(Value::int(Type::i8(), 0), $eb);
        $empty->ret($eb);

        // Element stride / bias chosen ONCE from the mode (PACKED 8B slot vs
        // HASHED 24B entry value) — the loop then loads each element inline.
        $flags = $init->load(Type::i64(), $this->hdr($init, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $ishash = $init->icmp('ne', $this->hashedBit($init, $flags), Value::int(Type::i64(), 0));
        $stride = $init->select($ishash, Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE), Value::int(Type::i64(), MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE));
        $bias = $init->add(
            $init->select($ishash, Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET), Value::int(Type::i64(), 0)),
            Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE),
        );
        $seplen = $init->call('__mir_strlen', Type::i64(), [$sep]);
        $accSlot = $init->alloca(Type::i64(), 'ii_acc');
        $iSlot = $init->alloca(Type::i64(), 'ii_i');
        $offSlot = $init->alloca(Type::i64(), 'ii_off');
        $init->store(Value::int(Type::i64(), 0), $accSlot);
        $init->store(Value::int(Type::i64(), 0), $iSlot);
        $init->br($sh);
        $i = $sh->load(Type::i64(), $iSlot);
        $sh->brIf($sh->icmp('sge', $i, $len), $al, $sb);
        $vaddr = $sb->gep(Type::i8(), $arr, [$sb->add($bias, $sb->mul($i, $stride))]);
        $v = $sb->load(Type::i64(), $vaddr);
        $n = $sb->call('__mir_int_len', Type::i64(), [$v]);
        $sb->store($sb->add($sb->load(Type::i64(), $accSlot), $n), $accSlot);
        $sb->store($sb->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $sb->br($sh);

        $acc = $al->load(Type::i64(), $accSlot);
        $seps = $al->mul($seplen, $al->sub($len, Value::int(Type::i64(), 1)));
        $total = $al->add($al->add($acc, $seps), Value::int(Type::i64(), 1));
        $buf = $al->call('__mir_str_alloc', Type::ptr(), [$total]);
        $al->store(Value::int(Type::i64(), 0), $iSlot);
        $al->store(Value::int(Type::i64(), 0), $offSlot);
        $al->br($ch);
        $i2 = $ch->load(Type::i64(), $iSlot);
        $ch->brIf($ch->icmp('sge', $i2, $len), $done, $cb);
        $cb->brIf($cb->icmp('eq', $i2, Value::int(Type::i64(), 0)), $cval, $csep);
        $off0 = $csep->load(Type::i64(), $offSlot);
        $dsts = $csep->gep(Type::i8(), $buf, [$off0]);
        $csep->call('memcpy', Type::ptr(), [$dsts, $sep, $seplen]);
        $csep->store($csep->add($off0, $seplen), $offSlot);
        $csep->br($cval);
        $vaddr2 = $cval->gep(Type::i8(), $arr, [$cval->add($bias, $cval->mul($i2, $stride))]);
        $v2 = $cval->load(Type::i64(), $vaddr2);
        $n2 = $cval->call('__mir_int_len', Type::i64(), [$v2]);
        $off1 = $cval->load(Type::i64(), $offSlot);
        $cval->call('__mir_int_fmt', Type::void(), [$buf, $off1, $v2]);
        $cval->store($cval->add($off1, $n2), $offSlot);
        $cval->store($cval->add($i2, Value::int(Type::i64(), 1)), $iSlot);
        $cval->br($ch);

        $offF = $done->load(Type::i64(), $offSlot);
        $done->store(Value::int(Type::i8(), 0), $done->gep(Type::i8(), $buf, [$offF]));
        $done->call('__mir_str_set_len', Type::void(), [$buf, $offF]);
        $done->ret($buf);
    }

    /**
     * `__mir_array_pop(arr) -> i64` — remove & return the last element;
     * 0 on empty/NULL. Decrements len in place (no relocation). The
     * popped value's rc / a hashed key are not dropped yet (Stage 3).
     */
    private function emitPop(): void
    {
        $fn = $this->module->func('__mir_array_pop', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $chk = $fn->block('chk');
        $go = $fn->block('go');
        $z = $fn->block('z');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $z, $chk);
        // Compact first so "the last entry" is a live one, not a tombstone.
        $len = $chk->call('__mir_array_live_len', Type::i64(), [$arr]);
        $chk->brIf($chk->icmp('sle', $len, Value::int(Type::i64(), 0)), $z, $go);
        $nl = $go->sub($len, Value::int(Type::i64(), 1));
        // Surgical index repair (last entry: backshift only, nothing to sweep);
        // PACKED / no-index arrays no-op inside.
        $go->call('__mir_array_index_unset', Type::void(), [$arr, $nl]);
        $v = $go->call('__mir_array_value_at', Type::i64(), [$arr, $nl]);
        $go->store($nl, $arr);
        $go->ret($v);
        $z->ret(Value::int(Type::i64(), 0));
    }

    /**
     * `__mir_array_shift(arr) -> i64` — remove & return element 0,
     * slide the tail down one slot/entry (memmove, mode stride), decr
     * len. 0 on empty/NULL. No relocation.
     */
    private function emitShift(): void
    {
        $fn = $this->module->func('__mir_array_shift', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $chk = $fn->block('chk');
        $go = $fn->block('go');
        $z = $fn->block('z');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $z, $chk);
        // Compact first so entry 0 is a live element and the memmove is clean.
        $len = $chk->call('__mir_array_live_len', Type::i64(), [$arr]);
        $chk->brIf($chk->icmp('sle', $len, Value::int(Type::i64(), 0)), $z, $go);
        // Surgical index repair for entry 0 (sweep shifts every survivor down
        // one); PACKED / no-index arrays no-op inside.
        $go->call('__mir_array_index_unset', Type::void(), [$arr, Value::int(Type::i64(), 0)]);
        $first = $go->call('__mir_array_value_at', Type::i64(), [$arr, Value::int(Type::i64(), 0)]);
        $esz = $this->elemSize($go, $arr);
        $tail = $go->sub($len, Value::int(Type::i64(), 1));
        $bytes = $go->mul($tail, $esz);
        $dst = $this->hdr($go, $arr, MemoryAbi::ARRAY_HEADER_SIZE);
        $src = $go->gep(Type::i8(), $arr, [$go->add(Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE), $esz)]);
        $go->call('memmove', Type::ptr(), [$dst, $src, $bytes]);
        $go->store($tail, $arr);
        $go->ret($first);
        $z->ret(Value::int(Type::i64(), 0));
    }

    /**
     * `__mir_array_unshift(arr, val) -> ptr` — prepend `val` (PACKED
     * list op): realloc to len+1, slide existing right one slot, store
     * val at index 0, bump len + next_int. Returns the relocated
     * buffer. HASHED unshift is not surfaced (a list operation); callers
     * use it on packed vecs only.
     */
    private function emitUnshift(): void
    {
        $fn = $this->module->func('__mir_array_unshift', Type::ptr());
        $arr = $fn->param(Type::ptr(), 'arr');
        $val = $fn->param(Type::i64(), 'val');
        $b = $fn->block('entry');
        if (Debug::$emptyArraySingleton) { $arr = $b->call('__mir_array_deimmortal', Type::ptr(), [$arr]); }
        $b->call('__mir_array_index_drop', Type::void(), [$arr]);
        $len = $b->load(Type::i64(), $arr);
        $newlen = $b->add($len, Value::int(Type::i64(), 1));
        $bytes = $b->add(
            $b->mul($newlen, Value::int(Type::i64(), MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE)),
            Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE),
        );
        $reloc = $b->call('__mir_realloc_tagged', Type::ptr(), [$arr, $bytes]);
        $b->store($newlen, $this->hdr($b, $reloc, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $dst = $b->gep(Type::i8(), $reloc, [Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE + MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE)]);
        $src = $this->hdr($b, $reloc, MemoryAbi::ARRAY_HEADER_SIZE);
        $mvBytes = $b->mul($len, Value::int(Type::i64(), MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE));
        $b->call('memmove', Type::ptr(), [$dst, $src, $mvBytes]);
        $b->store($val, $this->hdr($b, $reloc, MemoryAbi::ARRAY_HEADER_SIZE));
        $b->store($newlen, $reloc);
        $b->store($newlen, $this->hdr($b, $reloc, MemoryAbi::ARRAY_NEXT_INT_OFFSET));
        $b->ret($reloc);
    }

    /**
     * `__mir_array_implode(sep, arr) -> ptr` — join string-valued
     * elements with `sep`. Mirrors `__mir_implode` but fetches each
     * value via `__mir_array_value_at` (mode-agnostic). Two passes:
     * sum lengths, then copy with separators.
     */
    private function emitImplode(): void
    {
        $fn = $this->module->func('__mir_array_implode', Type::ptr());
        $fn->param(Type::ptr(), 'sep');
        $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        // live_len compacts out tombstones so both passes see a clean range.
        $e->raw('  %len = call i64 @__mir_array_live_len(ptr %arr)');
        // implode(sep, []) === "" — MUST short-circuit an empty array. The size
        // math in %alloc is `acc + seplen*(len-1) + 1`; for len==0 that is
        // `-seplen + 1`, which for a multi-char separator is NEGATIVE. str_alloc
        // then tests the size UNSIGNED, routing a negative size to the big path →
        // malloc(size+HEADER) of a wrapped tiny value → the returned data ptr is
        // PAST the allocation → OOB read/write (layout-flaky heap corruption).
        // The cell variant already guards this. str_alloc(1) is a valid len-0
        // buffer (cap 32, len n-1 = 0); NUL-terminate its data byte.
        $e->raw('  %isempty = icmp sle i64 %len, 0');
        $e->raw('  br i1 %isempty, label %empty, label %init');
        $empty = $fn->block('empty');
        $empty->raw('  %eb = call ptr @__mir_str_alloc(i64 1)');
        $empty->raw('  store i8 0, ptr %eb');
        $empty->raw('  ret ptr %eb');
        $init = $fn->block('init');
        // Header length (binary-safe, O(1)) NOT libc strlen: a manticore string
        // may carry no trailing NUL (str_set_len writes none), so libc strlen
        // over-reads into adjacent heap — and since the sizing pass and the copy
        // pass strlen independently, a str_alloc landing next door between them
        // yields el2 > el → the copy overruns the buffer. __mir_strlen reads
        // len@-16, identical across both passes.
        $init->raw('  %seplen = call i64 @__mir_strlen(ptr %sep)');
        // Element stride / bias chosen ONCE (PACKED 8B slot vs HASHED 24B entry
        // value); the loops then load each element ptr inline instead of an
        // out-of-line __mir_array_value_at CALL per element per pass.
        $iiFO = (string) MemoryAbi::ARRAY_FLAGS_OFFSET;
        $iiES = (string) MemoryAbi::ARRAY_ENTRY_SIZE;
        $iiVO = (string) MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET;
        $iiH  = (string) MemoryAbi::ARRAY_HEADER_SIZE;
        $init->raw('  %iflagp = getelementptr inbounds i8, ptr %arr, i64 ' . $iiFO);
        $init->raw('  %iflags = load i64, ptr %iflagp');
        $init->raw('  %iflagsh = and i64 %iflags, ' . (string) MemoryAbi::ARRAY_FLAG_HASHED);
        $init->raw('  %ihash = icmp ne i64 %iflagsh, 0');
        $init->raw('  %istride = select i1 %ihash, i64 ' . $iiES . ', i64 8');
        $init->raw('  %ibias0 = select i1 %ihash, i64 ' . $iiVO . ', i64 0');
        $init->raw('  %ibias = add i64 %ibias0, ' . $iiH);
        $init->raw('  %accp = alloca i64');
        $init->raw('  store i64 0, ptr %accp');
        $init->raw('  %ip = alloca i64');
        $init->raw('  store i64 0, ptr %ip');
        $init->raw('  br label %sumc');
        $sumc = $fn->block('sumc');
        $sumc->raw('  %i = load i64, ptr %ip');
        $sumc->raw('  %sd = icmp slt i64 %i, %len');
        $sumc->raw('  br i1 %sd, label %sumb, label %alloc');
        $sumb = $fn->block('sumb');
        $sumb->raw('  %iea0 = mul i64 %i, %istride');
        $sumb->raw('  %iea = add i64 %ibias, %iea0');
        $sumb->raw('  %eap = getelementptr inbounds i8, ptr %arr, i64 %iea');
        $sumb->raw('  %ev = load i64, ptr %eap');
        $sumb->raw('  %es = inttoptr i64 %ev to ptr');
        $sumb->raw('  %el = call i64 @__mir_strlen(ptr %es)');
        $sumb->raw('  %a = load i64, ptr %accp');
        $sumb->raw('  %a2 = add i64 %a, %el');
        $sumb->raw('  store i64 %a2, ptr %accp');
        $sumb->raw('  %i2 = add i64 %i, 1');
        $sumb->raw('  store i64 %i2, ptr %ip');
        $sumb->raw('  br label %sumc');
        $alloc = $fn->block('alloc');
        $alloc->raw('  %acc = load i64, ptr %accp');
        $alloc->raw('  %lm1 = sub i64 %len, 1');
        $alloc->raw('  %sb = mul i64 %seplen, %lm1');
        $alloc->raw('  %t = add i64 %acc, %sb');
        $alloc->raw('  %sz = add i64 %t, 1');
        $alloc->raw('  %buf = call ptr @__mir_str_alloc(i64 %sz)');
        $alloc->raw('  store i8 0, ptr %buf');
        $alloc->raw('  store i64 0, ptr %ip');
        $alloc->raw('  %wp = alloca i64');
        $alloc->raw('  store i64 0, ptr %wp');
        $alloc->raw('  br label %cpc');
        $cpc = $fn->block('cpc');
        $cpc->raw('  %j = load i64, ptr %ip');
        $cpc->raw('  %cd = icmp slt i64 %j, %len');
        $cpc->raw('  br i1 %cd, label %cpb, label %fin');
        $cpb = $fn->block('cpb');
        $cpb->raw('  %first = icmp eq i64 %j, 0');
        $cpb->raw('  br i1 %first, label %nosep, label %dosep');
        $dosep = $fn->block('dosep');
        $dosep->raw('  %w0 = load i64, ptr %wp');
        $dosep->raw('  %dst0 = getelementptr inbounds i8, ptr %buf, i64 %w0');
        $dosep->raw('  call ptr @memcpy(ptr %dst0, ptr %sep, i64 %seplen)');
        $dosep->raw('  %w0b = add i64 %w0, %seplen');
        $dosep->raw('  store i64 %w0b, ptr %wp');
        $dosep->raw('  br label %nosep');
        $nosep = $fn->block('nosep');
        $nosep->raw('  %jea0 = mul i64 %j, %istride');
        $nosep->raw('  %jea = add i64 %ibias, %jea0');
        $nosep->raw('  %eap2 = getelementptr inbounds i8, ptr %arr, i64 %jea');
        $nosep->raw('  %ev2 = load i64, ptr %eap2');
        $nosep->raw('  %es2 = inttoptr i64 %ev2 to ptr');
        $nosep->raw('  %el2 = call i64 @__mir_strlen(ptr %es2)');
        $nosep->raw('  %w1 = load i64, ptr %wp');
        $nosep->raw('  %dst1 = getelementptr inbounds i8, ptr %buf, i64 %w1');
        $nosep->raw('  call ptr @memcpy(ptr %dst1, ptr %es2, i64 %el2)');
        $nosep->raw('  %w2 = add i64 %w1, %el2');
        $nosep->raw('  store i64 %w2, ptr %wp');
        $nosep->raw('  %j2 = add i64 %j, 1');
        $nosep->raw('  store i64 %j2, ptr %ip');
        $nosep->raw('  br label %cpc');
        $fin = $fn->block('fin');
        $fin->raw('  %wf = load i64, ptr %wp');
        $fin->raw('  %nulp = getelementptr inbounds i8, ptr %buf, i64 %wf');
        $fin->raw('  store i8 0, ptr %nulp');
        $fin->raw('  ret ptr %buf');
    }

    /**
     * `__mir_array_isset_int(arr, idx) -> i64` — 1 if the key exists.
     * PACKED: 0 <= idx < len. HASHED: a KIND_INT entry with key==idx.
     */
    private function emitIssetInt(): void
    {
        $fn = $this->module->func('__mir_array_isset_int', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $idx = $fn->param(Type::i64(), 'idx');
        $e = $fn->block('entry');
        $chk = $fn->block('chk');
        $packed = $fn->block('packed');
        $doidx = $fn->block('doidx');
        $classify = $fn->block('classify');
        $head = $fn->block('head');
        $body = $fn->block('body');
        $kok = $fn->block('kind_ok');
        $next = $fn->block('next');
        $hit = $fn->block('hit');
        $z = $fn->block('z');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $z, $chk);
        $flags = $chk->load(Type::i64(), $this->hdr($chk, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $len = $chk->load(Type::i64(), $arr);
        $iSlot = $chk->alloca(Type::i64(), 'i');
        $rSlot = $chk->alloca(Type::i64(), 'r');
        $chk->store(Value::int(Type::i64(), 0), $iSlot);
        $chk->brIf($chk->icmp('ne', $this->hashedBit($chk, $flags), Value::int(Type::i64(), 0)), $doidx, $packed);
        $ok = $packed->and_(
            $packed->icmp('sge', $idx, Value::int(Type::i64(), 0)),
            $packed->icmp('slt', $idx, $len),
        );
        $packed->ret($packed->zext($ok, Type::i64()));
        // HASHED index fast path: -2 → linear, -1 → absent, else present.
        $rf = $doidx->call('__mir_array_index_find', Type::i64(),
            [$arr, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_INT), Value::null(), $idx, Value::int(Type::i64(), 0), Value::int(Type::i64(), 0)]);
        $doidx->store($rf, $rSlot);
        $doidx->brIf($doidx->icmp('eq', $rf, Value::int(Type::i64(), -2)), $head, $classify);
        $classify->brIf($classify->icmp('sge', $classify->load(Type::i64(), $rSlot), Value::int(Type::i64(), 0)), $hit, $z);
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $z, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $arr, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_INT)), $next, $kok);
        $k = $kok->load(Type::i64(), $this->entryAddr($kok, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->icmp('eq', $k, $idx), $hit, $next);
        $next->store($next->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $next->br($head);
        $hit->ret(Value::int(Type::i64(), 1));
        $z->ret(Value::int(Type::i64(), 0));
    }

    /**
     * `__mir_array_isset_str(arr, key) -> i64` — 1 if a KIND_STRING
     * entry matches `key` (strcmp). PACKED has no string keys → 0.
     */
    private function emitIssetStr(): void
    {
        $fn = $this->module->func('__mir_array_isset_str', Type::i64());
        $arr = $fn->param(Type::ptr(), 'arr');
        $key = $fn->param(Type::ptr(), 'key');
        $hash = $fn->param(Type::i64(), 'hash');
        $haveHash = $fn->param(Type::i64(), 'haveHash');
        $e = $fn->block('entry');
        $chk = $fn->block('chk');
        $gate = $fn->block('gate');
        $doidx = $fn->block('doidx');
        $classify = $fn->block('classify');
        $preh = $fn->block('preh');
        $head = $fn->block('head');
        $body = $fn->block('body');
        $kok = $fn->block('kind_ok');
        $hpre = $fn->block('hpre');
        $cmp = $fn->block('cmp');
        $next = $fn->block('next');
        $hit = $fn->block('hit');
        $z = $fn->block('z');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $z, $chk);
        $flags = $chk->load(Type::i64(), $this->hdr($chk, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $len = $chk->load(Type::i64(), $arr);
        $iSlot = $chk->alloca(Type::i64(), 'i');
        $rSlot = $chk->alloca(Type::i64(), 'r');
        $effSlot = $chk->alloca(Type::i64(), 'effh');
        $chk->store(Value::int(Type::i64(), 0), $iSlot);
        $chk->brIf($chk->icmp('eq', $this->hashedBit($chk, $flags), Value::int(Type::i64(), 0)), $z, $gate);
        // A null key never matches; else index fast path (-2 → linear).
        $gate->brIf($gate->icmp('eq', $key, Value::null()), $z, $doidx);
        $rf = $doidx->call('__mir_array_index_find', Type::i64(),
            [$arr, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING), $key, Value::int(Type::i64(), 0), $hash, $haveHash]);
        $doidx->store($rf, $rSlot);
        $doidx->brIf($doidx->icmp('eq', $rf, Value::int(Type::i64(), -2)), $preh, $classify);
        $classify->brIf($classify->icmp('sge', $classify->load(Type::i64(), $rSlot), Value::int(Type::i64(), 0)), $hit, $z);
        $preh->store($this->scanProbeHash($preh, $key, $hash, $haveHash), $effSlot);
        $preh->br($head);
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $z, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $arr, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $next, $kok);
        $tk = $kok->load(Type::ptr(), $this->entryAddr($kok, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->or_($kok->icmp('eq', $tk, Value::null()), $kok->icmp('eq', $key, Value::null())), $next, $hpre);
        $this->hashPrefilter($hpre, $tk, $effSlot, $cmp, $next);
        $cmp->brIf($cmp->call('__mir_str_eq', Type::i1(), [$tk, $key]), $hit, $next);
        $next->store($next->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $next->br($head);
        $hit->ret(Value::int(Type::i64(), 1));
        $z->ret(Value::int(Type::i64(), 0));
    }

    /**
     * `__mir_array_unset_str(arr, key) -> void` — delete the KIND_STRING
     * entry matching `key`: slide the tail entries down one, decr len.
     * No-op on PACKED / NULL / miss. Key rc not dropped yet (Stage 3).
     */
    private function emitUnsetStr(): void
    {
        $this->emitUnsetScan('__mir_array_unset_str', true);
    }

    /**
     * `__mir_array_unset_int(arr, idx) -> void` — delete the KIND_INT
     * entry with key==idx in a HASHED array. PACKED int unset is
     * deferred (would punch a hole / depack) — no-op, matching the dual
     * backend's vec-unset deferral.
     */
    private function emitUnsetInt(): void
    {
        $this->emitUnsetScan('__mir_array_unset_int', false);
    }

    /**
     * Shared HASHED delete: linear find (strcmp for str, == for int),
     * memmove the tail down one entry, decrement len. `$isStr` selects
     * the key kind / compare. Second param is `ptr key` (str) or
     * `i64 idx` (int).
     */
    private function emitUnsetScan(string $symbol, bool $isStr): void
    {
        $this->module->declare('memmove', Type::ptr(), [Type::ptr(), Type::ptr(), Type::i64()]);
        $fn = $this->module->func($symbol, Type::void());
        $arr = $fn->param(Type::ptr(), 'arr');
        $key = $isStr ? $fn->param(Type::ptr(), 'key') : $fn->param(Type::i64(), 'idx');
        $e = $fn->block('entry');
        $chk = $fn->block('chk');
        $idxc = $fn->block('idxc');
        $idxr = $fn->block('idxr');
        $idxf = $fn->block('idxf');
        $head = $fn->block('head');
        $body = $fn->block('body');
        $kok = $fn->block('kind_ok');
        $next = $fn->block('next');
        $found = $fn->block('found');
        $done = $fn->block('done');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $done, $chk);
        // HASHED only (flags != 0); PACKED is a no-op.
        $flags = $chk->load(Type::i64(), $this->hdr($chk, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $len = $chk->load(Type::i64(), $arr);
        $iSlot = $chk->alloca(Type::i64(), 'i');
        $chk->store(Value::int(Type::i64(), 0), $iSlot);
        $chk->brIf($chk->icmp('eq', $this->hashedBit($chk, $flags), Value::int(Type::i64(), 0)), $done, $idxc);
        // Index fast path: -2 (small map) → linear scan; -1 → miss (no-op);
        // else the entry index lands in iSlot and converges on $found.
        $rfU = $idxc->call('__mir_array_index_find', Type::i64(), [
            $arr,
            Value::int(Type::i64(), $isStr ? MemoryAbi::ARRAY_KIND_STRING : MemoryAbi::ARRAY_KIND_INT),
            $isStr ? $key : Value::null(),
            $isStr ? Value::int(Type::i64(), 0) : $key,
            Value::int(Type::i64(), 0), Value::int(Type::i64(), 0),
        ]);
        $idxc->brIf($idxc->icmp('eq', $rfU, Value::int(Type::i64(), -2)), $head, $idxr);
        $idxr->brIf($idxr->icmp('slt', $rfU, Value::int(Type::i64(), 0)), $done, $idxf);
        $idxf->store($rfU, $iSlot);
        $idxf->br($found);
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $done, $body);
        $wantKind = $isStr ? MemoryAbi::ARRAY_KIND_STRING : MemoryAbi::ARRAY_KIND_INT;
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $arr, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), $wantKind)), $next, $kok);
        if ($isStr) {
            $cmp = $fn->block('cmp');
            $tk = $kok->load(Type::ptr(), $this->entryAddr($kok, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
            $kok->brIf($kok->or_($kok->icmp('eq', $tk, Value::null()), $kok->icmp('eq', $key, Value::null())), $next, $cmp);
            $cmp->brIf($cmp->call('__mir_str_eq', Type::i1(), [$tk, $key]), $found, $next);
        } else {
            $k = $kok->load(Type::i64(), $this->entryAddr($kok, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
            $kok->brIf($kok->icmp('eq', $k, $key), $found, $next);
        }
        $next->store($next->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $next->br($head);
        // TOMBSTONE deletion (O(1)): remove the key from the bucket index by
        // backshift (no O(nbuckets) sweep — no entry index shifts), mark the
        // entry KIND_DELETED, and bump the tombstone counter in the flags word
        // (bits 8+, preserving the HASHED bit). No memmove, no len change. The
        // holes are removed lazily by {@see emitCompact} on the next full
        // iteration; lookups skip a dead entry via its KIND. This turns an
        // interleaved unset/lookup churn from ~O(n) per op into O(1).
        $fi = $found->load(Type::i64(), $iSlot);
        $found->call('__mir_array_index_remove', Type::void(), [$arr, $fi]);
        $found->store(Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_DELETED),
            $this->entryAddr($found, $arr, $fi, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $fl = $found->load(Type::i64(), $this->hdr($found, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $found->store($found->add($fl, Value::int(Type::i64(), 256)),
            $this->hdr($found, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $found->br($done);
        $done->retVoid();
    }
}
