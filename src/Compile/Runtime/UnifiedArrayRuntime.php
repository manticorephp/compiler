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
    private const INDEX_THRESHOLD = 16;

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
        $this->emitRetain();
        $this->emitRelease();
        $this->emitIsHashed();
        $this->emitGetInt();
        $this->emitGetStr();
        $this->emitPromote();
        $this->emitSetInt();
        $this->emitSetStr();
        $this->emitAppend();
        $this->emitCow();
        $this->emitRefSlot();
        $this->emitRefSlotStr();
        $this->emitValueAt();
        $this->emitKeyAt();
        $this->emitKeyCellAt();
        $this->emitPop();
        $this->emitShift();
        $this->emitUnshift();
        $this->emitImplode();
        $this->emitIssetInt();
        $this->emitIssetStr();
        $this->emitUnsetStr();
        $this->emitUnsetInt();
        $this->emitCopy();
        $this->emitHashStr();
        $this->emitIndexDrop();
        $this->emitIndexBuild();
        $this->emitIndexFind();
        $this->emitIndexAdd();
    }

    /**
     * `__mir_array_hash_str(ptr key) -> i64` — FNV-1a 64-bit over the key's
     * `len@-16` bytes (binary-safe: an embedded NUL is hashed, not a
     * terminator), with __mir_strlen's libc fallback for a raw key. Agrees
     * with the __mir_str_eq key comparison (both len-based). NULL → 0. Raw
     * LLVM: FNV needs a WRAPPING `mul`, but the structured builder forces
     * `mul nsw` (overflow UB).
     */
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
        $esz = $go->select($go->icmp('ne', $flags, Value::int(Type::i64(), 0)),
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

    /** PACKED → 8, HASHED → 24 (element/entry stride). */
    private function elemSize(Block $b, Value $arr): Value
    {
        $flags = $b->load(Type::i64(), $this->hdr($b, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        return $b->select(
            $b->icmp('ne', $flags, Value::int(Type::i64(), 0)),
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
     * `__mir_array_retain(arr)` — rc += 1. No-op on NULL. Tag-guarded:
     * a buffer carrying any sentinel other than {@see MemoryAbi::ARRAY_TAG_MAGIC}
     * at `ptr-8` is not a unified array → bail before touching rc@24.
     */
    private function emitRetain(): void
    {
        $fn = $this->module->func('__mir_array_retain', Type::void());
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
        $bump->retVoid();
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
        $this->emitReleaseVariant('__mir_array_release', '');
        $this->emitReleaseVariant('__mir_array_release_obj', 'obj');
        $this->emitReleaseVariant('__mir_array_release_str', 'str');
    }

    /** Drop one rc value `v` (i64) as obj / str. No-op for ''. */
    private function emitDropValue(Block $b, Value $v, string $flavor): void
    {
        if ($flavor === '') { return; }
        $p = $b->inttoptr($v, Type::ptr());
        $fn = $flavor === 'str' ? '__mir_rc_release_str' : '__mir_rc_release';
        $b->call($fn, Type::void(), [$p]);
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
        $isH = $free->icmp('ne', $flags, Value::int(Type::i64(), 0));
        $iSlot = $free->alloca(Type::i64(), 'di');
        $free->store(Value::int(Type::i64(), 0), $iSlot);

        $hhead = $fn->block('hhead');
        if ($valueFlavor === '') {
            // PACKED scalar: nothing to drop → straight to free. HASHED: drop keys.
            $free->brIf($isH, $hhead, $freeb);
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
        $hbody = $fn->block('hbody');
        $hkey  = $fn->block('hkey');
        $hval  = $fn->block('hval');
        $hi = $hhead->load(Type::i64(), $iSlot);
        $hhead->brIf($hhead->icmp('sge', $hi, $len), $freeb, $hbody);
        $kind = $hbody->load(Type::i64(), $this->entryAddr($hbody, $arr, $hi, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $hbody->brIf($hbody->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $hkey, $hval);
        $kp = $hkey->load(Type::ptr(), $this->entryAddr($hkey, $arr, $hi, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $hkey->call('__mir_rc_release_str', Type::void(), [$kp]);
        $hkey->br($hval);
        if ($valueFlavor !== '') {
            $vv = $hval->load(Type::i64(), $this->entryAddr($hval, $arr, $hi, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET));
            $this->emitDropValue($hval, $vv, $valueFlavor);
        }
        $hval->store($hval->add($hi, Value::int(Type::i64(), 1)), $iSlot);
        $hval->br($hhead);

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
        $chk->ret($chk->zext($chk->icmp('ne', $flags, Value::int(Type::i64(), 0)), Type::i64()));
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
        $chk->brIf($chk->icmp('ne', $flags, Value::int(Type::i64(), 0)), $doidx, $packed);

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
        $head = $fn->block('head');
        $body = $fn->block('body');
        $kok = $fn->block('kind_ok');
        $cmp = $fn->block('cmp');
        $next = $fn->block('next');
        $hit = $fn->block('hit');

        $e->brIf($e->icmp('eq', $arr, Value::null()), $retzero, $chk);
        $flags = $chk->load(Type::i64(), $this->hdr($chk, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $len = $chk->load(Type::i64(), $arr);
        $iSlot = $chk->alloca(Type::i64(), 'i');
        $rSlot = $chk->alloca(Type::i64(), 'r');
        $chk->store(Value::int(Type::i64(), 0), $iSlot);
        $chk->brIf($chk->icmp('eq', $flags, Value::int(Type::i64(), 0)), $retzero, $gate);

        // Index fast path: a null key never matches; else find returns -2
        // (small map → linear scan), -1 (miss), or the entry index (hit).
        $gate->brIf($gate->icmp('eq', $key, Value::null()), $retzero, $doidx);
        $rf = $doidx->call('__mir_array_index_find', Type::i64(),
            [$arr, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING), $key, Value::int(Type::i64(), 0), $hash, $haveHash]);
        $doidx->store($rf, $rSlot);
        $doidx->brIf($doidx->icmp('eq', $rf, Value::int(Type::i64(), -2)), $head, $chkmiss);
        $chkmiss->brIf($chkmiss->icmp('eq', $chkmiss->load(Type::i64(), $rSlot), Value::int(Type::i64(), -1)), $retzero, $idxhit);
        $ij = $idxhit->load(Type::i64(), $rSlot);
        $idxhit->ret($idxhit->load(Type::i64(), $this->entryAddr($idxhit, $arr, $ij, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET)));

        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $retzero, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $arr, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $next, $kok);
        $tk = $kok->load(Type::ptr(), $this->entryAddr($kok, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->or_($kok->icmp('eq', $tk, Value::null()), $kok->icmp('eq', $key, Value::null())), $next, $cmp);
        $cmp->brIf($cmp->call('__mir_str_eq', Type::i1(), [$tk, $key]), $hit, $next);
        $next->store($next->add($i, Value::int(Type::i64(), 1)), $iSlot);
        $next->br($head);
        $hit->ret($hit->load(Type::i64(), $this->entryAddr($hit, $arr, $i, MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET)));
        $retzero->ret(Value::int(Type::i64(), 0));
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
        $e->store(Value::int(Type::i64(), MemoryAbi::ARRAY_FLAG_HASHED), $this->hdr($e, $nu, MemoryAbi::ARRAY_FLAGS_OFFSET));
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
        $e->store($arr, $arrSlot);
        // A set may append a new entry → invalidate the bucket index (rebuilt
        // lazily on the next large-map lookup).
        $e->call('__mir_array_index_drop', Type::void(), [$arr]);
        $flags = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $e->brIf($e->icmp('ne', $flags, Value::int(Type::i64(), 0)), $hashed, $packed);

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
        // lower key may already exist → fall through to the linear scan.
        $cur0 = $head0->load(Type::ptr(), $arrSlot);
        $ni0 = $head0->load(Type::i64(), $this->hdr($head0, $cur0, MemoryAbi::ARRAY_NEXT_INT_OFFSET));
        $head0->brIf($head0->icmp('sge', $idx, $ni0), $app, $head);

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
        $head = $fn->block('s_head');
        $body = $fn->block('s_body');
        $kok = $fn->block('s_kok');
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
        $chk->brIf($chk->icmp('ne', $flags, Value::int(Type::i64(), 0)), $idxtry, $maybeProm);
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
        $idxchk->brIf($idxchk->icmp('eq', $rf, Value::int(Type::i64(), -2)), $head, $idxupd);
        $rv = $idxupd->load(Type::i64(), $rSlot);
        $idxupd->brIf($idxupd->icmp('eq', $rv, Value::int(Type::i64(), -1)), $app, $upd);

        $cur = $head->load(Type::ptr(), $arrSlot);
        $len = $head->load(Type::i64(), $cur);
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $app, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $cur, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $next, $kok);
        $tk = $kok->load(Type::ptr(), $this->entryAddr($kok, $cur, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->or_($kok->icmp('eq', $tk, Value::null()), $kok->icmp('eq', $key, Value::null())), $next, $cmp);
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
        $flags = $e->load(Type::i64(), $this->hdr($e, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $e->brIf($e->icmp('ne', $flags, Value::int(Type::i64(), 0)), $hashed, $packed);
        $len = $packed->load(Type::i64(), $arr);
        $packed->ret($packed->call('__mir_array_set_int', Type::ptr(), [$arr, $len, $val]));
        $ni = $hashed->load(Type::i64(), $this->hdr($hashed, $arr, MemoryAbi::ARRAY_NEXT_INT_OFFSET));
        $hashed->store($hashed->add($ni, Value::int(Type::i64(), 1)), $this->hdr($hashed, $arr, MemoryAbi::ARRAY_NEXT_INT_OFFSET));
        $hashed->ret($hashed->call('__mir_array_set_int', Type::ptr(), [$arr, $ni, $val]));
    }

    /**
     * `__mir_array_cow(arr) -> ptr` — clone when shared (rc > 1), drop
     * the source rc, return the rc=1 clone; else return arr. Flat
     * memcpy of header + body (packed cap*8 or hashed cap*24). String
     * keys / ptr values stay shared (Stage 1 does not deep-retain on
     * cow; matches the assoc model).
     */
    private function emitCow(): void
    {
        $fn = $this->module->func('__mir_array_cow', Type::ptr());
        $arr = $fn->param(Type::ptr(), 'arr');
        $e = $fn->block('entry');
        $chk = $fn->block('chk');
        $clone = $fn->block('clone');
        $keep = $fn->block('keep');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $keep, $chk);
        $rcAddr = $this->hdr($chk, $arr, MemoryAbi::ARRAY_RC_OFFSET);
        $rc = $chk->load(Type::i64(), $rcAddr);
        $chk->brIf($chk->icmp('sle', $rc, Value::int(Type::i64(), 1)), $keep, $clone);

        $cap = $clone->load(Type::i64(), $this->hdr($clone, $arr, MemoryAbi::ARRAY_CAPACITY_OFFSET));
        $flags = $clone->load(Type::i64(), $this->hdr($clone, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $esz = $clone->select($clone->icmp('ne', $flags, Value::int(Type::i64(), 0)),
            Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE),
            Value::int(Type::i64(), MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE));
        $bytes = $clone->add($clone->mul($cap, $esz), Value::int(Type::i64(), MemoryAbi::ARRAY_HEADER_SIZE));
        $copy = $clone->call('__mir_alloc_array_tagged', Type::ptr(), [$bytes]);
        $clone->call('memcpy', Type::ptr(), [$copy, $arr, $bytes]);
        $clone->store(Value::int(Type::i64(), 1), $this->hdr($clone, $copy, MemoryAbi::ARRAY_RC_OFFSET));
        $clone->store(Value::int(Type::i64(), 0), $this->hdr($clone, $copy, MemoryAbi::ARRAY_NBUCKETS_OFFSET));
        $clone->store(Value::null(), $this->hdr($clone, $copy, MemoryAbi::ARRAY_BUCKETS_PTR_OFFSET));
        $clone->store($clone->sub($rc, Value::int(Type::i64(), 1)), $rcAddr);
        $clone->ret($copy);
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
        $locate->brIf($locate->icmp('ne', $flags, Value::int(Type::i64(), 0)), $hashed, $packed);

        $packed->ret($this->packedSlot($packed, $b, $key));

        // HASHED: linear scan for the KIND_INT entry with key == key.
        $len = $hashed->load(Type::i64(), $b);
        $iSlot = $hashed->alloca(Type::i64(), 'i');
        $hashed->store(Value::int(Type::i64(), 0), $iSlot);
        $hashed->br($head);
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
        $head = $fn->block('head');
        $body = $fn->block('body');
        $kok = $fn->block('kind_ok');
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

        $bi = $locate->load(Type::i64(), $slotAddr);
        $b = $locate->inttoptr($bi, Type::ptr());
        $len = $locate->load(Type::i64(), $b);
        $iSlot = $locate->alloca(Type::i64(), 'i');
        $locate->store(Value::int(Type::i64(), 0), $iSlot);
        $locate->br($head);
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $miss, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $b, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $next, $kok);
        $ek = $kok->load(Type::ptr(), $this->entryAddr($kok, $b, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->icmp('eq', $ek, Value::null()), $next, $cmp);
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
        $e->brIf($e->icmp('ne', $flags, Value::int(Type::i64(), 0)), $hashed, $packed);
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
        $e->brIf($e->icmp('ne', $flags, Value::int(Type::i64(), 0)), $hashed, $packed);
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
        $e->brIf($e->icmp('ne', $flags, Value::int(Type::i64(), 0)), $hashed, $packed);
        $packed->ret($packed->or_($packed->and_($i, $mask), $intTag));
        $kind = $hashed->load(Type::i64(), $this->entryAddr($hashed, $arr, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $hashed->brIf($hashed->icmp('eq', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $hstr, $hint);
        $sk = $hstr->load(Type::i64(), $this->entryAddr($hstr, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $hstr->ret($hstr->or_($hstr->and_($sk, $mask), $ptrTag));
        $ik = $hint->load(Type::i64(), $this->entryAddr($hint, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $hint->ret($hint->or_($hint->and_($ik, $mask), $intTag));
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
        $len = $chk->load(Type::i64(), $arr);
        $chk->brIf($chk->icmp('sle', $len, Value::int(Type::i64(), 0)), $z, $go);
        $go->call('__mir_array_index_drop', Type::void(), [$arr]);
        $nl = $go->sub($len, Value::int(Type::i64(), 1));
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
        $len = $chk->load(Type::i64(), $arr);
        $chk->brIf($chk->icmp('sle', $len, Value::int(Type::i64(), 0)), $z, $go);
        $go->call('__mir_array_index_drop', Type::void(), [$arr]);
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
        $e->raw('  %len = load i64, ptr %arr');
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
        $sumb->raw('  %ev = call i64 @__mir_array_value_at(ptr %arr, i64 %i)');
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
        $nosep->raw('  %ev2 = call i64 @__mir_array_value_at(ptr %arr, i64 %j)');
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
        $chk->brIf($chk->icmp('ne', $flags, Value::int(Type::i64(), 0)), $doidx, $packed);
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
        $head = $fn->block('head');
        $body = $fn->block('body');
        $kok = $fn->block('kind_ok');
        $cmp = $fn->block('cmp');
        $next = $fn->block('next');
        $hit = $fn->block('hit');
        $z = $fn->block('z');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $z, $chk);
        $flags = $chk->load(Type::i64(), $this->hdr($chk, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $len = $chk->load(Type::i64(), $arr);
        $iSlot = $chk->alloca(Type::i64(), 'i');
        $rSlot = $chk->alloca(Type::i64(), 'r');
        $chk->store(Value::int(Type::i64(), 0), $iSlot);
        $chk->brIf($chk->icmp('eq', $flags, Value::int(Type::i64(), 0)), $z, $gate);
        // A null key never matches; else index fast path (-2 → linear).
        $gate->brIf($gate->icmp('eq', $key, Value::null()), $z, $doidx);
        $rf = $doidx->call('__mir_array_index_find', Type::i64(),
            [$arr, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING), $key, Value::int(Type::i64(), 0), $hash, $haveHash]);
        $doidx->store($rf, $rSlot);
        $doidx->brIf($doidx->icmp('eq', $rf, Value::int(Type::i64(), -2)), $head, $classify);
        $classify->brIf($classify->icmp('sge', $classify->load(Type::i64(), $rSlot), Value::int(Type::i64(), 0)), $hit, $z);
        $i = $head->load(Type::i64(), $iSlot);
        $head->brIf($head->icmp('sge', $i, $len), $z, $body);
        $kind = $body->load(Type::i64(), $this->entryAddr($body, $arr, $i, MemoryAbi::ARRAY_ENTRY_KIND_OFFSET));
        $body->brIf($body->icmp('ne', $kind, Value::int(Type::i64(), MemoryAbi::ARRAY_KIND_STRING)), $next, $kok);
        $tk = $kok->load(Type::ptr(), $this->entryAddr($kok, $arr, $i, MemoryAbi::ARRAY_ENTRY_KEY_OFFSET));
        $kok->brIf($kok->or_($kok->icmp('eq', $tk, Value::null()), $kok->icmp('eq', $key, Value::null())), $next, $cmp);
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
        $head = $fn->block('head');
        $body = $fn->block('body');
        $kok = $fn->block('kind_ok');
        $next = $fn->block('next');
        $found = $fn->block('found');
        $done = $fn->block('done');
        $e->brIf($e->icmp('eq', $arr, Value::null()), $done, $chk);
        // A delete shifts entry indices → invalidate the bucket index.
        $chk->call('__mir_array_index_drop', Type::void(), [$arr]);
        // HASHED only (flags != 0); PACKED is a no-op.
        $flags = $chk->load(Type::i64(), $this->hdr($chk, $arr, MemoryAbi::ARRAY_FLAGS_OFFSET));
        $len = $chk->load(Type::i64(), $arr);
        $iSlot = $chk->alloca(Type::i64(), 'i');
        $chk->store(Value::int(Type::i64(), 0), $iSlot);
        $chk->brIf($chk->icmp('eq', $flags, Value::int(Type::i64(), 0)), $done, $head);
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
        // shift entries [i+1 .. len) down one slot
        $dst = $this->entryAddr($found, $arr, $i, 0);
        $src = $this->entryAddr($found, $arr, $found->add($i, Value::int(Type::i64(), 1)), 0);
        $tail = $found->sub($found->sub($len, $i), Value::int(Type::i64(), 1));
        $bytes = $found->mul($tail, Value::int(Type::i64(), MemoryAbi::ARRAY_ENTRY_SIZE));
        $found->call('memmove', Type::ptr(), [$dst, $src, $bytes]);
        $found->store($found->sub($len, Value::int(Type::i64(), 1)), $arr);
        $found->br($done);
        $done->retVoid();
    }
}
