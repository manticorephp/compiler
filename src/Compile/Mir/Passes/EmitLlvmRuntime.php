<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Type;

trait EmitLlvmRuntime
{
    /**
     * Size-classed small-object pool (see {@see \Compile\MemoryAbi::POOL_GRAIN}
     * for the shape and the ⚠ that goes with it). Three entry points:
     *
     *  - `@__mir_pool_alloc(n)` — a block of `n` bytes, from a class free list
     *    or carved out of a span; `n > POOL_MAX_SMALL` falls through to malloc.
     *  - `@__mir_pool_free(p)` — back to its class list, or to libc `free` when
     *    `p` is not one of ours.
     *  - `@__mir_pool_size(p)` — the block's class size, or 0 when `p` is not
     *    pooled. Only `__mir_realloc_tagged` needs it, and only because libc
     *    `realloc` on a pooled block would corrupt the heap.
     *
     * Everything is `linkonce_odr`, pools INCLUDED — the string pool's ODR
     * lesson (alloc draining one head while reclaim fills another) applies
     * verbatim, and here the failure would be an abort rather than a leak.
     */
    private function poolRuntime(): string
    {
        if (!\Compile\Debug::$pool) { return ''; }
        $this->libcExtra['malloc'] = 'declare ptr @malloc(i64)';
        $this->libcExtra['free']   = 'declare void @free(ptr)';
        $this->libcExtra['mmap']   = 'declare ptr @mmap(ptr, i64, i32, i32, i32, i64)';
        $grain  = (string)\Compile\MemoryAbi::POOL_GRAIN;
        $maxSm  = (string)\Compile\MemoryAbi::POOL_MAX_SMALL;
        $nCls   = (string)\Compile\MemoryAbi::POOL_CLASSES;
        $span   = (string)\Compile\MemoryAbi::POOL_SPAN_SIZE;
        $spanHd = (string)\Compile\MemoryAbi::POOL_SPAN_HEADER;
        $region = (string)\Compile\MemoryAbi::POOL_REGION_BYTES;
        $mask   = (string)(-\Compile\MemoryAbi::POOL_SPAN_SIZE);   // ~(SPAN-1)
        $shift  = 4;                                               // log2(GRAIN)
        // MAP_PRIVATE|MAP_ANON — the constant differs between the hosts, same
        // pair the fiber stack allocator uses.
        $mflags = \Manticore\is_darwin() ? 0x1002 : 0x22;

        // base/top bracket the reserved range; both 0 until the first alloc, so
        // an uninitialised (or failed) pool answers "not mine" for every pointer
        // and the whole thing degrades to plain malloc/free.
        $out  = "@__mir_pool_base = linkonce_odr global i64 0\n";
        $out .= "@__mir_pool_top = linkonce_odr global i64 0\n";
        $out .= "@__mir_pool_next = linkonce_odr global i64 0\n";
        $out .= "@__mir_pool_ready = linkonce_odr global i64 0\n";
        $out .= "@__mir_pool_list = linkonce_odr global [" . $nCls . " x ptr] zeroinitializer\n";
        $out .= "@__mir_pool_cur = linkonce_odr global [" . $nCls . " x i64] zeroinitializer\n";
        $out .= "@__mir_pool_lim = linkonce_odr global [" . $nCls . " x i64] zeroinitializer\n";
        if (\Compile\Debug::$verify) {
            $raw = '[VERIFY] pool_free: block already on a free list (double free) p=%p';
            $out .= '@.vfy.pool = private unnamed_addr constant ['
                  . (string)(\strlen($raw) + 2) . ' x i8] c"' . $raw . '\0A\00", align 1' . "\n";
        }

        // Reserve the range once. mmap hands back page-aligned memory, not
        // SPAN-aligned, so round up and reserve one span extra — the mask in
        // free() only finds a span header if spans are SPAN-ALIGNED.
        $out .= "define void @__mir_pool_init() {\n";
        $out .= "entry:\n";
        $out .= "  %r = load i64, ptr @__mir_pool_ready\n";
        $out .= "  %done = icmp ne i64 %r, 0\n";
        $out .= "  br i1 %done, label %ret, label %go\n";
        $out .= "go:\n";
        $out .= "  store i64 1, ptr @__mir_pool_ready\n";
        $out .= "  %m = call ptr @mmap(ptr null, i64 " . (string)(\Compile\MemoryAbi::POOL_REGION_BYTES
              + \Compile\MemoryAbi::POOL_SPAN_SIZE) . ", i32 3, i32 " . (string)$mflags . ", i32 -1, i64 0)\n";
        $out .= "  %mi = ptrtoint ptr %m to i64\n";
        // MAP_FAILED is -1, not null (the fiber allocator learned this the hard
        // way). A failed reserve leaves base/top at 0 ⇒ permanent bypass.
        $out .= "  %bad = icmp eq i64 %mi, -1\n";
        $out .= "  %bad0 = icmp eq i64 %mi, 0\n";
        $out .= "  %nope = or i1 %bad, %bad0\n";
        $out .= "  br i1 %nope, label %ret, label %ok\n";
        $out .= "ok:\n";
        $out .= "  %up = add i64 %mi, " . (string)(\Compile\MemoryAbi::POOL_SPAN_SIZE - 1) . "\n";
        $out .= "  %al = and i64 %up, " . $mask . "\n";
        $out .= "  store i64 %al, ptr @__mir_pool_base\n";
        $out .= "  store i64 %al, ptr @__mir_pool_next\n";
        $out .= "  %tp = add i64 %al, " . $region . "\n";
        $out .= "  store i64 %tp, ptr @__mir_pool_top\n";
        $out .= "  br label %ret\n";
        $out .= "ret:\n";
        $out .= "  ret void\n";
        $out .= "}\n";

        // One span for class %idx, or 0 when the range is exhausted / absent.
        $out .= "define i64 @__mir_pool_span(i64 %idx) {\n";
        $out .= "entry:\n";
        $out .= "  call void @__mir_pool_init()\n";
        $out .= "  %next = load i64, ptr @__mir_pool_next\n";
        $out .= "  %top = load i64, ptr @__mir_pool_top\n";
        $out .= "  %end = add i64 %next, " . $span . "\n";
        $out .= "  %full = icmp ugt i64 %end, %top\n";
        $out .= "  br i1 %full, label %none, label %take\n";
        $out .= "take:\n";
        $out .= "  store i64 %end, ptr @__mir_pool_next\n";
        $out .= "  %sp = inttoptr i64 %next to ptr\n";
        $out .= "  store i64 %idx, ptr %sp\n";                     // span word 0 = class
        $out .= "  ret i64 %next\n";
        $out .= "none:\n";
        $out .= "  ret i64 0\n";
        $out .= "}\n";

        $out .= "define ptr @__mir_pool_alloc(i64 %n) {\n";
        $out .= "entry:\n";
        $out .= $this->profBump(16);
        $out .= "  %big = icmp ugt i64 %n, " . $maxSm . "\n";
        $out .= "  br i1 %big, label %bypass, label %small\n";
        $out .= "bypass:\n";
        $out .= $this->profBump(20);
        $out .= "  %mb = call ptr @malloc(i64 %n)\n";
        $out .= "  ret ptr %mb\n";
        $out .= "small:\n";
        // idx = ceil(n / GRAIN), floored at 1 so a 0-byte request still gets a
        // real block (callers add headers, but nothing guarantees it).
        $out .= "  %up = add i64 %n, " . (string)(\Compile\MemoryAbi::POOL_GRAIN - 1) . "\n";
        $out .= "  %i0 = lshr i64 %up, " . (string)$shift . "\n";
        $out .= "  %z = icmp eq i64 %i0, 0\n";
        $out .= "  %idx = select i1 %z, i64 1, i64 %i0\n";
        $out .= "  %lp = getelementptr inbounds [" . $nCls . " x ptr], ptr @__mir_pool_list, i64 0, i64 %idx\n";
        $out .= "  %h = load ptr, ptr %lp\n";
        $out .= "  %hn = icmp eq ptr %h, null\n";
        $out .= "  br i1 %hn, label %carve, label %pop\n";
        $out .= "pop:\n";
        $out .= $this->profBump(17);
        $out .= "  %nx = load ptr, ptr %h\n";                      // intrusive next @ +0
        $out .= "  store ptr %nx, ptr %lp\n";
        if (\Compile\Debug::$verify) {
            $out .= "  %clr = getelementptr inbounds i8, ptr %h, i64 8\n";
            $out .= "  store i64 0, ptr %clr\n";                   // clear the free poison
        }
        $out .= "  ret ptr %h\n";
        $out .= "carve:\n";
        $out .= "  %sz = shl i64 %idx, " . (string)$shift . "\n";
        $out .= "  %cp = getelementptr inbounds [" . $nCls . " x i64], ptr @__mir_pool_cur, i64 0, i64 %idx\n";
        $out .= "  %mp = getelementptr inbounds [" . $nCls . " x i64], ptr @__mir_pool_lim, i64 0, i64 %idx\n";
        $out .= "  %cur = load i64, ptr %cp\n";
        $out .= "  %lim = load i64, ptr %mp\n";
        $out .= "  %nend = add i64 %cur, %sz\n";
        $out .= "  %fits = icmp ule i64 %nend, %lim\n";
        $out .= "  %live = icmp ne i64 %cur, 0\n";
        $out .= "  %use = and i1 %fits, %live\n";
        $out .= "  br i1 %use, label %bump, label %fresh\n";
        $out .= "bump:\n";
        $out .= $this->profBump(18);
        $out .= "  store i64 %nend, ptr %cp\n";
        $out .= "  %bb = inttoptr i64 %cur to ptr\n";
        $out .= "  ret ptr %bb\n";
        $out .= "fresh:\n";
        $out .= "  %sp = call i64 @__mir_pool_span(i64 %idx)\n";
        $out .= "  %no = icmp eq i64 %sp, 0\n";
        $out .= "  br i1 %no, label %bypass2, label %first\n";
        $out .= "bypass2:\n";
        $out .= $this->profBump(20);
        $out .= "  %mb2 = call ptr @malloc(i64 %n)\n";
        $out .= "  ret ptr %mb2\n";
        $out .= "first:\n";
        $out .= $this->profBump(18);
        $out .= "  %d0 = add i64 %sp, " . $spanHd . "\n";
        $out .= "  %d1 = add i64 %d0, %sz\n";
        $out .= "  store i64 %d1, ptr %cp\n";
        $out .= "  %slim = add i64 %sp, " . $span . "\n";
        $out .= "  store i64 %slim, ptr %mp\n";
        $out .= "  %fb = inttoptr i64 %d0 to ptr\n";
        $out .= "  ret ptr %fb\n";
        $out .= "}\n";

        // The class size of a pooled block, 0 for anything else. The span
        // header answers it — which is the whole reason the class lives there
        // and not in a per-block word.
        $out .= "define i64 @__mir_pool_size(ptr %p) {\n";
        $out .= "entry:\n";
        $out .= "  %u = ptrtoint ptr %p to i64\n";
        $out .= "  %b = load i64, ptr @__mir_pool_base\n";
        $out .= "  %t = load i64, ptr @__mir_pool_top\n";
        $out .= "  %ge = icmp uge i64 %u, %b\n";
        $out .= "  %lt = icmp ult i64 %u, %t\n";
        $out .= "  %in = and i1 %ge, %lt\n";
        $out .= "  br i1 %in, label %mine, label %not\n";
        $out .= "mine:\n";
        $out .= "  %sb = and i64 %u, " . $mask . "\n";
        $out .= "  %sp = inttoptr i64 %sb to ptr\n";
        $out .= "  %idx = load i64, ptr %sp\n";
        $out .= "  %sz = shl i64 %idx, " . (string)$shift . "\n";
        $out .= "  ret i64 %sz\n";
        $out .= "not:\n";
        $out .= "  ret i64 0\n";
        $out .= "}\n";

        $out .= "define void @__mir_pool_free(ptr %p) {\n";
        $out .= "entry:\n";
        $out .= "  %nul = icmp eq ptr %p, null\n";
        $out .= "  br i1 %nul, label %ret, label %chk\n";
        $out .= "chk:\n";
        $out .= "  %u = ptrtoint ptr %p to i64\n";
        $out .= "  %b = load i64, ptr @__mir_pool_base\n";
        $out .= "  %t = load i64, ptr @__mir_pool_top\n";
        $out .= "  %ge = icmp uge i64 %u, %b\n";
        $out .= "  %lt = icmp ult i64 %u, %t\n";
        $out .= "  %in = and i1 %ge, %lt\n";
        $out .= "  br i1 %in, label %mine, label %libc\n";
        $out .= "libc:\n";
        $out .= "  call void @free(ptr %p)\n";
        $out .= "  ret void\n";
        $out .= "mine:\n";
        $out .= $this->profBump(19);
        $out .= "  %sb = and i64 %u, " . $mask . "\n";
        $out .= "  %sp = inttoptr i64 %sb to ptr\n";
        $out .= "  %idx = load i64, ptr %sp\n";
        $out .= "  %lp = getelementptr inbounds [" . $nCls . " x ptr], ptr @__mir_pool_list, i64 0, i64 %idx\n";
        if (\Compile\Debug::$verify) {
            // libc used to catch a double free for us and abort with a name.
            // A pooled block would instead be pushed onto its class list twice,
            // making a cycle — silent, and fatal much later somewhere else.
            // Verify mode restores the loud version: word +8 of a free block
            // carries a poison the allocator clears on the way out, so a second
            // free sees it. Every class is >= 16 bytes, so +8 is inside.
            $out .= "  %poisp = getelementptr inbounds i8, ptr %p, i64 8\n";
            $out .= "  %pois = load i64, ptr %poisp\n";
            $out .= "  %dbl = icmp eq i64 %pois, " . (string)\Compile\MemoryAbi::POOL_FREE_POISON . "\n";
            $out .= "  br i1 %dbl, label %vfail, label %push\n";
            $out .= "vfail:\n";
            if ($this->rt->needsOutBuf) { $out .= "  call void @__mir_out_flush()\n"; }
            $out .= "  call i32 (i32, ptr, ...) @dprintf(i32 2, ptr @.vfy.pool, ptr %p)\n";
            $out .= "  call void @abort()\n";
            $out .= "  unreachable\n";
            $out .= "push:\n";
        }
        $out .= "  %h = load ptr, ptr %lp\n";
        $out .= "  store ptr %h, ptr %p\n";
        $out .= "  store ptr %p, ptr %lp\n";
        if (\Compile\Debug::$verify) {
            $out .= "  %poisw = getelementptr inbounds i8, ptr %p, i64 8\n";
            $out .= "  store i64 " . (string)\Compile\MemoryAbi::POOL_FREE_POISON . ", ptr %poisw\n";
        }
        $out .= "  ret void\n";
        $out .= "ret:\n";
        $out .= "  ret void\n";
        $out .= "}\n";
        return $out;
    }

    /** `@__mir_pool_alloc` when the pool is on, plain `@malloc` when it is not. */
    private function poolAllocCall(string $reg, string $size): string
    {
        if (!\Compile\Debug::$pool) {
            return '  ' . $reg . ' = call ptr @malloc(i64 ' . $size . ")\n";
        }
        return '  ' . $reg . ' = call ptr @__mir_pool_alloc(i64 ' . $size . ")\n";
    }

    /** `@__mir_pool_free` when the pool is on, plain `@free` when it is not. */
    private function poolFreeCall(string $ptr): string
    {
        if (!\Compile\Debug::$pool) {
            return '  call void @free(ptr ' . $ptr . ")\n";
        }
        return '  call void @__mir_pool_free(ptr ' . $ptr . ")\n";
    }

    private function allocRuntime(): string
    {
        $out  = $this->poolRuntime();
        $out .= "\ndefine ptr @__mir_alloc(i64 %n) {\n";
        $out .= "entry:\n";
        $out .= "  %p = call ptr @malloc(i64 %n)\n";
        $out .= "  ret ptr %p\n";
        $out .= "}\n";
        // Tagged allocator for rc-managed obj/vec: an 8-byte tag
        // (RC_TAG_MAGIC) precedes the data; the returned ptr is base+8 so
        // every in-header offset (class_id@0 / len@0, rc@8, props/elems@16)
        // is unchanged. The rc helpers read ptr-8 to self-route (magic ⇒
        // obj/vec rc@+8; else ⇒ string). Free always releases ptr-8.
        $magic = (string)\Compile\MemoryAbi::RC_TAG_MAGIC;
        $out .= "define ptr @__mir_alloc_tagged(i64 %n) {\n";
        $out .= "entry:\n";
        $out .= $this->profBump(21);
        $out .= "  %t = add i64 %n, 8\n";
        $out .= $this->poolAllocCall('%base', '%t');
        $out .= "  store i64 " . $magic . ", ptr %base\n";
        $out .= "  %d = getelementptr inbounds i8, ptr %base, i64 8\n";
        $out .= "  ret ptr %d\n";
        $out .= "}\n";
        // Tagged realloc (vec grow): realloc the BASE (ptr-8), the tag
        // rides along in the copied bytes; return new base+8.
        $this->libcExtra['realloc'] = 'declare ptr @realloc(ptr, i64)';
        $out .= "define ptr @__mir_realloc_tagged(ptr %p, i64 %n) {\n";
        $out .= "entry:\n";
        $out .= "  %base = getelementptr inbounds i8, ptr %p, i64 -8\n";
        if (\Compile\Debug::$arenaArrays) {
            // Arena-array grow (Debug::$arenaArrays): an ARRAY_TAG_ARENA buffer
            // is arena-bumped, so libc realloc/free would corrupt — route to
            // __mir_arena_realloc. The old byte size is recovered from the
            // array's OWN header (cap@+8, flags@+32; packed slot 8B / hashed
            // entry 24B), still holding the pre-grow capacity at this point.
            // Only unified arrays ever carry this tag (vec/insert callers pass
            // heap arrays), so reading the array header here is sound. The tag
            // rides along the copy/in-place; re-stamp defensively.
            $atag = (string)\Compile\MemoryAbi::ARRAY_TAG_ARENA;
            $aesz = (string)\Compile\MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE;
            $ahdr = (string)\Compile\MemoryAbi::ARRAY_HEADER_SIZE;
            $out .= "  %tag = load i64, ptr %base\n";
            $out .= "  %isarena = icmp eq i64 %tag, " . $atag . "\n";
            $out .= "  br i1 %isarena, label %arena, label %heap\n";
            $out .= "arena:\n";
            $out .= "  %capp = getelementptr inbounds i8, ptr %p, i64 " . (string)\Compile\MemoryAbi::ARRAY_CAPACITY_OFFSET . "\n";
            $out .= "  %ocap = load i64, ptr %capp\n";
            $out .= "  %flagp = getelementptr inbounds i8, ptr %p, i64 " . (string)\Compile\MemoryAbi::ARRAY_FLAGS_OFFSET . "\n";
            $out .= "  %flags = load i64, ptr %flagp\n";
            $out .= "  %flagsh = and i64 %flags, " . (string)\Compile\MemoryAbi::ARRAY_FLAG_HASHED . "\n";
            $out .= "  %ishash = icmp ne i64 %flagsh, 0\n";
            $out .= "  %esz = select i1 %ishash, i64 " . (string)\Compile\MemoryAbi::ARRAY_ENTRY_SIZE . ", i64 " . $aesz . "\n";
            $out .= "  %obody = mul i64 %ocap, %esz\n";
            $out .= "  %obytes = add i64 %obody, " . $ahdr . "\n";
            $out .= "  %osz = add i64 %obytes, 8\n";
            $out .= "  %nsz = add i64 %n, 8\n";
            $out .= "  %nbase = call ptr @__mir_arena_realloc(ptr %base, i64 %osz, i64 %nsz)\n";
            $out .= "  store i64 " . $atag . ", ptr %nbase\n";
            $out .= "  %nd = getelementptr inbounds i8, ptr %nbase, i64 8\n";
            $out .= "  ret ptr %nd\n";
            $out .= "heap:\n";
        }
        $out .= "  %t = add i64 %n, 8\n";
        if (\Compile\Debug::$pool) {
            // A POOLED base is mmap memory: libc realloc on it corrupts the
            // heap exactly as free would. `__mir_pool_size` recovers the old
            // block's class size (the span header knows it), so the grow is a
            // plain alloc + copy + release. A base outside the region — a big
            // block, or one from a pre-pool build — takes the realloc arm and
            // nothing changes for it.
            $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
            $out .= "  %posz = call i64 @__mir_pool_size(ptr %base)\n";
            $out .= "  %pooled = icmp ne i64 %posz, 0\n";
            $out .= "  br i1 %pooled, label %pmove, label %plain\n";
            $out .= "pmove:\n";
            $out .= $this->poolAllocCall('%pnew', '%t');
            $out .= "  %shrink = icmp ult i64 %posz, %t\n";
            $out .= "  %cpy = select i1 %shrink, i64 %posz, i64 %t\n";
            $out .= "  call ptr @memcpy(ptr %pnew, ptr %base, i64 %cpy)\n";
            $out .= $this->poolFreeCall('%base');
            $out .= "  %pd = getelementptr inbounds i8, ptr %pnew, i64 8\n";
            $out .= "  ret ptr %pd\n";
            $out .= "plain:\n";
        }
        $out .= "  %nb = call ptr @realloc(ptr %base, i64 %t)\n";
        $out .= "  %d = getelementptr inbounds i8, ptr %nb, i64 8\n";
        $out .= "  ret ptr %d\n";
        $out .= "}\n";
        // String allocator: a 24-byte header `[cap@-24, len@-16, rc@-8]`
        // precedes the bytes; the returned ptr points at the bytes (rc stays
        // at ptr-8 so the rc/free string-vs-obj routing is unchanged). `cap` =
        // byte capacity of the data region; `len` = current content length
        // (the binary-safe source of truth — strlen() / compare read it, not
        // libc strlen). Heap strings start rc=1; arena strings rc=-1 (immortal,
        // arena bulk-frees them). `n` is the capacity (content + NUL); the
        // default `len = n-1` is exact for content+NUL allocs — over-allocating
        // producers (sprintf / append-grow) overwrite len@-16 explicitly. Free
        // base moves to ptr-24.
        // Small-string freelist: heap strings churn (every escaping concat /
        // int→str / array key is a malloc+free). Two size classes recycle freed
        // buffers — bin0 holds 64-byte allocs (data cap 40), bin1 holds 128-byte
        // (data cap 104); larger allocs go straight to malloc/free. A pooled
        // buffer's cap IS its class cap (40/104), so the free path recognises it
        // by cap and the data region is always big enough. Cuts malloc traffic
        // on the small-string hot paths (PHP's pooled emalloc analogue). The
        // intrusive next-ptr lives at base+0 while a buffer sits in a bin.
        // String-header layout constants (single source of truth: MemoryAbi).
        // base = data - HEADER; cap/len/rc live at their base-relative slots.
        $H     = (string)\Compile\MemoryAbi::STRING_HEADER_SIZE;
        $hashAt = (string)\Compile\MemoryAbi::STRING_HASH_AT;
        $capAt = (string)\Compile\MemoryAbi::STRING_CAP_AT;
        $lenAt = (string)\Compile\MemoryAbi::STRING_LEN_AT;
        $rcAt  = (string)\Compile\MemoryAbi::STRING_RC_AT;
        $p0a   = (string)\Compile\MemoryAbi::STRING_POOL0_ALLOC;
        $p1a   = (string)\Compile\MemoryAbi::STRING_POOL1_ALLOC;
        $p0c   = (string)\Compile\MemoryAbi::STRING_POOL0_CAP;
        $p1c   = (string)\Compile\MemoryAbi::STRING_POOL1_CAP;
        // linkonce_odr, NOT internal: __mir_str_alloc / __mir_str_reclaim are
        // linkonce_odr and every linked module (user .o + stdlib .o) carries a
        // copy referencing ITS OWN pool head. With internal pools that is an
        // ODR violation with real teeth — the linker keeps ONE alloc and ONE
        // reclaim, and they can land on DIFFERENT pool globals: alloc drains
        // pool A (always empty → every string mallocs), reclaim feeds pool B
        // (a bottomless list nothing ever pops) — ~64 B leaked per released
        // pooled string across the stdlib boundary (json_decode leaked one
        // string per parsed key/value; RSS 128 MB on the decode bench).
        // linkonce_odr pools coalesce to one head, exactly like the functions.
        $out .= "@__mir_strpool0 = linkonce_odr global ptr null\n";
        $out .= "@__mir_strpool1 = linkonce_odr global ptr null\n";
        $out .= "define ptr @__mir_str_alloc(i64 %n) {\n";
        $out .= "entry:\n";
        $out .= $this->profBump(0);
        $out .= "  %le40 = icmp ule i64 %n, " . $p0c . "\n";
        $out .= "  br i1 %le40, label %c0, label %chk1\n";
        $out .= "chk1:\n";
        $out .= "  %le104 = icmp ule i64 %n, " . $p1c . "\n";
        $out .= "  br i1 %le104, label %c1, label %big\n";
        // class 0 (64-byte alloc, data cap 40)
        $out .= "c0:\n";
        $out .= "  %h0 = load ptr, ptr @__mir_strpool0\n";
        $out .= "  %h0n = icmp eq ptr %h0, null\n";
        $out .= "  br i1 %h0n, label %m0, label %pop0\n";
        $out .= "pop0:\n";
        $out .= "  %nx0 = load ptr, ptr %h0\n";
        $out .= "  store ptr %nx0, ptr @__mir_strpool0\n";
        $out .= "  br label %i0\n";
        $out .= "m0:\n";
        $out .= "  %a0 = call ptr @malloc(i64 " . $p0a . ")\n";
        $out .= "  br label %i0\n";
        $out .= "i0:\n";
        $out .= "  %b0 = phi ptr [ %h0, %pop0 ], [ %a0, %m0 ]\n";
        $out .= "  %capp0 = getelementptr inbounds i8, ptr %b0, i64 " . $capAt . "\n";
        $out .= "  store i64 " . $p0c . ", ptr %capp0\n";
        $out .= "  br label %fin\n";
        // class 1 (128-byte alloc, data cap 104)
        $out .= "c1:\n";
        $out .= "  %h1 = load ptr, ptr @__mir_strpool1\n";
        $out .= "  %h1n = icmp eq ptr %h1, null\n";
        $out .= "  br i1 %h1n, label %m1, label %pop1\n";
        $out .= "pop1:\n";
        $out .= "  %nx1 = load ptr, ptr %h1\n";
        $out .= "  store ptr %nx1, ptr @__mir_strpool1\n";
        $out .= "  br label %i1\n";
        $out .= "m1:\n";
        $out .= "  %a1 = call ptr @malloc(i64 " . $p1a . ")\n";
        $out .= "  br label %i1\n";
        $out .= "i1:\n";
        $out .= "  %b1 = phi ptr [ %h1, %pop1 ], [ %a1, %m1 ]\n";
        $out .= "  %capp1 = getelementptr inbounds i8, ptr %b1, i64 " . $capAt . "\n";
        $out .= "  store i64 " . $p1c . ", ptr %capp1\n";
        $out .= "  br label %fin\n";
        // large: exact malloc, cap = n
        $out .= "big:\n";
        $out .= "  %tb = add i64 %n, " . $H . "\n";
        $out .= "  %ab = call ptr @malloc(i64 %tb)\n";
        $out .= "  %cappb = getelementptr inbounds i8, ptr %ab, i64 " . $capAt . "\n";
        $out .= "  store i64 %n, ptr %cappb\n";
        $out .= "  br label %fin\n";
        $out .= "fin:\n";
        $out .= "  %p = phi ptr [ %b0, %i0 ], [ %b1, %i1 ], [ %ab, %big ]\n";
        $out .= "  %lenp = getelementptr inbounds i8, ptr %p, i64 " . $lenAt . "\n";
        $out .= "  %len0 = sub i64 %n, 1\n";
        $out .= "  store i64 %len0, ptr %lenp\n";
        $out .= "  %rcp = getelementptr inbounds i8, ptr %p, i64 " . $rcAt . "\n";
        $out .= "  store i64 1, ptr %rcp\n";
        $out .= "  %hashp = getelementptr inbounds i8, ptr %p, i64 " . $hashAt . "\n";
        $out .= "  store i64 0, ptr %hashp\n";                        // hash = 0 (uncomputed)
        $out .= "  %d = getelementptr inbounds i8, ptr %p, i64 " . $H . "\n";
        $out .= "  ret ptr %d\n";
        $out .= "}\n";
        // Reclaim a freed string base: recycle into its size-class bin (cap
        // 40/104 — the only pooled caps), else return to libc. Same safety as
        // free (only ever called at rc==0, i.e. no live references).
        $out .= "define void @__mir_str_reclaim(ptr %sbase) {\n";
        $out .= "entry:\n";
        $out .= "  %capp = getelementptr inbounds i8, ptr %sbase, i64 " . $capAt . "\n";
        $out .= "  %cap = load i64, ptr %capp\n";
        $out .= "  %is0 = icmp eq i64 %cap, " . $p0c . "\n";
        $out .= "  br i1 %is0, label %p0, label %k1\n";
        $out .= "p0:\n";
        $out .= "  %o0 = load ptr, ptr @__mir_strpool0\n";
        $out .= "  store ptr %o0, ptr %sbase\n";
        $out .= "  store ptr %sbase, ptr @__mir_strpool0\n";
        $out .= "  ret void\n";
        $out .= "k1:\n";
        $out .= "  %is1 = icmp eq i64 %cap, " . $p1c . "\n";
        $out .= "  br i1 %is1, label %p1, label %df\n";
        $out .= "p1:\n";
        $out .= "  %o1 = load ptr, ptr @__mir_strpool1\n";
        $out .= "  store ptr %o1, ptr %sbase\n";
        $out .= "  store ptr %sbase, ptr @__mir_strpool1\n";
        $out .= "  ret void\n";
        $out .= "df:\n";
        $out .= "  call void @free(ptr %sbase)\n";
        $out .= "  ret void\n";
        $out .= "}\n";
        $out .= $this->lib->stringCore();
        if ($this->rt->needsArena) {
            $out .= "define ptr @__mir_str_alloc_arena(i64 %n) {\n";
            $out .= "entry:\n";
            $out .= "  %t = add i64 %n, " . $H . "\n";
            $out .= "  %p = call ptr @__mir_arena_alloc(i64 %t)\n";
            $out .= "  %capp = getelementptr inbounds i8, ptr %p, i64 " . $capAt . "\n";
            $out .= "  store i64 %n, ptr %capp\n";
            $out .= "  %lenp = getelementptr inbounds i8, ptr %p, i64 " . $lenAt . "\n";
            $out .= "  %len0 = sub i64 %n, 1\n";
            $out .= "  store i64 %len0, ptr %lenp\n";
            $out .= "  %rcp = getelementptr inbounds i8, ptr %p, i64 " . $rcAt . "\n";
            $out .= "  store i64 -1, ptr %rcp\n";
            $out .= "  %hashp = getelementptr inbounds i8, ptr %p, i64 " . $hashAt . "\n";
            $out .= "  store i64 0, ptr %hashp\n";                    // hash = 0 (uncomputed)
            $out .= "  %d = getelementptr inbounds i8, ptr %p, i64 " . $H . "\n";
            $out .= "  ret ptr %d\n";
            $out .= "}\n";
            // Arena unified-array allocators (Debug::$arenaArrays; flag ⇒
            // needsArena so this lives inside the arena block). Mirror the
            // heap __mir_alloc_array_tagged / __mir_array_alloc, but bump the
            // base buffer from the arena and stamp ARRAY_TAG_ARENA so the rc
            // helpers bail (retain/release proceed only on ARRAY_TAG_MAGIC) and
            // the grow/promote/index paths route to the arena allocator. rc is
            // set to 1 (immaterial — retain/release bail on the tag — but a
            // stray cow sees rc<=1 and never clones/decrements). The arena
            // bulk-frees the whole buffer at scope exit; no free() ever runs.
            if (\Compile\Debug::$arenaArrays) {
            $atag = (string)\Compile\MemoryAbi::ARRAY_TAG_ARENA;
            $ahdr = (string)\Compile\MemoryAbi::ARRAY_HEADER_SIZE;
            $aesz = (string)\Compile\MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE;
            $acap = (string)\Compile\MemoryAbi::ARRAY_CAPACITY_OFFSET;
            $arc  = (string)\Compile\MemoryAbi::ARRAY_RC_OFFSET;
            $out .= "define ptr @__mir_alloc_array_tagged_arena(i64 %n) {\n";
            $out .= "entry:\n";
            $out .= "  %t = add i64 %n, 8\n";
            $out .= "  %base = call ptr @__mir_arena_alloc(i64 %t)\n";
            $out .= "  store i64 " . $atag . ", ptr %base\n";
            $out .= "  %d = getelementptr inbounds i8, ptr %base, i64 8\n";
            $out .= "  ret ptr %d\n";
            $out .= "}\n";
            $out .= "define ptr @__mir_array_alloc_arena(i64 %capin) {\n";
            $out .= "entry:\n";
            $out .= "  %neg = icmp slt i64 %capin, 0\n";
            $out .= "  %cap = select i1 %neg, i64 0, i64 %capin\n";
            $out .= "  %body = mul i64 %cap, " . $aesz . "\n";
            $out .= "  %bytes = add i64 %body, " . $ahdr . "\n";
            $out .= "  %arr = call ptr @__mir_alloc_array_tagged_arena(i64 %bytes)\n";
            $out .= "  call ptr @memset(ptr %arr, i32 0, i64 " . $ahdr . ")\n";
            $out .= "  %capp = getelementptr inbounds i8, ptr %arr, i64 " . $acap . "\n";
            $out .= "  store i64 %cap, ptr %capp\n";
            $out .= "  %rcp = getelementptr inbounds i8, ptr %arr, i64 " . $arc . "\n";
            $out .= "  store i64 1, ptr %rcp\n";
            $out .= "  ret ptr %arr\n";
            $out .= "}\n";
            }
        }
        if ($this->rt->needsRc) {
            // Reference counting for escaping (RcHeap) vec / obj. Both
            // layouts carry the refcount at header[1] (offset 8): vec is
            // [len, rc, ...], obj is [class_id, rc, ...]. retain bumps it
            // on each extra owner (heap store / obj alias / container
            // element / capture); release drops it at scope exit and
            // frees at zero. Null-safe. Free is shallow (nested rc values
            // leak — recursive drop is a later step).
            // Self-routing retain: read the tag at ptr-8. RC_TAG_MAGIC ⇒
            // genuine obj/vec (rc at ptr+8). Otherwise the ptr is a string
            // (its rc IS the word at ptr-8); a wrong static type that sent a
            // string here can no longer corrupt ptr+8 (the misroute kill).
            $out .= "define void @__mir_rc_retain(ptr %p) {\n";
            $out .= "entry:\n";
            $out .= "  %z = icmp eq ptr %p, null\n";
            $out .= "  br i1 %z, label %done, label %check\n";
            $out .= "check:\n";
            $out .= "  %tagp = getelementptr i8, ptr %p, i64 -8\n";
            $out .= "  %tag = load i64, ptr %tagp\n";
            $out .= "  %isov = icmp eq i64 %tag, " . $magic . "\n";
            $out .= "  br i1 %isov, label %ov, label %str\n";
            $out .= "ov:\n";
            $out .= $this->profBump(3);
            $out .= "  %rcp = getelementptr i8, ptr %p, i64 8\n";
            $out .= "  %rc = load i64, ptr %rcp\n";
            $out .= "  %rc1 = add i64 %rc, 1\n";
            $out .= "  store i64 %rc1, ptr %rcp\n";
            $out .= "  br label %done\n";
            $out .= "str:\n";
            $out .= "  %imm = icmp slt i64 %tag, 0\n";
            $out .= "  br i1 %imm, label %done, label %sinc\n";
            $out .= "sinc:\n";
            $out .= "  %src1 = add i64 %tag, 1\n";
            $out .= "  store i64 %src1, ptr %tagp\n";
            $out .= "  br label %done\n";
            $out .= "done:\n";
            $out .= "  ret void\n";
            $out .= "}\n";
            // Self-routing release (obj path: recursive prop drop). Magic at
            // ptr-8 ⇒ obj/vec (rc@+8, drop_dispatch + free base=ptr-8). Else
            // the ptr is a string ⇒ its rc@ptr-8, free base=ptr-24 at zero
            // (the string header is [cap@-24, len@-16, rc@-8]; obj/vec base -8).
            $out .= $this->rcVerifyAliveFormat();
            $out .= "define void @__mir_rc_release(ptr %p) {\n";
            $out .= "entry:\n";
            $out .= "  %z = icmp eq ptr %p, null\n";
            $out .= "  br i1 %z, label %done, label %check\n";
            $out .= "check:\n";
            $out .= "  %tagp = getelementptr i8, ptr %p, i64 -8\n";
            $out .= "  %tag = load i64, ptr %tagp\n";
            $out .= "  %isov = icmp eq i64 %tag, " . $magic . "\n";
            $out .= "  br i1 %isov, label %ov, label %str\n";
            $out .= "ov:\n";
            $out .= $this->profBump(4);
            $out .= "  %rcp = getelementptr i8, ptr %p, i64 8\n";
            $out .= "  %rc = load i64, ptr %rcp\n";
            // ⚠ The rc WORD LAYOUT is an ABI fact, not a per-module option: the
            // live count is the SIGNED low-56-bit field and the top byte carries
            // the collector's color / buffered bits. This test must therefore
            // read the field the same way in EVERY module.
            //
            // It did not. The zero test used to be a raw `icmp sle i64 %rc1, 0`
            // unless THIS module needed the collector — and `__mir_rc_release`
            // is `linkonce_odr`, so a program links ONE of the two disagreeing
            // bodies. An object created inside `lib/manticore_stdlib.o` (built
            // WITH the collector) carries a word like `0x8100000000000005`,
            // which as a raw i64 is NEGATIVE: the collector-less copy freed it
            // on the FIRST drop, at refcount 5. That is the stream_select loop's
            // dead \Resource — an lldb watchpoint on the rc word caught the
            // decrement 6→5 and the free in the same call. It was invisible at
            // -O0, under MANTICORE_DEBUG_VERIFY and in --memory=arena, because
            // each of those changes which copy or inlining survives.
            $out .= $this->rcVerifyAlive();
            $out .= "  %rc1 = sub i64 %rc, 1\n";
            $out .= "  store i64 %rc1, ptr %rcp\n";
            $out .= "  %rcsh = shl i64 %rc1, 8\n";
            $out .= "  %rcsig = ashr i64 %rcsh, 8\n";
            $out .= "  %zero = icmp sle i64 %rcsig, 0\n";
            $out .= "  br i1 %zero, label %free, label %keep\n";
            $out .= "keep:\n";
            if ($this->rt->needsCc) {
                // On `rc>0` after a dec the object MIGHT be a cycle root
                // (Bacon-Rajan PossibleRoot). Only a module that carries the
                // collector can register one — a module without it simply does
                // not participate, which costs cycle collection, never safety.
                $out .= "  call void @__manticore_cc_add_root(ptr %p)\n";
            }
            $out .= "  br label %done\n";
            $out .= "free:\n";
            // A *buffered* object is NOT freed here even at rc<=0 — the
            // collector owns it, else its candidate list dangles. The mask is
            // zero in a program that never buffers, so this is a no-op there.
            $out .= "  %bufb = and i64 %rc1, " . (string)\Compile\MemoryAbi::BUFFERED_MASK . "\n";
            $out .= "  %isbuf = icmp ne i64 %bufb, 0\n";
            $out .= "  br i1 %isbuf, label %done, label %dofree\n";
            $out .= "dofree:\n";
            // Recursive drop: release this object's obj-typed properties
            // before freeing it, so nested objects don't leak.
            $out .= "  call void @__mir_drop_dispatch(ptr %p)\n";
            $out .= "  %obase = getelementptr i8, ptr %p, i64 -8\n";
            $out .= $this->poolFreeCall('%obase');
            $out .= "  br label %done\n";
            $out .= "str:\n";
            $out .= "  %imm = icmp slt i64 %tag, 0\n";
            $out .= "  br i1 %imm, label %done, label %sdec\n";
            $out .= "sdec:\n";
            $out .= "  %src1 = sub i64 %tag, 1\n";
            $out .= "  store i64 %src1, ptr %tagp\n";
            $out .= "  %szero = icmp sle i64 %src1, 0\n";
            $out .= "  br i1 %szero, label %sfree, label %done\n";
            $out .= "sfree:\n";
            $out .= "  %sbase = getelementptr i8, ptr %tagp, i64 -" . (string)\Compile\MemoryAbi::STRING_RC_AT . "\n";
            $out .= "  call void @__mir_str_reclaim(ptr %sbase)\n";
            $out .= "  br label %done\n";
            $out .= "done:\n";
            $out .= "  ret void\n";
            $out .= "}\n";
            $out .= $this->dropRuntime();
            if ($this->rt->needsCc) { $out .= $this->ccRuntime(); }
        }
        if ($this->rt->needsClosureRc) { $out .= $this->closureRcRuntime(); }
        if ($this->rt->needsStrRc) {
            // String rc: the rc word (ptr-8) holds the count; cap@-16
            // precedes it. Immortal strings (literals, arena) carry -1 and
            // are skipped by both ops, so retain never writes read-only
            // memory and release never frees an arena buffer. Heap strings
            // free the malloc base (data ptr - 16) at zero.
            // Self-routing too: a misrouted obj/vec (tag = magic at ptr-8)
            // must take the rc@+8 path, never write the tag word.
            $out .= "define void @__mir_rc_retain_str(ptr %p) {\n";
            $out .= "entry:\n";
            $out .= "  %z = icmp eq ptr %p, null\n";
            $out .= "  br i1 %z, label %done, label %hdr\n";
            $out .= "hdr:\n";
            $out .= "  %h = getelementptr inbounds i8, ptr %p, i64 -8\n";
            $out .= "  %rc = load i64, ptr %h\n";
            $out .= "  %isov = icmp eq i64 %rc, " . $magic . "\n";
            $out .= "  br i1 %isov, label %ov, label %strchk\n";
            $out .= "ov:\n";
            $out .= "  %rcp = getelementptr i8, ptr %p, i64 8\n";
            $out .= "  %orc = load i64, ptr %rcp\n";
            $out .= "  %orc1 = add i64 %orc, 1\n";
            $out .= "  store i64 %orc1, ptr %rcp\n";
            $out .= "  br label %done\n";
            $out .= "strchk:\n";
            $out .= "  %imm = icmp slt i64 %rc, 0\n";
            $out .= "  br i1 %imm, label %done, label %inc\n";
            $out .= "inc:\n";
            $out .= $this->profBump(1);
            $out .= "  %rc1 = add i64 %rc, 1\n";
            $out .= "  store i64 %rc1, ptr %h\n";
            $out .= "  br label %done\n";
            $out .= "done:\n";
            $out .= "  ret void\n";
            $out .= "}\n";
            if (\Compile\Debug::$verify) {
                $raw = '[VERIFY] str_release: rc <= 0 (double release / UAF) str=%p rc=%lld';
                $out .= '@.vfy.strrc = private unnamed_addr constant ['
                    . (string)(\strlen($raw) + 2) . ' x i8] c"' . $raw . '\0A\00", align 1' . "\n";
            }
            // Self-routing: misrouted obj/vec (tag at ptr-8) → rc@+8 path
            // (no drop_dispatch — a leak is safe; never corrupt the tag).
            $out .= "define void @__mir_rc_release_str(ptr %p) {\n";
            $out .= "entry:\n";
            $out .= "  %z = icmp eq ptr %p, null\n";
            $out .= "  br i1 %z, label %done, label %hdr\n";
            $out .= "hdr:\n";
            $out .= "  %h = getelementptr inbounds i8, ptr %p, i64 -8\n";
            $out .= "  %rc = load i64, ptr %h\n";
            $out .= "  %isov = icmp eq i64 %rc, " . $magic . "\n";
            $out .= "  br i1 %isov, label %ov, label %strchk\n";
            $out .= "ov:\n";
            $out .= "  %rcp = getelementptr i8, ptr %p, i64 8\n";
            $out .= "  %orc = load i64, ptr %rcp\n";
            $out .= "  %orc1 = sub i64 %orc, 1\n";
            $out .= "  store i64 %orc1, ptr %rcp\n";
            $out .= "  %ozero = icmp sle i64 %orc1, 0\n";
            $out .= "  br i1 %ozero, label %ovfree, label %done\n";
            $out .= "ovfree:\n";
            $out .= $this->poolFreeCall('%h');
            $out .= "  br label %done\n";
            $out .= "strchk:\n";
            $out .= "  %imm = icmp slt i64 %rc, 0\n";
            $out .= "  br i1 %imm, label %done, label %dec\n";
            $out .= "dec:\n";
            $out .= $this->profBump(2);
            // The array path has had an rc<=0 guard since forever; the STRING
            // path had none, so a string double-release just corrupted the
            // freelist somewhere else entirely. Same guard, same abort.
            if (\Compile\Debug::$verify) {
                $fmt = '@.vfy.strrc';
                $out .= "  %vbad = icmp sle i64 %rc, 0\n";
                $out .= "  br i1 %vbad, label %vfail, label %vok\n";
                $out .= "vfail:\n";
                // Drain stdout first — abort() discards the stdio buffer, so
                // without this the output leading up to the over-release is lost
                // and the trace reads as if nothing had been printed.
                if ($this->rt->needsOutBuf) { $out .= "  call void @__mir_out_flush()\n"; }
                $out .= "  call i32 (i32, ptr, ...) @dprintf(i32 2, ptr " . $fmt . ", ptr %p, i64 %rc)\n";
                $out .= "  call void @abort()\n";
                $out .= "  unreachable\n";
                $out .= "vok:\n";
            }
            $out .= "  %rc1 = sub i64 %rc, 1\n";
            $out .= "  store i64 %rc1, ptr %h\n";
            $out .= "  %zero = icmp sle i64 %rc1, 0\n";
            $out .= "  br i1 %zero, label %free, label %done\n";
            $out .= "free:\n";
            $out .= "  %sbase = getelementptr i8, ptr %h, i64 -" . (string)\Compile\MemoryAbi::STRING_RC_AT . "\n";
            $out .= "  call void @__mir_str_reclaim(ptr %sbase)\n";
            $out .= "  br label %done\n";
            $out .= "done:\n";
            $out .= "  ret void\n";
            $out .= "}\n";
        }
        if (!$this->rt->needsArena) {
            return $out;
        }
        // ── Bump-pointer arena (chunk chain + LIFO scope marks) ──
        // Chunk: [i64 next, i64 cap, i64 used, data...]; header = 24B.
        // alloc bumps `used`; a new chunk is malloc'd only when the
        // current one (and any already-linked spare) is full. enter saves
        // (chunk, used); leave restores them and zeroes spare chunks for
        // reuse — bulk free in O(spilled chunks), no per-object tracking.
        // linkonce_odr (NOT internal): the arena runtime helpers are
        // linkonce_odr and dedup to one copy across user.o + stdlib.o. The
        // STATE they touch must coalesce to one address too — else at -O2 a
        // helper inlined into stdlib.o bumps stdlib's own internal cursor
        // while the deduped function uses user.o's → split arena → flaky
        // heap corruption under heavy allocation. linkonce_odr is a no-op for
        // a lone .o (single coalesced symbol == old internal behavior).
        $out .= "@__mir_arena_head  = linkonce_odr global ptr null\n";
        $out .= "@__mir_arena_cur   = linkonce_odr global ptr null\n";
        $out .= "@__mir_arena_marks = linkonce_odr global ptr null\n";
        $out .= "@__mir_arena_sp    = linkonce_odr global i64 0\n";
        $out .= "@__mir_arena_mcap  = linkonce_odr global i64 0\n";
        // alloc: round to 16, bump current chunk, else reuse/append a chunk.
        $out .= "define ptr @__mir_arena_alloc(i64 %sz) {\n";
        $out .= "entry:\n";
        $out .= "  %a1 = add i64 %sz, 15\n";
        $out .= "  %sz16 = and i64 %a1, -16\n";
        $out .= "  br label %retry\n";
        $out .= "retry:\n";
        $out .= "  %cur = load ptr, ptr @__mir_arena_cur\n";
        $out .= "  %curnull = icmp eq ptr %cur, null\n";
        $out .= "  br i1 %curnull, label %need, label %havecur\n";
        $out .= "havecur:\n";
        $out .= "  %usedp = getelementptr i8, ptr %cur, i64 16\n";
        $out .= "  %used = load i64, ptr %usedp\n";
        $out .= "  %capp = getelementptr i8, ptr %cur, i64 8\n";
        $out .= "  %cap = load i64, ptr %capp\n";
        $out .= "  %end = add i64 %used, %sz16\n";
        $out .= "  %fits = icmp ule i64 %end, %cap\n";
        $out .= "  br i1 %fits, label %alloc, label %need\n";
        $out .= "alloc:\n";
        $out .= "  %datab = getelementptr i8, ptr %cur, i64 24\n";
        $out .= "  %p = getelementptr i8, ptr %datab, i64 %used\n";
        $out .= "  store i64 %end, ptr %usedp\n";
        $out .= "  ret ptr %p\n";
        $out .= "need:\n";
        $out .= "  br i1 %curnull, label %fromhead, label %fromnext\n";
        $out .= "fromhead:\n";
        $out .= "  %head = load ptr, ptr @__mir_arena_head\n";
        $out .= "  %headnull = icmp eq ptr %head, null\n";
        $out .= "  br i1 %headnull, label %newchunk, label %reuseh\n";
        $out .= "reuseh:\n";
        $out .= "  %hu = getelementptr i8, ptr %head, i64 16\n";
        $out .= "  store i64 0, ptr %hu\n";
        $out .= "  store ptr %head, ptr @__mir_arena_cur\n";
        $out .= "  br label %retry\n";
        $out .= "fromnext:\n";
        $out .= "  %nxp = getelementptr i8, ptr %cur, i64 0\n";
        $out .= "  %nx = load ptr, ptr %nxp\n";
        $out .= "  %nxnull = icmp eq ptr %nx, null\n";
        // The next chunk doubles this one, up to the 64 KiB ceiling. Computed
        // here because %cur is null on the other edge into newchunk.
        $out .= "  %ccapp = getelementptr i8, ptr %cur, i64 8\n";
        $out .= "  %curcap = load i64, ptr %ccapp\n";
        $out .= "  %grow = shl i64 %curcap, 1\n";
        $out .= "  %growbig = icmp ugt i64 %grow, 65536\n";
        $out .= "  %grown = select i1 %growbig, i64 65536, i64 %grow\n";
        $out .= "  br i1 %nxnull, label %newchunk, label %reusen\n";
        $out .= "reusen:\n";
        $out .= "  %nu = getelementptr i8, ptr %nx, i64 16\n";
        $out .= "  store i64 0, ptr %nu\n";
        $out .= "  store ptr %nx, ptr @__mir_arena_cur\n";
        $out .= "  br label %retry\n";
        $out .= "newchunk:\n";
        // An arena's FIRST chunk is small and every later one doubles it, to a
        // 64 KiB ceiling. Every fiber runs on its own arena (prelude/fiber.php:10
        // — a fresh ctx zeroes these globals), so a flat 64 KiB minimum was 64 KiB
        // of first-touch per concurrent task, which at tens of thousands of tasks
        // is the dominant per-task cost. A light task never leaves the first 4 KiB;
        // an allocation-heavy one pays four extra mallocs to reach the same
        // ceiling, and chunks are retained and reused, so the ramp is paid once
        // per arena lifetime.
        $out .= "  %base = phi i64 [ 4096, %fromhead ], [ %grown, %fromnext ]\n";
        // A big alloc gets 2x slack so a vec that outgrew its chunk (the
        // append-realloc copy path) has headroom to extend in place on the
        // next appends — otherwise an exact-sized chunk is full on arrival
        // and every further append re-copies to a fresh chunk (O(n^2)).
        $out .= "  %big = icmp ugt i64 %sz16, 65536\n";
        $out .= "  %dbl = shl i64 %sz16, 1\n";
        // %ncap >= %sz16 unconditionally: it is the loop invariant of the retry
        // edge, and a chunk that cannot hold the request spins instead of failing.
        $out .= "  %basefits = icmp uge i64 %base, %sz16\n";
        $out .= "  %small = select i1 %basefits, i64 %base, i64 %sz16\n";
        $out .= "  %ncap = select i1 %big, i64 %dbl, i64 %small\n";
        $out .= "  %tot = add i64 %ncap, 24\n";
        $out .= "  %chunk = call ptr @malloc(i64 %tot)\n";
        $out .= "  store ptr null, ptr %chunk\n";
        $out .= "  %ccap = getelementptr i8, ptr %chunk, i64 8\n";
        $out .= "  store i64 %ncap, ptr %ccap\n";
        $out .= "  %cused = getelementptr i8, ptr %chunk, i64 16\n";
        $out .= "  store i64 0, ptr %cused\n";
        $out .= "  br i1 %curnull, label %sethead, label %linkcur\n";
        $out .= "sethead:\n";
        $out .= "  store ptr %chunk, ptr @__mir_arena_head\n";
        $out .= "  store ptr %chunk, ptr @__mir_arena_cur\n";
        $out .= "  br label %retry\n";
        $out .= "linkcur:\n";
        $out .= "  store ptr %chunk, ptr %cur\n";
        $out .= "  store ptr %chunk, ptr @__mir_arena_cur\n";
        $out .= "  br label %retry\n";
        $out .= "}\n";
        // realloc: in-place extend when `old` is the chunk's last alloc
        // (the tight append loop), else bump a fresh block + memcpy.
        $out .= "define ptr @__mir_arena_realloc(ptr %old, i64 %oldsz, i64 %newsz) {\n";
        $out .= "entry:\n";
        $out .= "  %cur = load ptr, ptr @__mir_arena_cur\n";
        $out .= "  %curnull = icmp eq ptr %cur, null\n";
        $out .= "  br i1 %curnull, label %copy, label %trylast\n";
        $out .= "trylast:\n";
        $out .= "  %datab = getelementptr i8, ptr %cur, i64 24\n";
        $out .= "  %usedp = getelementptr i8, ptr %cur, i64 16\n";
        $out .= "  %used = load i64, ptr %usedp\n";
        $out .= "  %curend = getelementptr i8, ptr %datab, i64 %used\n";
        $out .= "  %ao = add i64 %oldsz, 15\n";
        $out .= "  %old16 = and i64 %ao, -16\n";
        $out .= "  %oldend = getelementptr i8, ptr %old, i64 %old16\n";
        $out .= "  %islast = icmp eq ptr %oldend, %curend\n";
        $out .= "  %ge = icmp uge ptr %old, %datab\n";
        $out .= "  %both = and i1 %islast, %ge\n";
        $out .= "  br i1 %both, label %inplace, label %copy\n";
        $out .= "inplace:\n";
        $out .= "  %an = add i64 %newsz, 15\n";
        $out .= "  %new16 = and i64 %an, -16\n";
        $out .= "  %capp = getelementptr i8, ptr %cur, i64 8\n";
        $out .= "  %cap = load i64, ptr %capp\n";
        $out .= "  %oldi = ptrtoint ptr %old to i64\n";
        $out .= "  %datai = ptrtoint ptr %datab to i64\n";
        $out .= "  %oldoff = sub i64 %oldi, %datai\n";
        $out .= "  %nused = add i64 %oldoff, %new16\n";
        $out .= "  %room = icmp ule i64 %nused, %cap\n";
        $out .= "  br i1 %room, label %extend, label %copy\n";
        $out .= "extend:\n";
        $out .= "  store i64 %nused, ptr %usedp\n";
        $out .= "  ret ptr %old\n";
        $out .= "copy:\n";
        $out .= "  %an2 = add i64 %newsz, 15\n";
        $out .= "  %nz = and i64 %an2, -16\n";
        $out .= "  %np = call ptr @__mir_arena_alloc(i64 %nz)\n";
        $out .= "  call ptr @memcpy(ptr %np, ptr %old, i64 %oldsz)\n";
        $out .= "  ret ptr %np\n";
        $out .= "}\n";
        // enter: push (cur, cur.used) as a scope mark (2 i64 each).
        $out .= "define void @__mir_arena_enter() {\n";
        $out .= "entry:\n";
        $out .= "  %cur = load ptr, ptr @__mir_arena_cur\n";
        $out .= "  %curnull = icmp eq ptr %cur, null\n";
        $out .= "  br i1 %curnull, label %uz, label %ul\n";
        $out .= "ul:\n";
        $out .= "  %up = getelementptr i8, ptr %cur, i64 16\n";
        $out .= "  %u = load i64, ptr %up\n";
        $out .= "  br label %m\n";
        $out .= "uz:\n";
        $out .= "  br label %m\n";
        $out .= "m:\n";
        $out .= "  %used = phi i64 [0, %uz], [%u, %ul]\n";
        $out .= "  %sp = load i64, ptr @__mir_arena_sp\n";
        $out .= "  %mcap = load i64, ptr @__mir_arena_mcap\n";
        $out .= "  %full = icmp sge i64 %sp, %mcap\n";
        $out .= "  br i1 %full, label %grow, label %store\n";
        $out .= "grow:\n";
        $out .= "  %z = icmp eq i64 %mcap, 0\n";
        $out .= "  %m2 = mul i64 %mcap, 2\n";
        $out .= "  %ncap = select i1 %z, i64 16, i64 %m2\n";
        $out .= "  %nb = mul i64 %ncap, 16\n";
        $out .= "  %oldm = load ptr, ptr @__mir_arena_marks\n";
        $out .= "  %newm = call ptr @realloc(ptr %oldm, i64 %nb)\n";
        $out .= "  store ptr %newm, ptr @__mir_arena_marks\n";
        $out .= "  store i64 %ncap, ptr @__mir_arena_mcap\n";
        $out .= "  br label %store\n";
        $out .= "store:\n";
        $out .= "  %marks = load ptr, ptr @__mir_arena_marks\n";
        $out .= "  %base = mul i64 %sp, 2\n";
        $out .= "  %s0 = getelementptr i64, ptr %marks, i64 %base\n";
        $out .= "  %curi = ptrtoint ptr %cur to i64\n";
        $out .= "  store i64 %curi, ptr %s0\n";
        $out .= "  %b1 = add i64 %base, 1\n";
        $out .= "  %s1 = getelementptr i64, ptr %marks, i64 %b1\n";
        $out .= "  store i64 %used, ptr %s1\n";
        $out .= "  %sp1 = add i64 %sp, 1\n";
        $out .= "  store i64 %sp1, ptr @__mir_arena_sp\n";
        $out .= "  ret void\n";
        $out .= "}\n";
        // leave: pop mark, restore cur + its used, zero spare chunks.
        $out .= "define void @__mir_arena_leave() {\n";
        $out .= "entry:\n";
        $out .= "  %sp = load i64, ptr @__mir_arena_sp\n";
        $out .= "  %sp1 = sub i64 %sp, 1\n";
        $out .= "  store i64 %sp1, ptr @__mir_arena_sp\n";
        $out .= "  %marks = load ptr, ptr @__mir_arena_marks\n";
        $out .= "  %base = mul i64 %sp1, 2\n";
        $out .= "  %s0 = getelementptr i64, ptr %marks, i64 %base\n";
        $out .= "  %mchunki = load i64, ptr %s0\n";
        $out .= "  %b1 = add i64 %base, 1\n";
        $out .= "  %s1 = getelementptr i64, ptr %marks, i64 %b1\n";
        $out .= "  %mused = load i64, ptr %s1\n";
        $out .= "  %mchunk = inttoptr i64 %mchunki to ptr\n";
        $out .= "  store ptr %mchunk, ptr @__mir_arena_cur\n";
        $out .= "  %mnull = icmp eq ptr %mchunk, null\n";
        $out .= "  br i1 %mnull, label %sh, label %sn\n";
        $out .= "sn:\n";
        $out .= "  %up = getelementptr i8, ptr %mchunk, i64 16\n";
        $out .= "  store i64 %mused, ptr %up\n";
        $out .= "  %np0 = load ptr, ptr %mchunk\n";
        $out .= "  br label %zloop\n";
        $out .= "sh:\n";
        $out .= "  %h0 = load ptr, ptr @__mir_arena_head\n";
        $out .= "  br label %zloop\n";
        $out .= "zloop:\n";
        $out .= "  %c = phi ptr [%np0, %sn], [%h0, %sh], [%cn, %zbody]\n";
        $out .= "  %cnull = icmp eq ptr %c, null\n";
        $out .= "  br i1 %cnull, label %fin, label %zbody\n";
        $out .= "zbody:\n";
        $out .= "  %cu = getelementptr i8, ptr %c, i64 16\n";
        $out .= "  store i64 0, ptr %cu\n";
        $out .= "  %cn = load ptr, ptr %c\n";
        $out .= "  br label %zloop\n";
        $out .= "fin:\n";
        $out .= "  ret void\n";
        $out .= "}\n";
        if ($this->rt->needsArenaReset) {
            // Per-loop iteration reset: save the bump position before the
            // loop, restore it at the top of each iteration so confined
            // (Arena) temporaries built in the body are reclaimed instead
            // of accumulating in the whole-frame scope. Restore mirrors
            // `leave`'s tail (restore cur + used, zero spare chunks for
            // reuse) but takes the mark as args — no mark-stack push, so
            // `return` / `break` stay balanced (the frame `leave` cleans up).
            $out .= "define i64 @__mir_arena_used() {\n";
            $out .= "entry:\n";
            $out .= "  %cur = load ptr, ptr @__mir_arena_cur\n";
            $out .= "  %n = icmp eq ptr %cur, null\n";
            $out .= "  br i1 %n, label %z, label %l\n";
            $out .= "l:\n";
            $out .= "  %up = getelementptr i8, ptr %cur, i64 16\n";
            $out .= "  %u = load i64, ptr %up\n";
            $out .= "  ret i64 %u\n";
            $out .= "z:\n";
            $out .= "  ret i64 0\n";
            $out .= "}\n";
            $out .= "define void @__mir_arena_restore(ptr %mchunk, i64 %mused) {\n";
            $out .= "entry:\n";
            $out .= "  store ptr %mchunk, ptr @__mir_arena_cur\n";
            $out .= "  %mnull = icmp eq ptr %mchunk, null\n";
            $out .= "  br i1 %mnull, label %sh, label %sn\n";
            $out .= "sn:\n";
            $out .= "  %up = getelementptr i8, ptr %mchunk, i64 16\n";
            $out .= "  store i64 %mused, ptr %up\n";
            $out .= "  %np0 = load ptr, ptr %mchunk\n";
            $out .= "  br label %zloop\n";
            $out .= "sh:\n";
            $out .= "  %h0 = load ptr, ptr @__mir_arena_head\n";
            $out .= "  br label %zloop\n";
            $out .= "zloop:\n";
            $out .= "  %c = phi ptr [%np0, %sn], [%h0, %sh], [%cn, %zbody]\n";
            $out .= "  %cnull = icmp eq ptr %c, null\n";
            $out .= "  br i1 %cnull, label %fin2, label %zbody\n";
            $out .= "zbody:\n";
            $out .= "  %cu = getelementptr i8, ptr %c, i64 16\n";
            $out .= "  store i64 0, ptr %cu\n";
            $out .= "  %cn = load ptr, ptr %c\n";
            $out .= "  br label %zloop\n";
            $out .= "fin2:\n";
            $out .= "  ret void\n";
            $out .= "}\n";
        }
        return $out;
    }

    /**
     * `__mir_closure_retain` / `__mir_closure_release` — the lifetime of a
     * CAPTURING closure env `[MAGIC@-32, retain@-24, drop@-16, rc@-8][fn, caps…]`
     * ({@see \Compile\MemoryAbi::CLOSURE_TAG_MAGIC}).
     *
     * Both self-guard on the magic, so a `Closure`-typed value that is NOT one
     * of our capturing envs — a first-class callable, a rebound copy, anything
     * a future producer invents — is left completely alone: the old
     * never-freed behaviour, which leaks but cannot corrupt. The per-closure
     * `drop` releases exactly the captures the literal retained (it is
     * generated beside the closure body, from the same type switch), so the
     * two halves cannot drift.
     *
     * ⚠ The magic lives 32 bytes BEFORE the value pointer, so both helpers
     * read `p-32` on a pointer that might not have a header. That is the same
     * probe the Generator frame does at `-24`: the read stays inside the heap
     * (never a fresh page boundary in practice) and a false positive needs an
     * exact 64-bit magic to appear there by chance.
     */
    private function closureRcRuntime(): string
    {
        $magic = (string)\Compile\MemoryAbi::CLOSURE_TAG_MAGIC;
        $hdr   = (string)\Compile\MemoryAbi::STRING_HEADER_SIZE;
        $mOff  = (string)\Compile\MemoryAbi::STRING_HASH_OFFSET;
        $dOff  = (string)\Compile\MemoryAbi::CLOSURE_DROP_OFFSET;
        $out  = "define void @__mir_closure_retain(ptr %p) {\n";
        $out .= "entry:\n";
        $out .= "  %z = icmp eq ptr %p, null\n";
        $out .= "  br i1 %z, label %done, label %hdr\n";
        $out .= "hdr:\n";
        $out .= "  %mp = getelementptr inbounds i8, ptr %p, i64 " . $mOff . "\n";
        $out .= "  %m = load i64, ptr %mp\n";
        $out .= "  %ism = icmp eq i64 %m, " . $magic . "\n";
        $out .= "  br i1 %ism, label %rcb, label %done\n";
        $out .= "rcb:\n";
        $out .= "  %rp = getelementptr inbounds i8, ptr %p, i64 -8\n";
        $out .= "  %c = load i64, ptr %rp\n";
        $out .= "  %imm = icmp slt i64 %c, 0\n";
        $out .= "  br i1 %imm, label %done, label %inc\n";
        $out .= "inc:\n";
        $out .= "  %c1 = add i64 %c, 1\n";
        $out .= "  store i64 %c1, ptr %rp\n";
        $out .= "  br label %done\n";
        $out .= "done:\n";
        $out .= "  ret void\n";
        $out .= "}\n";
        $out .= "define void @__mir_closure_release(ptr %p) {\n";
        $out .= "entry:\n";
        $out .= "  %z = icmp eq ptr %p, null\n";
        $out .= "  br i1 %z, label %done, label %hdr\n";
        $out .= "hdr:\n";
        $out .= "  %mp = getelementptr inbounds i8, ptr %p, i64 " . $mOff . "\n";
        $out .= "  %m = load i64, ptr %mp\n";
        $out .= "  %ism = icmp eq i64 %m, " . $magic . "\n";
        $out .= "  br i1 %ism, label %rcb, label %done\n";
        $out .= "rcb:\n";
        $out .= "  %rp = getelementptr inbounds i8, ptr %p, i64 -8\n";
        $out .= "  %c = load i64, ptr %rp\n";
        $out .= "  %imm = icmp slt i64 %c, 0\n";
        $out .= "  br i1 %imm, label %done, label %dec\n";
        $out .= "dec:\n";
        $out .= "  %c1 = sub i64 %c, 1\n";
        $out .= "  store i64 %c1, ptr %rp\n";
        $out .= "  %zero = icmp sle i64 %c1, 0\n";
        $out .= "  br i1 %zero, label %free, label %done\n";
        $out .= "free:\n";
        // The captures die with the env: one generated fn per closure, its
        // address stamped by the literal. A no-capture env stores null here.
        $out .= "  %dp = getelementptr inbounds i8, ptr %p, i64 " . $dOff . "\n";
        $out .= "  %dv = load i64, ptr %dp\n";
        $out .= "  %hasd = icmp ne i64 %dv, 0\n";
        $out .= "  br i1 %hasd, label %dropit, label %dofree\n";
        $out .= "dropit:\n";
        $out .= "  %df = inttoptr i64 %dv to ptr\n";
        $out .= "  call void %df(ptr %p)\n";
        $out .= "  br label %dofree\n";
        $out .= "dofree:\n";
        // Clear the magic first: a stale pointer released twice then bails at
        // the guard instead of pushing a freed block onto a free list.
        $out .= "  store i64 0, ptr %mp\n";
        $out .= "  %base = getelementptr inbounds i8, ptr %p, i64 -" . $hdr . "\n";
        $out .= "  call void @free(ptr %base)\n";
        $out .= "  br label %done\n";
        $out .= "done:\n";
        $out .= "  ret void\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * Per-class object destructors + a class_id dispatch, used by
     * `__mir_rc_release` to recursively release an object's obj-typed
     * properties before freeing it. Struct classes and struct-typed
     * properties are skipped (no rc header). `__mir_drop_dispatch` is
     * always emitted (a no-op when no class needs a drop).
     */
    private function dropRuntime(): string
    {
        // Single pass, no intermediate array-valued maps (those get
        // AST-self-host-miscompiled). Build each class's drop body inline;
        // a drop releases obj-handle props (@__mir_rc_release) and string
        // props (@__mir_rc_release_str) before the object is freed.
        // Per-class descriptor `{ i64 class_id, ptr drop_fn }` lives at the
        // object header's slot 0 (a pointer, NOT the raw id). instanceof /
        // method dispatch / catch read class_id THROUGH it; release calls
        // drop_fn INDIRECTLY. linkonce_odr → one descriptor per class across
        // every separately-linked object, so a class only one .o knows still
        // drops correctly (no central id-switch to lose a case).
        return $this->dropRuntimeBody();
    }

    /**
     * rmeta + registry entries for things with no ClassDef: interfaces, traits,
     * and enums that declare no methods. Name + flags only.
     *
     * @param string[] $reflIds appended to, by reference — the registry array
     *                          must list every ctor it should run
     */
    private function reflNameOnlyEntries(array &$reflIds): string
    {
        $out = '';
        $seen = [];
        foreach ($this->enums as $ename => $ed) {
            if (isset($this->classes[$ename])) { continue; }   // already emitted with its ClassDef
            $out .= $this->reflNameOnly($ename, \Compile\MemoryAbi::RMETA_FLAG_ENUM, $reflIds, $seen);
        }
        foreach ($this->interfaceNames as $iname => $_) {
            $out .= $this->reflNameOnly($iname, \Compile\MemoryAbi::RMETA_FLAG_INTERFACE, $reflIds, $seen);
        }
        foreach ($this->traitNames as $tname => $_) {
            $out .= $this->reflNameOnly($tname, \Compile\MemoryAbi::RMETA_FLAG_TRAIT, $reflIds, $seen);
        }
        return $out;
    }

    /**
     * One name-keyed rmeta entry. `$seen` guards a name that is somehow in two
     * tables: one symbol may be defined once.
     *
     * @param string[] $reflIds
     * @param array<string,bool> $seen
     */
    private function reflNameOnly(string $name, int $flags, array &$reflIds, array &$seen): string
    {
        if (isset($seen[$name])) { return ''; }
        if (!$this->reflectWants($name)) { return ''; }
        $seen[$name] = true;
        $key = 'n_' . $this->mangle($name);
        $nameSym = '@.rmeta.name.' . $key;
        $out = $this->strGlobalDef($nameSym, $name);
        $out .= \Compile\Mir\RuntimeLibrary::rmetaGlobal(
            $key, 'ptr ' . $this->strSymBytes($nameSym), $flags, 0);
        $out .= \Compile\Mir\RuntimeLibrary::reflNodeAndCtor($key);
        $reflIds[] = $key;
        return $out;
    }

    /** Does this class need reflection metadata? {@see ReflectAnalysis}. */
    private function reflectWants(string $name): bool
    {
        if ($this->reflectAll) { return true; }
        return isset($this->reflectNames[$name]);
    }

    /**
     * The method table for one class: `[{ ptr name, i64 flags }]` in php's
     * getMethods() order (own → trait → inherited), which is the order
     * {@see \Compile\Mir\ClassDef::$methodMeta} already carries.
     *
     * Only USER-DECLARED methods: `$methodNames` also holds compiler-synthesised
     * entries (property hooks, the ctor synthesised for defaulted props) that
     * `$methodMeta` has no declaration for, and php reports none of them. That
     * asymmetry is deliberate — see the ClassDef docblock.
     *
     * @return string[] [globalDef, "i64 n, ptr sym"]
     */
    private function rmetaMethodTable(\Compile\Mir\ClassDef $cls, string $id): array
    {
        $rows = [];
        $defs = '';
        $i = 0;
        foreach ($cls->methodMeta as $mn => $mm) {
            $sym = '@.rmeta.m.' . $id . '.' . (string)$i;
            $defs .= $this->strGlobalDef($sym, $mn);
            $pp = $this->rmetaParamTable($mm, $id, $i);
            $defs .= $pp[0];
            $mdecl = $mm->declaringClass !== '' ? $mm->declaringClass : $cls->name;
            $ap = $this->attrTableFor($mm->attributes, $mdecl, 'm', $mn, '@.rmeta.mattr.' . $id . '.' . (string)$i);
            $defs .= $ap[0];
            // Always a real string (empty when untyped), never a null pointer:
            // hasReturnType()/getReturnType() compare it to "" BY VALUE, and a
            // null pointer read back is 0, which is `!== ""` — a false positive.
            $rsym = '@.rmeta.mret.' . $id . '.' . (string)$i;
            $defs .= $this->strGlobalDef($rsym, $mm->returnType);
            $retFld = $this->strSymBytes($rsym);
            $rows[] = \Compile\Mir\RuntimeLibrary::rmetaRow(
                $this->strSymBytes($sym),
                $this->memberFlags($mm->visibility, $mm->isStatic, $mm->isAbstract, $mm->isFinal, false)
                    | $this->metaIsDeprecated($mm),
                $this->methodTrampField($cls, $mm, $mn),
                $this->methodArity($mm),
                \count($mm->params),
                $pp[1],
                $ap[1], $ap[2], $retFld);
            $i = $i + 1;
        }
        $pair = \Compile\Mir\RuntimeLibrary::rmetaTable('@.rmeta.mt.' . $id, $rows);
        return [$defs . $pair[0], $pair[1]];
    }

    /**
     * A method's parameter table — one `{ ptr name, ptr type, i64 flags }` entry
     * per declared parameter, in order. `type` is the hint with a leading `?`
     * stripped (the nullability is a flag); empty ⇒ null + no HAS_TYPE bit.
     *
     * @return string[] [globalDef, tableSym|'null'] — both strings (the count is
     *   `count($mm->params)`, pushed by the caller as an int so every rmetaTable
     *   column keeps ONE repr across the method + property call sites).
     */
    private function rmetaParamTable(\Compile\Mir\MethodMeta $mm, string $id, int $mi): array
    {
        $names = [];
        $types = [];
        $flags = [];
        $defs = '';
        $pi = 0;
        foreach ($mm->params as $pm) {
            $nsym = '@.rmeta.pmn.' . $id . '.' . (string)$mi . '.' . (string)$pi;
            $defs .= $this->strGlobalDef($nsym, $pm->name);
            $names[] = $this->strSymBytes($nsym);
            $hint = $pm->typeHint;
            if ($hint !== '' && $hint[0] === '?') { $hint = \substr($hint, 1); }
            $f = 0;
            if ($pm->hasDefault)        { $f = $f | \Compile\MemoryAbi::RMETA_PARAM_HAS_DEFAULT; }
            if ($pm->allowsNull())      { $f = $f | \Compile\MemoryAbi::RMETA_PARAM_ALLOWS_NULL; }
            if ($pm->variadic)          { $f = $f | \Compile\MemoryAbi::RMETA_PARAM_VARIADIC; }
            if ($pm->promoted !== '')   { $f = $f | \Compile\MemoryAbi::RMETA_PARAM_PROMOTED; }
            if ($pm->typeHint !== '') {
                $f = $f | \Compile\MemoryAbi::RMETA_PARAM_HAS_TYPE;
                $tsym = '@.rmeta.pmt.' . $id . '.' . (string)$mi . '.' . (string)$pi;
                $defs .= $this->strGlobalDef($tsym, $hint);
                $types[] = $this->strSymBytes($tsym);
            } else {
                $types[] = 'null';
            }
            $flags[] = $f;
            $pi = $pi + 1;
        }
        $pair = \Compile\Mir\RuntimeLibrary::rmetaParamTable(
            '@.rmeta.parm.' . $id . '.' . (string)$mi, $names, $types, $flags);
        return [$defs . $pair[0], $pair[1]];
    }

    /**
     * The property table for one class. Every property php's getProperties()
     * reports — instance AND static — in {@see \Compile\Mir\ClassDef::$propertyMeta}
     * order (inherited first, then own), carrying real visibility / static /
     * readonly flags now that {@see \Compile\Mir\PropertyMeta} records them.
     *
     * A property row reuses the shared 48-byte row: `name@0`, `flags@8` (member
     * flags), tramp/arity/nparams zero, and `params@40` points at a
     * `{ ptr typeName, ptr getter, ptr setter }` extra struct (the same slot a
     * method row uses for its parameter table). The accessors are
     * {@see ReflectSynth}'s synthesized `__mc_pget_/pset_` functions, referenced
     * by symbol only when actually synthesized (an undefined DATA ref is a link
     * error — the same guard as {@see methodTrampField}). getValue/setValue call
     * them indirectly.
     *
     * @return string[] [globalDef, "i64 n, ptr sym"]
     */
    private function rmetaPropTable(\Compile\Mir\ClassDef $cls, string $id): array
    {
        $rows = [];
        $defs = '';
        $i = 0;
        foreach ($cls->propertyMeta as $pn => $pm) {
            $sym = '@.rmeta.p.' . $id . '.' . (string)$i;
            $defs .= $this->strGlobalDef($sym, $pn);
            // Type name — the hint AS WRITTEN (`?App\Foo`). getType() derives
            // nullability + the clean name from it in the prelude; a property has
            // no ALLOWS_NULL flag slot the way a parameter does. Always a real
            // string (empty when untyped) so hasType()/getType() compare it to ""
            // BY VALUE — a null pointer reads back 0, which is `!== ""`.
            $tsym = '@.rmeta.pty.' . $id . '.' . (string)$i;
            $defs .= $this->strGlobalDef($tsym, $pm->typeHint);
            $typeFld = 'ptr ' . $this->strSymBytes($tsym);
            $decl = $pm->declaringClass !== '' ? $pm->declaringClass : $cls->name;
            $getFld = $this->accessorField($decl, $pm->name, false);
            $setFld = $this->accessorField($decl, $pm->name, true);
            $extra = 'null';
            if ($typeFld !== 'null' || $getFld !== 'ptr null' || $setFld !== 'ptr null') {
                $exSym = '@.rmeta.px.' . $id . '.' . (string)$i;
                $tf = $typeFld === 'null' ? 'ptr null' : $typeFld;
                $defs .= $exSym . ' = linkonce_odr constant { ptr, ptr, ptr } { '
                       . $tf . ', ' . $getFld . ', ' . $setFld . " }\n";
                $extra = $exSym;
            }
            $ap = $this->attrTableFor($pm->attributes, $decl, 'p', $pm->name, '@.rmeta.pattr.' . $id . '.' . (string)$i);
            $defs .= $ap[0];
            $rows[] = \Compile\Mir\RuntimeLibrary::rmetaRow(
                $this->strSymBytes($sym),
                $this->memberFlags($pm->visibility, $pm->isStatic, false, false, $pm->isReadonly),
                'null', 0, 0, $extra,
                $ap[1], $ap[2]);
            $i = $i + 1;
        }
        $pair = \Compile\Mir\RuntimeLibrary::rmetaTable('@.rmeta.pt.' . $id, $rows);
        return [$defs . $pair[0], $pair[1]];
    }

    /**
     * A property accessor field: `ptr @manticore_…` when {@see ReflectSynth}
     * synthesized it (guarded by its presence in the signature table — a data
     * reference to an undefined symbol is a link error), else `ptr null`.
     */
    private function accessorField(string $declClass, string $prop, bool $setter): string
    {
        $sym = \Compile\Mir\Passes\ReflectSynth::propAccessor($declClass, $prop, $setter);
        if (!isset($this->sigs->paramTypes[$sym])) { return 'ptr null'; }
        return 'ptr @manticore_' . $this->mangle($sym);
    }

    /**
     * The attribute table for one member (Ф4): a `{name, args_factory,
     * new_factory}` row per attribute whose factory {@see ReflectSynth}
     * synthesized — the presence of the args factory in the signature table is
     * what tells a real attribute class from a compiler marker (`#[Struct]` …),
     * whose factory was never emitted. `$declClass` is the DECLARING class (an
     * inherited method's attrs key by its origin, where the factory was made).
     *
     * @param string[] $names attribute names, in declaration order (the index is
     *                        the factory site key, so a skipped one keeps its k)
     * @return array{0:string,1:int,2:string} [defs, nattrs, tableSym|'null']
     */
    private function attrTableFor(array $names, string $declClass, string $kind, string $member, string $sym): array
    {
        $rows = [];
        $defs = '';
        $target = \Compile\BuiltinAttributes::TARGET_CLASS;
        if ($kind === 'm') { $target = \Compile\BuiltinAttributes::TARGET_METHOD; }
        elseif ($kind === 'p') { $target = \Compile\BuiltinAttributes::TARGET_PROPERTY; }
        // A repeat is a property of the SITE, so it is counted over all names
        // here, not per surviving row.
        $counts = [];
        foreach ($names as $an) { $counts[$an] = ($counts[$an] ?? 0) + 1; }
        $k = -1;
        foreach ($names as $an) {
            $k = $k + 1;
            $argsFn = \Compile\Mir\Passes\ReflectSynth::attrFn($declClass, $kind, $member, $k, false);
            if (!isset($this->sigs->paramTypes[$argsFn])) { continue; }
            $newFn = \Compile\Mir\Passes\ReflectSynth::attrFn($declClass, $kind, $member, $k, true);
            $nameSym = $sym . '.n.' . (string)$k;
            $defs .= $this->strGlobalDef($nameSym, $an);
            $argsFld = 'ptr @manticore_' . $this->mangle($argsFn);
            $newFld = isset($this->sigs->paramTypes[$newFn])
                ? 'ptr @manticore_' . $this->mangle($newFn) : 'ptr null';
            // The newInstance() verdict, baked at lowering (see
            // Module::$attrSiteErrors) — php raises it only when the instance is
            // actually constructed.
            $err = $this->attrSiteErrors[$declClass . '|' . $kind . '|' . $member . '|' . (string)$k] ?? '';
            // Emitted as its OWN global beside the name, never interned: the
            // string pool has already been written out by the time rmeta is
            // built, so a fresh intern here dangles ("use of undefined value
            // @.str.N" out of clang). A VALID use still gets a real EMPTY
            // string rather than null — the prelude reads this field as a
            // `string` and tests `!== ""`, and a null pointer is not the empty
            // string object, so every attribute would have thrown.
            $errSym = $sym . '.e.' . (string)$k;
            $defs .= $this->strGlobalDef($errSym, $err);
            $errFld = 'ptr ' . $this->strSymBytes($errSym);
            $rows[] = \Compile\Mir\RuntimeLibrary::rmetaAttrRow(
                $this->strSymBytes($nameSym), $argsFld, $newFld,
                $target, ($counts[$an] ?? 1) > 1 ? 1 : 0, $errFld,
            );
        }
        $n = \count($rows);
        if ($n === 0) { return [$defs, 0, 'null']; }
        $defs .= $sym . ' = linkonce_odr constant [' . (string)$n . ' x '
               . \Compile\Mir\RuntimeLibrary::rmetaAttrType() . '] [' . \implode(', ', $rows) . "]\n";
        return [$defs, $n, $sym];
    }

    /**
     * The invoke-trampoline symbol field for a method row: `ptr @manticore_…`
     * when a uniform `(recv, args)` entry was synthesized for it, else `null`.
     *
     * Keyed by the DECLARING class (a `Dog` inheriting `Animal::feed` shares
     * `__mc_rtramp_Animal__feed` — the body's `$t->feed()` still dispatches
     * virtually to Dog's copy). Not invokable — and so `null` — for an abstract
     * or interface method, or one with a by-ref parameter (Ф2 does not forward
     * by-ref through the boxed args array; see the plan).
     */
    private function methodTrampField(\Compile\Mir\ClassDef $cls, \Compile\Mir\MethodMeta $mm, string $name): string
    {
        if ($mm->isAbstract) { return 'null'; }
        foreach ($mm->params as $p) {
            if ($p->byRef || $p->variadic) { return 'null'; }
        }
        $decl = $mm->declaringClass !== '' ? $mm->declaringClass : $cls->name;
        $tramp = \Compile\Mir\Passes\TrampolineSynth::symBase($decl, $name);
        // Only reference a trampoline that was actually synthesized — a data
        // reference to an undefined symbol is a LINK error (unlike a call, which
        // the stub generator fills). Synthesis is not gated per class, so any
        // reflectable class's methods resolve.
        if (!isset($this->sigs->paramTypes[$tramp])) { return 'null'; }
        return '@manticore_' . $this->mangle($tramp);
    }

    /**
     * The constructor-trampoline field for a class's rmeta: `ptr @manticore_…`
     * when a ctor trampoline was synthesized (a non-abstract user class), else
     * `null`. `newInstance()` reads it; `getConstructor()` instead consults the
     * method table for a user `__construct`, so the two disagree exactly when a
     * class has no explicit ctor (php: newInstance works, getConstructor null).
     */
    private function ctorTrampField(\Compile\Mir\ClassDef $cls): string
    {
        $tramp = \Compile\Mir\Passes\TrampolineSynth::symBase($cls->name, '__construct');
        if (!isset($this->sigs->paramTypes[$tramp])) { return 'ptr null'; }
        return 'ptr @manticore_' . $this->mangle($tramp);
    }

    /** Pack a method's arity word: `required | (total << 8) | (variadic << 16)`. */
    private function methodArity(\Compile\Mir\MethodMeta $mm): int
    {
        $total = \count($mm->params);
        $variadic = 0;
        foreach ($mm->params as $p) {
            if ($p->variadic) { $variadic = 1; }
        }
        return $mm->requiredParams() | ($total << 8) | ($variadic << 16);
    }

    /** Whether a member carries `#[\Deprecated]` (names only reach rmeta). */
    private function metaIsDeprecated(\Compile\Mir\MethodMeta $mm): int
    {
        foreach ($mm->attributes as $an) {
            if (\ltrim($an, '\\') === 'Deprecated') { return \Compile\MemoryAbi::RMETA_MEM_DEPRECATED; }
        }
        return 0;
    }

    /** Pack a member's flags word. Visibility is an enum in the low bits. */
    private function memberFlags(string $vis, bool $static, bool $abstract, bool $final, bool $readonly): int
    {
        $f = \Compile\MemoryAbi::RMETA_MEM_PUBLIC;
        if ($vis === 'protected') { $f = \Compile\MemoryAbi::RMETA_MEM_PROTECTED; }
        if ($vis === 'private')   { $f = \Compile\MemoryAbi::RMETA_MEM_PRIVATE; }
        if ($static)   { $f = $f | \Compile\MemoryAbi::RMETA_MEM_STATIC; }
        if ($abstract) { $f = $f | \Compile\MemoryAbi::RMETA_MEM_ABSTRACT; }
        if ($final)    { $f = $f | \Compile\MemoryAbi::RMETA_MEM_FINAL; }
        if ($readonly) { $f = $f | \Compile\MemoryAbi::RMETA_MEM_READONLY; }
        return $f;
    }

    /**
     * Ф5 — a metadata row `@__mc_fnmeta_<f>` per reflected free function, plus
     * a name→row registry (`@__mc_refl_fn_head` + `__mc_refl_fn_find`). A
     * function reuses the method ROW layout unchanged (flags 0, no attrs); its
     * invoke trampoline is {@see TrampolineSynth::fnTrampBase}, referenced only
     * when synthesized (variadic / by-ref functions have none → invoke throws).
     *
     * @param string[] $fnRegCtors appended with each registry ctor symbol, to
     *                             join the single @llvm.global_ctors array
     */
    private function fnMetaRuntime(array &$fnRegCtors): string
    {
        // head + find are emitted UNCONDITIONALLY (even with no reflected
        // functions): the `__mc_refl_fn_find` builtin a ReflectionFunction ctor
        // calls needs the symbol defined, and a dynamic-name program registers
        // none. `define` becomes linkonce_odr (linkonceRuntime) so it coalesces;
        // dead-strip drops it when unused.
        $out = '';
        foreach ($this->reflFnMeta as $fn => $mm) {
            $id = $this->mangle($fn);
            $nameSym = '@.fnmeta.name.' . $id;
            $out .= $this->strGlobalDef($nameSym, $fn);
            $pp = $this->rmetaParamTable($mm, $id, 0);
            $out .= $pp[0];
            $rsym = '@.fnmeta.ret.' . $id;
            $out .= $this->strGlobalDef($rsym, $mm->returnType);
            $trampSym = \Compile\Mir\Passes\TrampolineSynth::fnTrampBase($fn);
            $trampFld = isset($this->sigs->paramTypes[$trampSym])
                ? '@manticore_' . $this->mangle($trampSym) : 'null';
            $row = \Compile\Mir\RuntimeLibrary::rmetaRow(
                $this->strSymBytes($nameSym), $this->metaIsDeprecated($mm), $trampFld,
                $this->methodArity($mm), \count($mm->params), $pp[1],
                0, 'null', $this->strSymBytes($rsym));
            $out .= '@__mc_fnmeta_' . $id . ' = linkonce_odr constant ' . $row . "\n";
            $node = '@__mc_reflfn_node_' . $id;
            $out .= $node . ' = linkonce_odr global { ptr, ptr, i64 } { ptr @__mc_fnmeta_'
                  . $id . ", ptr null, i64 0 }\n";
            $out .= 'define void @__mc_reflfn_reg_' . $id . "() {\nentry:\n";
            $out .= '  %f = getelementptr i8, ptr ' . $node . ", i64 16\n";
            $out .= "  %fv = load i64, ptr %f\n";
            $out .= "  %done = icmp ne i64 %fv, 0\n";
            $out .= "  br i1 %done, label %skip, label %reg\n";
            $out .= "reg:\n  store i64 1, ptr %f\n";
            $out .= "  %h = load ptr, ptr @__mc_refl_fn_head\n";
            $out .= '  %np = getelementptr i8, ptr ' . $node . ", i64 8\n";
            $out .= "  store ptr %h, ptr %np\n";
            $out .= '  store ptr ' . $node . ", ptr @__mc_refl_fn_head\n";
            $out .= "  br label %skip\nskip:\n  ret void\n}\n";
            $fnRegCtors[] = '@__mc_reflfn_reg_' . $id;
        }
        $noff = (string)\Compile\MemoryAbi::RMETA_ROW_NAME_OFFSET;
        $out .= "@__mc_refl_fn_head = linkonce_odr global ptr null\n";
        $out .= "define i64 @__mc_refl_fn_find(ptr %name) {\nentry:\n";
        $out .= "  %p0 = load ptr, ptr @__mc_refl_fn_head\n  br label %loop\n";
        $out .= "loop:\n  %p = phi ptr [ %p0, %entry ], [ %next, %cont ]\n";
        $out .= "  %end = icmp eq ptr %p, null\n  br i1 %end, label %miss, label %body\n";
        $out .= "body:\n  %m = load ptr, ptr %p\n";
        $out .= '  %nmp = getelementptr i8, ptr %m, i64 ' . $noff . "\n";
        $out .= "  %nm = load ptr, ptr %nmp\n";
        $out .= "  %c = call i32 @strcmp(ptr %nm, ptr %name)\n";
        $out .= "  %eq = icmp eq i32 %c, 0\n  br i1 %eq, label %hit, label %cont\n";
        $out .= "hit:\n  %r = ptrtoint ptr %m to i64\n  ret i64 %r\n";
        $out .= "cont:\n  %nxp = getelementptr i8, ptr %p, i64 8\n";
        $out .= "  %next = load ptr, ptr %nxp\n  br label %loop\n";
        $out .= "miss:\n  ret i64 0\n}\n";
        return $out;
    }

    private function dropRuntimeBody(): string
    {
        $descs = '';
        $defs = '';
        /** @var int[] class ids to register in the name→rmeta registry */
        $reflIds = [];
        foreach ($this->classes as $cls) {
            if ($cls->isStruct) { continue; }
            $id = (string)$cls->classId;
            $body = '';
            $i = 0;
            // __destruct runs FIRST (PHP calls it before properties are
            // released), on the most-derived __destruct the class resolves.
            $dtorCls = $this->resolveMethodClass($cls->name, '__destruct');
            $hasDtor = $dtorCls !== '';
            if ($hasDtor) {
                $body .= '  %oi = ptrtoint ptr %o to i64' . "\n";
                $body .= '  %dr = call i64 @manticore_' . $this->mangle($dtorCls)
                       . '____destruct(i64 %oi)' . "\n";
            }
            foreach ($cls->propertyNames as $pn) {
                $pt = $cls->propertyTypes[$pn] ?? null;
                if ($pt === null) { continue; }
                // Release obj / string / vec / assoc props (flavor picks the
                // right element-walking helper). Flags were pre-set in
                // scanDropFlags so the helper is already emitted.
                $flavor = $this->discardReleaseFlavor($pt);
                if ($flavor === '') { continue; }
                $rel = $this->dropHelperFor($flavor);
                if ($rel === '') { continue; }
                $s = (string)$i;
                $off = (string)$cls->propertyOffset($pn);
                $body .= '  %g' . $s . ' = getelementptr i8, ptr %o, i64 ' . $off . "\n";
                $body .= '  %v' . $s . ' = load i64, ptr %g' . $s . "\n";
                $body .= '  %p' . $s . ' = inttoptr i64 %v' . $s . " to ptr\n";
                $body .= '  call void ' . $rel . '(ptr %p' . $s . ")\n";
                $i = $i + 1;
            }
            $dropFld = 'ptr null';
            if ($i > 0 || $hasDtor) {
                // Plain define → linkonceRuntime promotes it; coalesces by name.
                $defs .= 'define void @__mir_drop_' . $id . "(ptr %o) {\nentry:\n"
                    . $body . "  ret void\n}\n";
                $dropFld = 'ptr @__mir_drop_' . $id;
            }
            // Reflection metadata — only for classes reflection can actually
            // reach ({@see ReflectAnalysis}). A class outside the set keeps
            // `ptr null` in its descriptor and emits no block, no tables, no
            // name string and no startup ctor. The analysis fails OPEN, so an
            // unresolvable name simply puts every class back in.
            if (!$this->reflectWants($cls->name)) {
                $descs .= \Compile\Mir\RuntimeLibrary::descriptorGlobal((int)$id, $dropFld);
                continue;
            }
            // Every field is derived from the class itself, never from anything
            // module-local, so each module emitting this class emits identical
            // bytes — what makes the linkonce_odr coalescing sound (the epic's
            // ODR invariant).
            //
            // The name is a HEADERED, immortal (rc -1) string literal, so
            // __mc_refl_name hands the pointer straight back: no allocation, no
            // retain/release, and it cannot be freed under a caller.
            //
            // Its own symbol, keyed by CLASS ID — deliberately NOT the string
            // pool's `litStr()`. Pool symbols are `@.str.<n>` where n is a
            // module-local counter, so `@.str.7` is a different string in every
            // object: an rmeta referencing one would not be a pure function of
            // the class, breaking exactly the invariant that lets these
            // coalesce. (It would also depend on the pool still being open at
            // emit time.)
            $nameSym = '@.rmeta.name.' . $id;
            $descs .= $this->strGlobalDef($nameSym, $cls->display());
            $flags = 0;
            if ($cls->isFinal)    { $flags = $flags | \Compile\MemoryAbi::RMETA_FLAG_FINAL; }
            if ($cls->isAbstract) { $flags = $flags | \Compile\MemoryAbi::RMETA_FLAG_ABSTRACT; }
            // An enum with methods DOES get a ClassDef and lands here; one
            // without is registered separately below. php reports an enum as a
            // class (class_exists('E') is true), so the ENUM bit is additive,
            // not exclusive.
            if (isset($this->enums[$cls->name])) { $flags = $flags | \Compile\MemoryAbi::RMETA_FLAG_ENUM; }
            $parentId = 0;
            $parentNameFld = 'ptr null';
            if ($cls->parent !== '' && isset($this->classes[$cls->parent])) {
                $pcd = $this->classes[$cls->parent];
                $parentId = $pcd->classId;
                // The parent's name, so getParentClass() is find(parent_name) —
                // the registry is name-keyed, and this saves a second lookup
                // structure keyed by id. Its own symbol for the same reason the
                // class name has one.
                $pnSym = '@.rmeta.pname.' . $id;
                $descs .= $this->strGlobalDef($pnSym, $pcd->display());
                $parentNameFld = 'ptr ' . $this->strSymBytes($pnSym);
            }
            $mPair = $this->rmetaMethodTable($cls, $id);
            $descs .= $mPair[0];
            $mFlds = $mPair[1];
            $pPair = $this->rmetaPropTable($cls, $id);
            $descs .= $pPair[0];
            $pFlds = $pPair[1];
            $aPair = $this->attrTableFor($cls->attributes, $cls->name, 'c', '', '@.rmeta.cattr.' . $id);
            $descs .= $aPair[0];
            $attrsFlds = 'i64 ' . (string)$aPair[1] . ', '
                       . ($aPair[2] === 'null' ? 'ptr null' : 'ptr ' . $aPair[2]);
            $constsFnFld = 'ptr null';
            $constsFn = \Compile\Mir\Passes\ReflectSynth::constsFn($cls->name);
            if (isset($this->sigs->paramTypes[$constsFn])) {
                $constsFnFld = 'ptr @manticore_' . $this->mangle($constsFn);
            }
            $ifacesFnFld = 'ptr null';
            $ifacesFn = \Compile\Mir\Passes\ReflectSynth::ifacesFn($cls->name);
            if (isset($this->sigs->paramTypes[$ifacesFn])) {
                $ifacesFnFld = 'ptr @manticore_' . $this->mangle($ifacesFn);
            }
            $descs .= \Compile\Mir\RuntimeLibrary::rmetaGlobal(
                $id, 'ptr ' . $this->strSymBytes($nameSym), $flags, $parentId,
                $parentNameFld, $mFlds, $pFlds, $this->ctorTrampField($cls), $attrsFlds,
                $constsFnFld, $ifacesFnFld);
            $descs .= \Compile\Mir\RuntimeLibrary::descriptorGlobal(
                (int)$id, $dropFld, \Compile\Mir\RuntimeLibrary::rmetaField((int)$id));
            // Registry entry, so a NAME can find this class at runtime.
            $descs .= \Compile\Mir\RuntimeLibrary::reflNodeAndCtor($id);
            $reflIds[] = $id;
        }
        // Interfaces, traits, and enums WITHOUT methods never reach the loop
        // above: an interface has no ClassDef at all (Module::$interfaceNames is
        // names only), and an enum gets one only when it declares a method. They
        // still belong in the registry — it is the runtime CLASS TABLE, and
        // interface_exists($runtimeName) / get_declared_interfaces() have nothing
        // else to ask.
        //
        // They key by NAME, not class id, because they have no id: still a pure
        // function of the entry's own identity, so the linkonce_odr coalescing
        // stays sound. They carry no parent/method/property tables — nothing
        // reads those for a name-existence answer.
        $descs .= $this->reflNameOnlyEntries($reflIds);
        // Ф5 ReflectionFunction: a metadata row + registry entry per reflected
        // free function. Its startup ctors join the SAME @llvm.global_ctors array
        // (LLVM permits only one), so they are handed to reflRegistry below.
        $fnRegCtors = [];
        $descs .= $this->fnMetaRuntime($fnRegCtors);
        // The name→rmeta registry: list head, the global_ctors array that fills
        // it, and __mc_refl_find. Nothing is emitted for a module with no
        // classes, so a program that declares none carries no startup cost.
        $this->rt->needsStrcmp = true;
        $descs .= \Compile\Mir\RuntimeLibrary::reflRegistry($reflIds, $fnRegCtors);
        // Indirect dispatch: load the per-object descriptor (header slot 0),
        // then its drop_fn (descriptor offset 8), and call it. The body is
        // identical in every object → linkonce_odr coalesces it cleanly.
        $out = $descs . $defs;
        $out .= "define void @__mir_drop_dispatch(ptr %p) {\nentry:\n";
        $out .= "  %descI = load i64, ptr %p\n";
        $out .= "  %dz = icmp eq i64 %descI, 0\n";
        $out .= "  br i1 %dz, label %end, label %have\n";
        $out .= "have:\n";
        $out .= "  %desc = inttoptr i64 %descI to ptr\n";
        $out .= "  %dfp = getelementptr i8, ptr %desc, i64 8\n";
        $out .= "  %df = load ptr, ptr %dfp\n";
        $out .= "  %fz = icmp eq ptr %df, null\n";
        $out .= "  br i1 %fz, label %end, label %call\n";
        $out .= "call:\n";
        $out .= "  call void %df(ptr %p)\n";
        $out .= "  br label %end\n";
        $out .= "end:\n  ret void\n}\n";
        return $out;
    }

    /**
     * Bacon-Rajan synchronous cycle collector (opt-in, gated by needsCc).
     * The obj rc word packs `rc | color | buffered` (see MemoryAbi); rc is
     * the SIGNED low-56-bit field (trial deletion drives it negative).
     * `gc_collect_cycles()` runs MarkRoots → ScanRoots → CollectRoots over
     * the candidate buffer populated by `cc_add_root` (from obj release's
     * keep branch). Children = obj-typed (non-struct) properties only —
     * strings/vecs don't hold object refs that form collectable cycles.
     */
    private function ccRuntime(): string
    {
        $rcMask   = (string)\Compile\MemoryAbi::RC_MASK;
        $colorMask = (string)\Compile\MemoryAbi::COLOR_MASK;
        $colorClr = (string)\Compile\MemoryAbi::COLOR_CLEAR_MASK;
        $bufMask  = (string)\Compile\MemoryAbi::BUFFERED_MASK;
        $bufClr   = (string)\Compile\MemoryAbi::BUFFERED_CLEAR_MASK;
        $PURPLE = (string)\Compile\MemoryAbi::COLOR_PURPLE;
        $GRAY   = (string)\Compile\MemoryAbi::COLOR_GRAY;
        $WHITE  = (string)\Compile\MemoryAbi::COLOR_WHITE;
        $BLACK  = (string)\Compile\MemoryAbi::COLOR_BLACK;

        $out = "\n; ── Bacon-Rajan cycle collector ──\n";
        // linkonce_odr: cycle-collector root buffer is shared mutable state;
        // must coalesce across user.o + stdlib.o (same rationale as arena).
        $out .= "@__manticore_cc_roots = linkonce_odr global ptr null\n";
        $out .= "@__manticore_cc_count = linkonce_odr global i64 0\n";
        $out .= "@__manticore_cc_cap   = linkonce_odr global i64 0\n";
        $out .= "@__manticore_cc_freed = linkonce_odr global i64 0\n";
        $this->libcExtra['malloc'] = 'declare ptr @malloc(i64)';
        $this->libcExtra['realloc'] = 'declare ptr @realloc(ptr, i64)';
        $this->libcExtra['free'] = 'declare void @free(ptr)';

        // ── header-word accessors (rc word @ ptr+8) ──
        $out .= "define i64 @__cc_color(ptr %s) {\n";
        $out .= "  %wp = getelementptr i8, ptr %s, i64 8\n";
        $out .= "  %w = load i64, ptr %wp\n";
        $out .= "  %c = lshr i64 %w, 56\n";
        $out .= "  %m = and i64 %c, 127\n";
        $out .= "  ret i64 %m\n}\n";
        $out .= "define void @__cc_setcolor(ptr %s, i64 %c) {\n";
        $out .= "  %wp = getelementptr i8, ptr %s, i64 8\n";
        $out .= "  %w = load i64, ptr %wp\n";
        $out .= "  %clr = and i64 %w, " . $colorClr . "\n";
        $out .= "  %cs = shl i64 %c, 56\n";
        $out .= "  %csm = and i64 %cs, " . $colorMask . "\n";
        $out .= "  %nw = or i64 %clr, %csm\n";
        $out .= "  store i64 %nw, ptr %wp\n";
        $out .= "  ret void\n}\n";
        $out .= "define i64 @__cc_buffered(ptr %s) {\n";
        $out .= "  %wp = getelementptr i8, ptr %s, i64 8\n";
        $out .= "  %w = load i64, ptr %wp\n";
        $out .= "  %b = lshr i64 %w, 63\n";
        $out .= "  ret i64 %b\n}\n";
        $out .= "define void @__cc_setbuffered(ptr %s, i64 %b) {\n";
        $out .= "  %wp = getelementptr i8, ptr %s, i64 8\n";
        $out .= "  %w = load i64, ptr %wp\n";
        $out .= "  %clr = and i64 %w, " . $bufClr . "\n";
        $out .= "  %bs = shl i64 %b, 63\n";
        $out .= "  %nw = or i64 %clr, %bs\n";
        $out .= "  store i64 %nw, ptr %wp\n";
        $out .= "  ret void\n}\n";
        // signed rc value (sign-extend bit 55)
        $out .= "define i64 @__cc_rcval(ptr %s) {\n";
        $out .= "  %wp = getelementptr i8, ptr %s, i64 8\n";
        $out .= "  %w = load i64, ptr %wp\n";
        $out .= "  %sh = shl i64 %w, 8\n";
        $out .= "  %r = ashr i64 %sh, 8\n";
        $out .= "  ret i64 %r\n}\n";
        // add %d to signed rc, preserving color/buffered
        $out .= "define void @__cc_rcadd(ptr %s, i64 %d) {\n";
        $out .= "  %wp = getelementptr i8, ptr %s, i64 8\n";
        $out .= "  %w = load i64, ptr %wp\n";
        $out .= "  %hi = and i64 %w, " . $colorMask . "\n";
        $out .= "  %hib = and i64 %w, " . $bufMask . "\n";
        $out .= "  %hiboth = or i64 %hi, %hib\n";
        $out .= "  %sh = shl i64 %w, 8\n";
        $out .= "  %rc = ashr i64 %sh, 8\n";
        $out .= "  %rc2 = add i64 %rc, %d\n";
        $out .= "  %lo = and i64 %rc2, " . $rcMask . "\n";
        $out .= "  %nw = or i64 %hiboth, %lo\n";
        $out .= "  store i64 %nw, ptr %wp\n";
        $out .= "  ret void\n}\n";

        // ── candidate-buffer push (grow x2, min 8) ──
        $out .= "define void @__manticore_cc_add_root(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %col = call i64 @__cc_color(ptr %s)\n";
        $out .= "  %isp = icmp eq i64 %col, " . $PURPLE . "\n";
        $out .= "  br i1 %isp, label %done, label %mark\n";
        $out .= "mark:\n";
        $out .= "  call void @__cc_setcolor(ptr %s, i64 " . $PURPLE . ")\n";
        $out .= "  %b = call i64 @__cc_buffered(ptr %s)\n";
        $out .= "  %isb = icmp ne i64 %b, 0\n";
        $out .= "  br i1 %isb, label %done, label %push\n";
        $out .= "push:\n";
        $out .= "  call void @__cc_setbuffered(ptr %s, i64 1)\n";
        $out .= "  %cnt = load i64, ptr @__manticore_cc_count\n";
        $out .= "  %cap = load i64, ptr @__manticore_cc_cap\n";
        $out .= "  %full = icmp sge i64 %cnt, %cap\n";
        $out .= "  br i1 %full, label %grow, label %store\n";
        $out .= "grow:\n";
        $out .= "  %dbl = mul i64 %cap, 2\n";
        $out .= "  %small = icmp slt i64 %dbl, 8\n";
        $out .= "  %ncap = select i1 %small, i64 8, i64 %dbl\n";
        $out .= "  %bytes = mul i64 %ncap, 8\n";
        $out .= "  %old = load ptr, ptr @__manticore_cc_roots\n";
        $out .= "  %nb = call ptr @realloc(ptr %old, i64 %bytes)\n";
        $out .= "  store ptr %nb, ptr @__manticore_cc_roots\n";
        $out .= "  store i64 %ncap, ptr @__manticore_cc_cap\n";
        $out .= "  br label %store\n";
        $out .= "store:\n";
        $out .= "  %buf = load ptr, ptr @__manticore_cc_roots\n";
        $out .= "  %slot = getelementptr ptr, ptr %buf, i64 %cnt\n";
        $out .= "  store ptr %s, ptr %slot\n";
        $out .= "  %cnt1 = add i64 %cnt, 1\n";
        $out .= "  store i64 %cnt1, ptr @__manticore_cc_count\n";
        $out .= "  br label %done\n";
        $out .= "done:\n  ret void\n}\n";

        // ── per-child action dispatch ──
        $out .= "define void @__manticore_cc_child_apply(ptr %child, i64 %a) {\n";
        $out .= "entry:\n";
        $out .= "  switch i64 %a, label %done [ i64 0, label %mg i64 1, label %sc i64 2, label %sb i64 3, label %cw ]\n";
        $out .= "mg:\n";
        $out .= "  call void @__cc_rcadd(ptr %child, i64 -1)\n";
        $out .= "  call void @__manticore_cc_mark_gray(ptr %child)\n";
        $out .= "  br label %done\n";
        $out .= "sc:\n";
        $out .= "  call void @__manticore_cc_scan(ptr %child)\n";
        $out .= "  br label %done\n";
        $out .= "sb:\n";
        $out .= "  call void @__cc_rcadd(ptr %child, i64 1)\n";
        $out .= "  %col = call i64 @__cc_color(ptr %child)\n";
        $out .= "  %nb = icmp ne i64 %col, " . $BLACK . "\n";
        $out .= "  br i1 %nb, label %sbgo, label %done\n";
        $out .= "sbgo:\n";
        $out .= "  call void @__manticore_cc_scan_black(ptr %child)\n";
        $out .= "  br label %done\n";
        $out .= "cw:\n";
        $out .= "  call void @__manticore_cc_collect_white(ptr %child)\n";
        $out .= "  br label %done\n";
        $out .= "done:\n  ret void\n}\n";

        // ── per-class obj-child walker (mirrors drop, obj-only) ──
        $defs = '';
        $cases = '';
        $dispatch = '';
        foreach ($this->classes as $cls) {
            if ($cls->isStruct) { continue; }
            $body = '';
            $k = 0;
            foreach ($cls->propertyNames as $pn) {
                $pt = $cls->propertyTypes[$pn] ?? null;
                if ($pt === null) { continue; }
                if ($pt->kind !== Type::KIND_OBJ) { continue; }
                $pcls = $pt->class ?? '';
                if ($pcls !== '' && isset($this->classes[$pcls])
                    && $this->classes[$pcls]->isStruct) { continue; }
                $s = (string)$k;
                $off = (string)$cls->propertyOffset($pn);
                $body .= '  %g' . $s . ' = getelementptr i8, ptr %s, i64 ' . $off . "\n";
                $body .= '  %v' . $s . ' = load i64, ptr %g' . $s . "\n";
                $body .= '  %z' . $s . ' = icmp eq i64 %v' . $s . ", 0\n";
                $body .= '  br i1 %z' . $s . ', label %n' . $s . ', label %d' . $s . "\n";
                $body .= 'd' . $s . ":\n";
                $body .= '  %c' . $s . ' = inttoptr i64 %v' . $s . " to ptr\n";
                $body .= '  call void @__manticore_cc_child_apply(ptr %c' . $s . ', i64 %a)' . "\n";
                $body .= '  br label %n' . $s . "\n";
                $body .= 'n' . $s . ":\n";
                $k = $k + 1;
            }
            if ($k === 0) { continue; }
            $id = (string)$cls->classId;
            $defs .= 'define void @__cc_children_' . $id . "(ptr %s, i64 %a) {\nentry:\n"
                . $body . "  ret void\n}\n";
            $cases .= '    i64 ' . $id . ', label %k' . $id . "\n";
            $dispatch .= 'k' . $id . ":\n  call void @__cc_children_" . $id
                . "(ptr %s, i64 %a)\n  br label %end\n";
        }
        $out .= $defs;
        $out .= "define void @__manticore_cc_children(ptr %s, i64 %a) {\nentry:\n";
        if ($cases === '') {
            $out .= "  ret void\n}\n";
        } else {
            $out .= "  %cdesc = load i64, ptr %s\n";
        $out .= "  %cdescp = inttoptr i64 %cdesc to ptr\n";
        $out .= "  %cid = load i64, ptr %cdescp\n";
            $out .= "  switch i64 %cid, label %end [\n" . $cases . "  ]\n";
            $out .= $dispatch;
            $out .= "end:\n  ret void\n}\n";
        }

        // ── per-class NON-OBJ (string/vec/assoc) prop drop (white free path) ──
        // A collected cycle node's obj props are reclaimed by collect_white
        // recursion; its non-obj rc props (string / vec / assoc + their string
        // or obj elements) would otherwise leak — and the free path can't call
        // drop_dispatch (that re-releases the obj children being collected). So
        // drop the non-obj props here via the same flavor mapping dropRuntime
        // uses. Helpers exist (scanDropFlags pre-set the flags). Obj elements of
        // a vec[obj]/assoc[obj] are NOT cycle-walker children (the walker only
        // follows DIRECT obj props), so releasing them here is sound rc, not a
        // double-free of a collected node.
        $sDefs = '';
        $sCases = '';
        $sDispatch = '';
        foreach ($this->classes as $cls) {
            if ($cls->isStruct) { continue; }
            $body = '';
            $k = 0;
            foreach ($cls->propertyNames as $pn) {
                $pt = $cls->propertyTypes[$pn] ?? null;
                if ($pt === null) { continue; }
                $flavor = $this->discardReleaseFlavor($pt);
                if ($flavor === '' || $flavor === 'obj') { continue; }
                $rel = $this->dropHelperFor($flavor);
                if ($rel === '') { continue; }
                $s = (string)$k;
                $off = (string)$cls->propertyOffset($pn);
                $body .= '  %g' . $s . ' = getelementptr i8, ptr %s, i64 ' . $off . "\n";
                $body .= '  %v' . $s . ' = load i64, ptr %g' . $s . "\n";
                $body .= '  %p' . $s . ' = inttoptr i64 %v' . $s . " to ptr\n";
                $body .= '  call void ' . $rel . '(ptr %p' . $s . ")\n";
                $k = $k + 1;
            }
            if ($k === 0) { continue; }
            $id = (string)$cls->classId;
            $sDefs .= 'define void @__cc_dropscalar_' . $id . "(ptr %s) {\nentry:\n"
                . $body . "  ret void\n}\n";
            $sCases .= '    i64 ' . $id . ', label %s' . $id . "\n";
            $sDispatch .= 's' . $id . ":\n  call void @__cc_dropscalar_" . $id
                . "(ptr %s)\n  br label %end\n";
        }
        $out .= $sDefs;
        $out .= "define void @__manticore_cc_drop_strings(ptr %s) {\nentry:\n";
        if ($sCases === '') {
            $out .= "  ret void\n}\n";
        } else {
            $out .= "  %cdesc = load i64, ptr %s\n";
        $out .= "  %cdescp = inttoptr i64 %cdesc to ptr\n";
        $out .= "  %cid = load i64, ptr %cdescp\n";
            $out .= "  switch i64 %cid, label %end [\n" . $sCases . "  ]\n";
            $out .= $sDispatch;
            $out .= "end:\n  ret void\n}\n";
        }

        // ── walkers ──
        $out .= "define void @__manticore_cc_mark_gray(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %c = call i64 @__cc_color(ptr %s)\n";
        $out .= "  %isg = icmp eq i64 %c, " . $GRAY . "\n";
        $out .= "  br i1 %isg, label %done, label %go\n";
        $out .= "go:\n";
        $out .= "  call void @__cc_setcolor(ptr %s, i64 " . $GRAY . ")\n";
        $out .= "  call void @__manticore_cc_children(ptr %s, i64 0)\n";
        $out .= "  br label %done\n";
        $out .= "done:\n  ret void\n}\n";

        $out .= "define void @__manticore_cc_scan(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %c = call i64 @__cc_color(ptr %s)\n";
        $out .= "  %isg = icmp eq i64 %c, " . $GRAY . "\n";
        $out .= "  br i1 %isg, label %go, label %done\n";
        $out .= "go:\n";
        $out .= "  %rc = call i64 @__cc_rcval(ptr %s)\n";
        $out .= "  %pos = icmp sgt i64 %rc, 0\n";
        $out .= "  br i1 %pos, label %ext, label %white\n";
        $out .= "ext:\n";
        $out .= "  call void @__manticore_cc_scan_black(ptr %s)\n";
        $out .= "  br label %done\n";
        $out .= "white:\n";
        $out .= "  call void @__cc_setcolor(ptr %s, i64 " . $WHITE . ")\n";
        $out .= "  call void @__manticore_cc_children(ptr %s, i64 1)\n";
        $out .= "  br label %done\n";
        $out .= "done:\n  ret void\n}\n";

        $out .= "define void @__manticore_cc_scan_black(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  call void @__cc_setcolor(ptr %s, i64 " . $BLACK . ")\n";
        $out .= "  call void @__manticore_cc_children(ptr %s, i64 2)\n";
        $out .= "  ret void\n}\n";

        $out .= "define void @__manticore_cc_collect_white(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %c = call i64 @__cc_color(ptr %s)\n";
        $out .= "  %isw = icmp eq i64 %c, " . $WHITE . "\n";
        $out .= "  %b = call i64 @__cc_buffered(ptr %s)\n";
        $out .= "  %nb = icmp eq i64 %b, 0\n";
        $out .= "  %ok = and i1 %isw, %nb\n";
        $out .= "  br i1 %ok, label %go, label %done\n";
        $out .= "go:\n";
        $out .= "  call void @__cc_setcolor(ptr %s, i64 " . $BLACK . ")\n";
        $out .= "  call void @__manticore_cc_children(ptr %s, i64 3)\n";
        $out .= "  %fr = load i64, ptr @__manticore_cc_freed\n";
        $out .= "  %fr1 = add i64 %fr, 1\n";
        $out .= "  store i64 %fr1, ptr @__manticore_cc_freed\n";
        // Drop this node's string props (obj props handled by the recursion
        // above) so collected cycles don't leak their strings.
        $out .= "  call void @__manticore_cc_drop_strings(ptr %s)\n";
        $out .= "  %base = getelementptr i8, ptr %s, i64 -8\n";
        $out .= $this->poolFreeCall('%base');
        $out .= "  br label %done\n";
        $out .= "done:\n  ret void\n}\n";

        // ── gc_collect_cycles(): MarkRoots → ScanRoots → CollectRoots ──
        $out .= "define i64 @__manticore_cc_collect_cycles() {\n";
        $out .= "entry:\n";
        $out .= "  store i64 0, ptr @__manticore_cc_freed\n";
        $out .= "  %cnt = load i64, ptr @__manticore_cc_count\n";
        $out .= "  %buf = load ptr, ptr @__manticore_cc_roots\n";
        $out .= "  %ip = alloca i64\n";
        $out .= "  %wp = alloca i64\n";
        $out .= "  store i64 0, ptr %ip\n";
        $out .= "  store i64 0, ptr %wp\n";
        // MarkRoots (compact purple→front as gray; drop others)
        $out .= "  br label %mr\n";
        $out .= "mr:\n";
        $out .= "  %i = load i64, ptr %ip\n";
        $out .= "  %go = icmp slt i64 %i, %cnt\n";
        $out .= "  br i1 %go, label %mrb, label %mrd\n";
        $out .= "mrb:\n";
        $out .= "  %sp = getelementptr ptr, ptr %buf, i64 %i\n";
        $out .= "  %s = load ptr, ptr %sp\n";
        $out .= "  %col = call i64 @__cc_color(ptr %s)\n";
        $out .= "  %isp = icmp eq i64 %col, " . $PURPLE . "\n";
        $out .= "  br i1 %isp, label %mrkeep, label %mrdrop\n";
        $out .= "mrkeep:\n";
        $out .= "  call void @__manticore_cc_mark_gray(ptr %s)\n";
        $out .= "  %w = load i64, ptr %wp\n";
        $out .= "  %dst = getelementptr ptr, ptr %buf, i64 %w\n";
        $out .= "  store ptr %s, ptr %dst\n";
        $out .= "  %w1 = add i64 %w, 1\n";
        $out .= "  store i64 %w1, ptr %wp\n";
        $out .= "  br label %mrn\n";
        $out .= "mrdrop:\n";
        $out .= "  call void @__cc_setbuffered(ptr %s, i64 0)\n";
        $out .= "  %isbk = icmp eq i64 %col, " . $BLACK . "\n";
        $out .= "  %rcv = call i64 @__cc_rcval(ptr %s)\n";
        $out .= "  %rc0 = icmp eq i64 %rcv, 0\n";
        $out .= "  %deadf = and i1 %isbk, %rc0\n";
        $out .= "  br i1 %deadf, label %mrfree, label %mrn\n";
        $out .= "mrfree:\n";
        $out .= "  call void @__mir_drop_dispatch(ptr %s)\n";
        $out .= "  %fbase = getelementptr i8, ptr %s, i64 -8\n";
        $out .= $this->poolFreeCall('%fbase');
        $out .= "  br label %mrn\n";
        $out .= "mrn:\n";
        $out .= "  %inext = add i64 %i, 1\n";
        $out .= "  store i64 %inext, ptr %ip\n";
        $out .= "  br label %mr\n";
        $out .= "mrd:\n";
        $out .= "  %kept = load i64, ptr %wp\n";
        // ScanRoots
        $out .= "  store i64 0, ptr %ip\n";
        $out .= "  br label %sr\n";
        $out .= "sr:\n";
        $out .= "  %si = load i64, ptr %ip\n";
        $out .= "  %sgo = icmp slt i64 %si, %kept\n";
        $out .= "  br i1 %sgo, label %srb, label %srd\n";
        $out .= "srb:\n";
        $out .= "  %ssp = getelementptr ptr, ptr %buf, i64 %si\n";
        $out .= "  %ss = load ptr, ptr %ssp\n";
        $out .= "  call void @__manticore_cc_scan(ptr %ss)\n";
        $out .= "  %sin = add i64 %si, 1\n";
        $out .= "  store i64 %sin, ptr %ip\n";
        $out .= "  br label %sr\n";
        $out .= "srd:\n";
        // CollectRoots
        $out .= "  store i64 0, ptr %ip\n";
        $out .= "  br label %cr\n";
        $out .= "cr:\n";
        $out .= "  %ci = load i64, ptr %ip\n";
        $out .= "  %cgo = icmp slt i64 %ci, %kept\n";
        $out .= "  br i1 %cgo, label %crb, label %crd\n";
        $out .= "crb:\n";
        $out .= "  %csp = getelementptr ptr, ptr %buf, i64 %ci\n";
        $out .= "  %cs = load ptr, ptr %csp\n";
        $out .= "  call void @__cc_setbuffered(ptr %cs, i64 0)\n";
        $out .= "  call void @__manticore_cc_collect_white(ptr %cs)\n";
        $out .= "  %cin = add i64 %ci, 1\n";
        $out .= "  store i64 %cin, ptr %ip\n";
        $out .= "  br label %cr\n";
        $out .= "crd:\n";
        $out .= "  store i64 0, ptr @__manticore_cc_count\n";
        $out .= "  %freed = load i64, ptr @__manticore_cc_freed\n";
        $out .= "  ret i64 %freed\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * The output funnel: THE single place a byte destined for stdout passes
     * through, plus the `ob_start()` buffer state it consults.
     *
     * Before this existed, bytes reached fd 1 from twelve emit sites under two
     * incompatible disciplines — a string `echo` did `fflush(NULL)` + an
     * unbuffered `write(1, …)` (one syscall per echo), everything else did a
     * stdio `printf`, and the per-echo `fflush(NULL)` existed only to order the
     * two against each other. Both are gone: everything renders bytes first and
     * calls `@__mir_out_write`, which either hands them to stdout's ONE stdio
     * stream or appends them to the innermost open buffer.
     *
     * ⚠ Every body here must be renderable from nothing but this file — no
     * module-local information, no conditional reference to a PHP-level symbol.
     * These are `linkonce_odr` ({@see EmitLlvm::linkonceRuntime}) and the linker
     * keeps exactly one across the user `.o` and the prebuilt stdlib `.o`; two
     * bodies that disagree is the shape of the `__mir_rc_release` bug that freed
     * objects at refcount 5. The state globals carry the linkage explicitly, for
     * the same reason `@__mir_strpool0` does.
     */
    private function outBufRuntime(): string
    {
        $n     = (string)\Compile\MemoryAbi::OB_MAX_LEVELS;
        $arrP  = '[' . $n . ' x ptr]';
        $arrI  = '[' . $n . ' x i64]';
        $hashOff = (string)\Compile\MemoryAbi::STRING_HASH_OFFSET;

        $out  = "\n@__mir_ob_depth = linkonce_odr global i64 0\n";
        $out .= '@__mir_ob_stack = linkonce_odr global ' . $arrP . " zeroinitializer\n";
        // Per-level "a flush is draining me right now" flag. ob_flush() keeps its
        // level open while it hands the bytes downstream, so a handler that
        // echoes must NOT land back in the buffer it is handling — the target
        // walk below skips any level marked in use. That closes the one
        // reentrancy hazard structurally, with no guard the caller can forget.
        $out .= '@__mir_ob_inuse = linkonce_odr global ' . $arrI . " zeroinitializer\n";
        $out .= "@__mir_ob_implicit = linkonce_odr global i64 0\n";
        // Non-zero while an ob_start() handler is running. php DISCARDS whatever
        // a handler echoes — it does not forward it downstream, and it does not
        // fold it back into the buffer being handled (verified against the
        // oracle: a handler echoing "[side]" contributes nothing, at any nesting
        // depth). So this is a hard drop at the funnel's door, not a routing
        // decision like @__mir_ob_inuse below it.
        $out .= "@__mir_ob_incb = linkonce_odr global i64 0\n";

        // Innermost buffer accepting bytes, or 0 for "write to stdout".
        $out .= "\ndefine i64 @__mir_ob_target() {\n";
        $out .= "entry:\n";
        $out .= "  %d = load i64, ptr @__mir_ob_depth\n";
        $out .= "  %any = icmp sgt i64 %d, 0\n";
        $out .= "  br i1 %any, label %loop, label %none\n";
        $out .= "none:\n  ret i64 0\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [ %d, %entry ], [ %i1, %next ]\n";
        $out .= "  %idx = sub i64 %i, 1\n";
        $out .= '  %up = getelementptr inbounds ' . $arrI
              . ", ptr @__mir_ob_inuse, i64 0, i64 %idx\n";
        $out .= "  %u = load i64, ptr %up\n";
        $out .= "  %busy = icmp ne i64 %u, 0\n";
        $out .= "  br i1 %busy, label %next, label %found\n";
        $out .= "found:\n  ret i64 %i\n";
        $out .= "next:\n";
        $out .= "  %i1 = sub i64 %i, 1\n";
        $out .= "  %more = icmp sgt i64 %i1, 0\n";
        $out .= "  br i1 %more, label %loop, label %none\n";
        $out .= "}\n";

        // Append %n bytes at %d onto the level accumulator %s, returning the
        // (possibly new) accumulator. Same amortized in-place/grow shape as
        // {@see RuntimeLibrary::strAppend}, but it takes a (data, len) pair
        // rather than a headered chunk — so it is binary-safe by construction,
        // never reading a NUL as an end — and tolerates a null accumulator,
        // which is what an untouched level holds.
        $out .= "\ndefine ptr @__mir_ob_append(ptr %s, ptr %d, i64 %n) {\n";
        $out .= "entry:\n";
        $out .= "  %fresh = icmp eq ptr %s, null\n";
        $out .= "  br i1 %fresh, label %new, label %have\n";
        $out .= "new:\n";
        $out .= "  %fdbl = shl i64 %n, 1\n";
        $out .= "  %fcap = add i64 %fdbl, 1\n";
        $out .= "  %fbuf = call ptr @__mir_str_alloc(i64 %fcap)\n";
        $out .= "  call ptr @memcpy(ptr %fbuf, ptr %d, i64 %n)\n";
        $out .= "  %fnul = getelementptr inbounds i8, ptr %fbuf, i64 %n\n";
        $out .= "  store i8 0, ptr %fnul\n";
        $out .= "  call void @__mir_str_set_len(ptr %fbuf, i64 %n)\n";
        $out .= "  ret ptr %fbuf\n";
        $out .= "have:\n";
        // Sole ownership? rc@-8 == 1. A live ob_get_contents() borrow makes this
        // 2, which forces the copy path — that is what keeps the returned string
        // from mutating under the caller.
        $out .= "  %rcp = getelementptr i8, ptr %s, i64 -8\n";
        $out .= "  %rc = load i64, ptr %rcp\n";
        $out .= "  %sole = icmp eq i64 %rc, 1\n";
        $out .= "  br i1 %sole, label %chkcap, label %grow\n";
        $out .= "chkcap:\n";
        $out .= "  %la = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %need = add i64 %la, %n\n";
        $out .= "  %capp = getelementptr i8, ptr %s, i64 -24\n";
        $out .= "  %cap = load i64, ptr %capp\n";
        $out .= "  %fits = icmp slt i64 %need, %cap\n";  // need + NUL <= cap
        $out .= "  br i1 %fits, label %inplace, label %grow\n";
        $out .= "inplace:\n";
        $out .= "  %dst = getelementptr inbounds i8, ptr %s, i64 %la\n";
        $out .= "  call ptr @memcpy(ptr %dst, ptr %d, i64 %n)\n";
        $out .= "  %inul = getelementptr inbounds i8, ptr %s, i64 %need\n";
        $out .= "  store i8 0, ptr %inul\n";
        $out .= "  call void @__mir_str_set_len(ptr %s, i64 %need)\n";
        // Content changed under the same ptr → invalidate the cached hash.
        $out .= '  %hinv = getelementptr inbounds i8, ptr %s, i64 ' . $hashOff . "\n";
        $out .= "  store i64 0, ptr %hinv\n";
        $out .= "  ret ptr %s\n";
        $out .= "grow:\n";
        $out .= "  %la2 = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %sum = add i64 %la2, %n\n";
        $out .= "  %dbl = shl i64 %sum, 1\n";           // over-allocate ~2×
        $out .= "  %ncap = add i64 %dbl, 1\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %ncap)\n";
        $out .= "  call ptr @memcpy(ptr %buf, ptr %s, i64 %la2)\n";
        $out .= "  %dst2 = getelementptr inbounds i8, ptr %buf, i64 %la2\n";
        $out .= "  call ptr @memcpy(ptr %dst2, ptr %d, i64 %n)\n";
        $out .= "  %gnul = getelementptr inbounds i8, ptr %buf, i64 %sum\n";
        $out .= "  store i8 0, ptr %gnul\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %sum)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %s)\n";
        $out .= "  ret ptr %buf\n";
        $out .= "}\n";

        // THE choke point.
        $out .= "\ndefine void @__mir_out_write(ptr %d, i64 %n) {\n";
        $out .= "entry:\n";
        $out .= "  %empty = icmp sle i64 %n, 0\n";
        $out .= "  br i1 %empty, label %done, label %live\n";
        $out .= "live:\n";
        $out .= "  %cb = load i64, ptr @__mir_ob_incb\n";
        $out .= "  %incb = icmp ne i64 %cb, 0\n";
        $out .= "  br i1 %incb, label %done, label %go\n";
        $out .= "go:\n";
        $out .= "  %lvl = call i64 @__mir_ob_target()\n";
        $out .= "  %buffered = icmp sgt i64 %lvl, 0\n";
        $out .= "  br i1 %buffered, label %buf, label %std\n";
        $out .= "std:\n";
        // One stdio stream for every producer, so ordering between echo, printf
        // and a user fwrite(STDOUT, …) is the stream's problem, not ours.
        $out .= "  %f = call ptr @manticore_stdout()\n";
        $out .= "  call i64 @fwrite(ptr %d, i64 1, i64 %n, ptr %f)\n";
        $out .= "  %imp = load i64, ptr @__mir_ob_implicit\n";
        $out .= "  %doimp = icmp ne i64 %imp, 0\n";
        $out .= "  br i1 %doimp, label %impf, label %done\n";
        $out .= "impf:\n";
        $out .= "  call i32 @fflush(ptr %f)\n";
        $out .= "  br label %done\n";
        $out .= "buf:\n";
        $out .= "  %bidx = sub i64 %lvl, 1\n";
        $out .= '  %sp = getelementptr inbounds ' . $arrP
              . ", ptr @__mir_ob_stack, i64 0, i64 %bidx\n";
        $out .= "  %cur = load ptr, ptr %sp\n";
        $out .= "  %new = call ptr @__mir_ob_append(ptr %cur, ptr %d, i64 %n)\n";
        $out .= "  store ptr %new, ptr %sp\n";
        $out .= "  br label %done\n";
        $out .= "done:\n  ret void\n}\n";

        // A headered string, whole. Binary-safe: len@-16, never a NUL scan —
        // `printf("%s")` truncated a string holding an image or a protocol frame
        // at its first zero byte, and the tagged echo helper did exactly that.
        $out .= "\ndefine void @__mir_out_str(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %z = icmp eq ptr %s, null\n";
        $out .= "  br i1 %z, label %done, label %go\n";
        $out .= "go:\n";
        $out .= "  %n = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  call void @__mir_out_write(ptr %s, i64 %n)\n";
        $out .= "  br label %done\n";
        $out .= "done:\n  ret void\n}\n";

        // Decimal, via the same digit loop a concat uses: no printf format
        // parse, no allocation, and an entry-block alloca that costs nothing.
        $out .= "\ndefine void @__mir_out_int(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %buf = alloca [24 x i8], align 8\n";
        $out .= "  %n = call i64 @__mir_int_len(i64 %v)\n";
        $out .= "  call void @__mir_int_fmt(ptr %buf, i64 0, i64 %v)\n";
        $out .= "  call void @__mir_out_write(ptr %buf, i64 %n)\n";
        $out .= "  ret void\n}\n";

        // PHP `echo` of a bool prints "1" for true and nothing for false.
        $out .= "\ndefine void @__mir_out_bool(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %nz = icmp ne i64 %v, 0\n";
        $out .= "  br i1 %nz, label %yes, label %done\n";
        $out .= "yes:\n";
        $out .= "  call void @__mir_out_write(ptr @.str.one, i64 1)\n";
        $out .= "  br label %done\n";
        $out .= "done:\n  ret void\n}\n";

        // Via __mir_float_to_str, not "%.14g": PHP's echo scientific form is
        // "1.0E+20", which C's %g does not produce.
        $out .= "\ndefine void @__mir_out_float(double %d) {\n";
        $out .= "entry:\n";
        $out .= "  %s = call ptr @__mir_float_to_str(double %d)\n";
        $out .= "  call void @__mir_out_str(ptr %s)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %s)\n";
        $out .= "  ret void\n}\n";

        // fflush(stdout), NOT fflush(NULL): draining every stream would also
        // push a user's half-written file buffers, which `flush()` does not mean.
        $out .= "\ndefine void @__mir_out_flush() {\n";
        $out .= "entry:\n";
        $out .= "  %f = call ptr @manticore_stdout()\n";
        $out .= "  call i32 @fflush(ptr %f)\n";
        $out .= "  ret void\n}\n";

        return $out . $this->obApiRuntime();
    }

    /**
     * The `ob_*` accessors — the seam between the C-level byte stack above and
     * `prelude/ob.php`, which owns the handler callables (a callable cannot
     * cross the stdlib.o boundary, so the split is forced: bytes here, handlers
     * there, and nothing but an integer depth shared between them).
     *
     * Emitted with the funnel rather than on their own demand: they are a few
     * dozen instructions, and a separate gate would be one more way for a
     * module to carry the state without the accessors that maintain it.
     */
    private function obApiRuntime(): string
    {
        $n    = (string)\Compile\MemoryAbi::OB_MAX_LEVELS;
        $arrP = '[' . $n . ' x ptr]';
        $arrI = '[' . $n . ' x i64]';

        $out  = "\ndefine i64 @__mir_ob_level() {\n";
        $out .= "entry:\n";
        $out .= "  %d = load i64, ptr @__mir_ob_depth\n";
        $out .= "  ret i64 %d\n}\n";

        // 0 answers "refused" — ob_start() reports false rather than smashing
        // past the end of a linkonce_odr array whose extent is an ABI fact.
        $out .= "\ndefine i64 @__mir_ob_push() {\n";
        $out .= "entry:\n";
        $out .= "  %d = load i64, ptr @__mir_ob_depth\n";
        $out .= '  %full = icmp sge i64 %d, ' . $n . "\n";
        $out .= "  br i1 %full, label %no, label %ok\n";
        $out .= "no:\n  ret i64 0\n";
        $out .= "ok:\n";
        $out .= '  %sp = getelementptr inbounds ' . $arrP
              . ", ptr @__mir_ob_stack, i64 0, i64 %d\n";
        $out .= "  store ptr null, ptr %sp\n";
        $out .= '  %up = getelementptr inbounds ' . $arrI
              . ", ptr @__mir_ob_inuse, i64 0, i64 %d\n";
        $out .= "  store i64 0, ptr %up\n";
        $out .= "  %d1 = add i64 %d, 1\n";
        $out .= "  store i64 %d1, ptr @__mir_ob_depth\n";
        $out .= "  ret i64 %d1\n}\n";

        // Releases whatever the level still holds, so a pop without a take
        // cannot leak. ob_get_clean()'s take has already nulled the slot.
        $out .= "\ndefine i64 @__mir_ob_pop() {\n";
        $out .= "entry:\n";
        $out .= "  %d = load i64, ptr @__mir_ob_depth\n";
        $out .= "  %none = icmp sle i64 %d, 0\n";
        $out .= "  br i1 %none, label %no, label %ok\n";
        $out .= "no:\n  ret i64 0\n";
        $out .= "ok:\n";
        $out .= "  %idx = sub i64 %d, 1\n";
        $out .= '  %sp = getelementptr inbounds ' . $arrP
              . ", ptr @__mir_ob_stack, i64 0, i64 %idx\n";
        $out .= "  %cur = load ptr, ptr %sp\n";
        $out .= "  %held = icmp ne ptr %cur, null\n";
        $out .= "  br i1 %held, label %drop, label %fin\n";
        $out .= "drop:\n";
        $out .= "  call void @__mir_rc_release_str(ptr %cur)\n";
        $out .= "  br label %fin\n";
        $out .= "fin:\n";
        $out .= "  store ptr null, ptr %sp\n";
        $out .= '  %up = getelementptr inbounds ' . $arrI
              . ", ptr @__mir_ob_inuse, i64 0, i64 %idx\n";
        $out .= "  store i64 0, ptr %up\n";
        $out .= "  store i64 %idx, ptr @__mir_ob_depth\n";
        $out .= "  ret i64 %idx\n}\n";

        // Levels are 1-based on the PHP side, matching ob_get_level().
        $out .= "\ndefine i64 @__mir_ob_len(i64 %l) {\n";
        $out .= "entry:\n";
        $out .= "  %d = load i64, ptr @__mir_ob_depth\n";
        $out .= "  %lo = icmp slt i64 %l, 1\n";
        $out .= "  %hi = icmp sgt i64 %l, %d\n";
        $out .= "  %bad = or i1 %lo, %hi\n";
        $out .= "  br i1 %bad, label %no, label %ok\n";
        $out .= "no:\n  ret i64 0\n";
        $out .= "ok:\n";
        $out .= "  %idx = sub i64 %l, 1\n";
        $out .= '  %sp = getelementptr inbounds ' . $arrP
              . ", ptr @__mir_ob_stack, i64 0, i64 %idx\n";
        $out .= "  %cur = load ptr, ptr %sp\n";
        $out .= "  %z = icmp eq ptr %cur, null\n";
        $out .= "  br i1 %z, label %no, label %have\n";
        $out .= "have:\n";
        $out .= "  %n = call i64 @__mir_strlen(ptr %cur)\n";
        $out .= "  ret i64 %n\n}\n";

        // A BORROW: the caller gets a +1, which also forces the next append off
        // the in-place path (rc != 1), so the string it is holding cannot change
        // under it. That is ob_get_contents(), and it is why peek retains.
        $out .= "\ndefine ptr @__mir_ob_peek(i64 %l) {\n";
        $out .= "entry:\n";
        $out .= "  %d = load i64, ptr @__mir_ob_depth\n";
        $out .= "  %lo = icmp slt i64 %l, 1\n";
        $out .= "  %hi = icmp sgt i64 %l, %d\n";
        $out .= "  %bad = or i1 %lo, %hi\n";
        $out .= "  br i1 %bad, label %empty, label %ok\n";
        $out .= "empty:\n";
        $out .= '  ret ptr ' . $this->strSymBytes('@.cstr.empty') . "\n";
        $out .= "ok:\n";
        $out .= "  %idx = sub i64 %l, 1\n";
        $out .= '  %sp = getelementptr inbounds ' . $arrP
              . ", ptr @__mir_ob_stack, i64 0, i64 %idx\n";
        $out .= "  %cur = load ptr, ptr %sp\n";
        $out .= "  %z = icmp eq ptr %cur, null\n";
        $out .= "  br i1 %z, label %empty, label %have\n";
        $out .= "have:\n";
        $out .= "  call void @__mir_rc_retain_str(ptr %cur)\n";
        $out .= "  ret ptr %cur\n}\n";

        // A MOVE: the level is emptied and the caller owns the string outright,
        // so ob_get_clean() costs no copy at all.
        $out .= "\ndefine ptr @__mir_ob_take(i64 %l) {\n";
        $out .= "entry:\n";
        $out .= "  %d = load i64, ptr @__mir_ob_depth\n";
        $out .= "  %lo = icmp slt i64 %l, 1\n";
        $out .= "  %hi = icmp sgt i64 %l, %d\n";
        $out .= "  %bad = or i1 %lo, %hi\n";
        $out .= "  br i1 %bad, label %empty, label %ok\n";
        $out .= "empty:\n";
        $out .= '  ret ptr ' . $this->strSymBytes('@.cstr.empty') . "\n";
        $out .= "ok:\n";
        $out .= "  %idx = sub i64 %l, 1\n";
        $out .= '  %sp = getelementptr inbounds ' . $arrP
              . ", ptr @__mir_ob_stack, i64 0, i64 %idx\n";
        $out .= "  %cur = load ptr, ptr %sp\n";
        $out .= "  store ptr null, ptr %sp\n";
        $out .= "  %z = icmp eq ptr %cur, null\n";
        $out .= "  br i1 %z, label %empty, label %have\n";
        $out .= "have:\n";
        $out .= "  ret ptr %cur\n}\n";

        $out .= "\ndefine void @__mir_ob_clean(i64 %l) {\n";
        $out .= "entry:\n";
        $out .= "  %s = call ptr @__mir_ob_take(i64 %l)\n";
        // take() answers the immortal empty sentinel for an empty level; its rc
        // is -1, and release skips an immortal, so this needs no extra guard.
        $out .= "  call void @__mir_rc_release_str(ptr %s)\n";
        $out .= "  ret void\n}\n";

        // `_set_` in the name, not just `@__mir_ob_inuse`: LLVM has ONE global
        // namespace for values, so a function may not share a symbol with the
        // global it writes. Same for the implicit-flush flag below.
        $out .= "\ndefine void @__mir_ob_set_inuse(i64 %l, i64 %f) {\n";
        $out .= "entry:\n";
        $out .= "  %d = load i64, ptr @__mir_ob_depth\n";
        $out .= "  %lo = icmp slt i64 %l, 1\n";
        $out .= "  %hi = icmp sgt i64 %l, %d\n";
        $out .= "  %bad = or i1 %lo, %hi\n";
        $out .= "  br i1 %bad, label %done, label %ok\n";
        $out .= "ok:\n";
        $out .= "  %idx = sub i64 %l, 1\n";
        $out .= '  %up = getelementptr inbounds ' . $arrI
              . ", ptr @__mir_ob_inuse, i64 0, i64 %idx\n";
        $out .= "  store i64 %f, ptr %up\n";
        $out .= "  br label %done\n";
        $out .= "done:\n  ret void\n}\n";

        $out .= "\ndefine void @__mir_ob_set_implicit(i64 %f) {\n";
        $out .= "entry:\n";
        $out .= "  store i64 %f, ptr @__mir_ob_implicit\n";
        $out .= "  ret void\n}\n";

        $out .= "\ndefine void @__mir_ob_set_incb(i64 %f) {\n";
        $out .= "entry:\n";
        $out .= "  store i64 %f, ptr @__mir_ob_incb\n";
        $out .= "  ret void\n}\n";

        return $out;
    }

    private function concatRuntime(): string
    {
        $out = $this->concatImpl('@__mir_concat', '@__mir_str_alloc');
        if ($this->rt->needsArena) {
            $out .= $this->concatImpl('@__mir_concat_arena', '@__mir_str_alloc_arena');
        }
        if ($this->rt->needsStrAppend) {
            $out .= $this->lib->strAppend();
        }
        return $out;
    }

    private function concatImpl(string $name, string $alloc): string
    {
        $out  = "\ndefine ptr " . $name . "(ptr %ina, ptr %inb) {\n";
        $out .= "entry:\n";
        // A null `?string` operand (ptr 0) concatenates as "" (PHP), not a
        // strlen/memcpy of null → map 0 to the empty C-string.
        $out .= "  %anull = icmp eq ptr %ina, null\n";
        $out .= "  %a = select i1 %anull, ptr " . $this->strSymBytes('@.cstr.empty') . ", ptr %ina\n";
        $out .= "  %bnull = icmp eq ptr %inb, null\n";
        $out .= "  %b = select i1 %bnull, ptr " . $this->strSymBytes('@.cstr.empty') . ", ptr %inb\n";
        // Operand lengths via __mir_strlen: O(1) len@-16 for a headered string
        // (binary-safe — an embedded NUL keeps its true length), with a libc
        // strlen fallback for a not-yet-headered raw operand. The result buffer
        // gets a correct len from str_alloc; the +1 memcpy copies the trailing
        // NUL the headered string already carries at content[len].
        $out .= "  %la = call i64 @__mir_strlen(ptr %a)\n";
        $out .= "  %lb = call i64 @__mir_strlen(ptr %b)\n";
        $out .= "  %sum = add i64 %la, %lb\n";
        $out .= "  %sz = add i64 %sum, 1\n";
        $out .= "  %buf = call ptr " . $alloc . "(i64 %sz)\n";
        $out .= "  call ptr @memcpy(ptr %buf, ptr %a, i64 %la)\n";
        $out .= "  %dst2 = getelementptr inbounds i8, ptr %buf, i64 %la\n";
        $out .= "  %lb1 = add i64 %lb, 1\n";
        $out .= "  call ptr @memcpy(ptr %dst2, ptr %b, i64 %lb1)\n";
        $out .= "  ret ptr %buf\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * Hand-emitted string builtins (substr / str_repeat / strtolower /
     * strtoupper / strpos), each gated by its flag. Loops use phi
     * nodes (no allocas). The libc deps (strlen / memcpy / malloc /
     * strstr) are registered into $libcExtra at the call site.
     */
    private function stringBuiltinRuntime(): string
    {
        $out = '';
        if ($this->rt->needsSubstr) {
            // PHP/Zend substr() normalization, branchless (all `select`):
            //   n = strlen(s)
            //   start = start<0 ? max(0, n+start) : min(start, n)
            //   end = !haveLen ? n
            //       : len<0 ? max(start, n+len) : min(n, start+len)
            //   rlen = end - start  (always >= 0)
            $out .= "\ndefine ptr @__mir_substr(ptr %s, i64 %start, i64 %len, i64 %haveLen) {\n";
            $out .= "entry:\n";
            $out .= "  %n = call i64 @__mir_strlen(ptr %s)\n";
            $out .= "  %sneg = icmp slt i64 %start, 0\n";
            $out .= "  %splusn = add i64 %start, %n\n";
            $out .= "  %s0 = select i1 %sneg, i64 %splusn, i64 %start\n";
            $out .= "  %slo = icmp slt i64 %s0, 0\n";
            $out .= "  %s1 = select i1 %slo, i64 0, i64 %s0\n";
            $out .= "  %shi = icmp sgt i64 %s1, %n\n";
            $out .= "  %start2 = select i1 %shi, i64 %n, i64 %s1\n";
            $out .= "  %lneg = icmp slt i64 %len, 0\n";
            $out .= "  %endNeg = add i64 %n, %len\n";
            $out .= "  %enLo = icmp slt i64 %endNeg, %start2\n";
            $out .= "  %endNeg2 = select i1 %enLo, i64 %start2, i64 %endNeg\n";
            $out .= "  %endPos = add i64 %start2, %len\n";
            $out .= "  %epHi = icmp sgt i64 %endPos, %n\n";
            $out .= "  %endPos2 = select i1 %epHi, i64 %n, i64 %endPos\n";
            $out .= "  %endHave = select i1 %lneg, i64 %endNeg2, i64 %endPos2\n";
            $out .= "  %have = icmp ne i64 %haveLen, 0\n";
            $out .= "  %end = select i1 %have, i64 %endHave, i64 %n\n";
            $out .= "  %rlen = sub i64 %end, %start2\n";
            $out .= "  %src = getelementptr inbounds i8, ptr %s, i64 %start2\n";
            $out .= "  %sz = add i64 %rlen, 1\n";
            $out .= "  %buf = call ptr @__mir_str_alloc(i64 %sz)\n";
            $out .= "  call ptr @memcpy(ptr %buf, ptr %src, i64 %rlen)\n";
            $out .= "  %nul = getelementptr inbounds i8, ptr %buf, i64 %rlen\n";
            $out .= "  store i8 0, ptr %nul\n";
            $out .= "  ret ptr %buf\n";
            $out .= "}\n";
        }
        if ($this->rt->needsStrRepeat) {
            $out .= "\ndefine ptr @__mir_str_repeat(ptr %s, i64 %n) {\n";
            $out .= "entry:\n";
            $out .= "  %slen = call i64 @__mir_strlen(ptr %s)\n";
            $out .= "  %total = mul i64 %slen, %n\n";
            $out .= "  %sz = add i64 %total, 1\n";
            $out .= "  %buf = call ptr @__mir_str_alloc(i64 %sz)\n";
            $out .= "  br label %loop\n";
            $out .= "loop:\n";
            $out .= "  %i = phi i64 [0, %entry], [%i2, %body]\n";
            $out .= "  %done = icmp sge i64 %i, %n\n";
            $out .= "  br i1 %done, label %fin, label %body\n";
            $out .= "body:\n";
            $out .= "  %off = mul i64 %i, %slen\n";
            $out .= "  %dst = getelementptr inbounds i8, ptr %buf, i64 %off\n";
            $out .= "  call ptr @memcpy(ptr %dst, ptr %s, i64 %slen)\n";
            $out .= "  %i2 = add i64 %i, 1\n";
            $out .= "  br label %loop\n";
            $out .= "fin:\n";
            $out .= "  %np = getelementptr inbounds i8, ptr %buf, i64 %total\n";
            $out .= "  store i8 0, ptr %np\n";
            $out .= "  ret ptr %buf\n";
            $out .= "}\n";
        }
        if ($this->rt->needsIpow) { $out .= $this->lib->ipow(); }
        if ($this->rt->needsStrtolower) { $out .= $this->lib->caseConv('__mir_strtolower', 65, 90, 32); }
        if ($this->rt->needsStrtoupper) { $out .= $this->lib->caseConv('__mir_strtoupper', 97, 122, -32); }
        if ($this->rt->needsAddslashes) { $out .= $this->lib->addslashes(); }
        if ($this->rt->needsJsonEscape) { $out .= $this->lib->jsonEscape(); }
        if ($this->rt->needsRyu) { $out .= $this->lib->ryuMsp(); }
        if ($this->rt->needsJsonEnc) { $out .= $this->lib->jsonEnc(); }
        if ($this->rt->needsJsonDec) {
            // stdClass's layout is a constant of the compiler (it declares no
            // properties), so the emitted text is identical in every module and
            // the linkonce_odr coalescing stays sound. Absent — a library `.o`
            // carries no classes — the decoder keeps answering assoc arrays.
            $std = $this->classes['stdClass'] ?? null;
            $out .= $this->lib->jsonDec(
                $std === null ? 0 : $std->instanceSize(),
                $std === null ? 16 : $std->bagOffset(),
                $this->lib->descSlotValue($std),
            );
        }
        if ($this->rt->needsStrReplaceOne) { $out .= $this->lib->strReplaceOne(); }
        if ($this->rt->needsStrpos) {
            // Zend-faithful `int|false`: hit → NaN-boxed int(offset),
            // miss → NaN-boxed bool(false). Callers read the tag.
            // strpos($h, $n, $off): search from byte offset $off (PHP-faithful —
            // a negative offset counts from the end; an offset past the end
            // misses). The returned position is relative to the ORIGINAL $h.
            // Result is NaN-boxed: hit → int cell, miss → `false` cell.
            // BINARY-SAFE, like __mir_strcspn: both lengths come from the string
            // header (len@-16 via __mir_strlen), and the scan is memchr+memcmp.
            // strlen+strstr made a NUL-bearing argument unfindable — a haystack
            // truncated at its first NUL, and a needle CONTAINING one read as
            // empty. serialize's mangled property keys ("\0*\0prop") are exactly
            // that, and demangling them silently found nothing — and so is
            // `substr_count($cell, "\0")`, which symfony's Table uses to correct
            // multi-byte padding: it answered strlen($cell)+1, every cell's pad
            // width collapsed, and the table rendered unpadded.
            $out .= "\ndefine i64 @__mir_strpos(ptr %h, ptr %n, i64 %off) {\n";
            $out .= "entry:\n";
            $out .= "  %hlen = call i64 @__mir_strlen(ptr %h)\n";
            $out .= "  %nlen = call i64 @__mir_strlen(ptr %n)\n";
            $out .= "  %isneg = icmp slt i64 %off, 0\n";
            $out .= "  %fromend = add i64 %hlen, %off\n";
            $out .= "  %off1 = select i1 %isneg, i64 %fromend, i64 %off\n";
            $out .= "  %neg2 = icmp slt i64 %off1, 0\n";
            $out .= "  %off2 = select i1 %neg2, i64 0, i64 %off1\n";
            $out .= "  %toobig = icmp sgt i64 %off2, %hlen\n";
            $out .= "  br i1 %toobig, label %miss, label %chk0\n";
            // php 8: an EMPTY needle matches at the offset itself.
            $out .= "chk0:\n";
            $out .= "  %isempty = icmp eq i64 %nlen, 0\n";
            $out .= "  br i1 %isempty, label %hitoff, label %setup\n";
            $out .= "setup:\n";
            $out .= "  %last = sub i64 %hlen, %nlen\n";
            $out .= "  %fits = icmp sle i64 %off2, %last\n";
            $out .= "  %n0 = load i8, ptr %n\n";
            $out .= "  %n0i = zext i8 %n0 to i32\n";
            $out .= "  br i1 %fits, label %loop, label %miss\n";
            $out .= "loop:\n";
            $out .= "  %cur = phi i64 [ %off2, %setup ], [ %next, %again ]\n";
            $out .= "  %win = sub i64 %last, %cur\n";
            $out .= "  %win1 = add i64 %win, 1\n";
            $out .= "  %hc = getelementptr i8, ptr %h, i64 %cur\n";
            $out .= "  %p = call ptr @memchr(ptr %hc, i32 %n0i, i64 %win1)\n";
            $out .= "  %isnull = icmp eq ptr %p, null\n";
            $out .= "  br i1 %isnull, label %miss, label %cand\n";
            $out .= "cand:\n";
            $out .= "  %hi = ptrtoint ptr %h to i64\n";
            $out .= "  %pi = ptrtoint ptr %p to i64\n";
            $out .= "  %idx = sub i64 %pi, %hi\n";
            $out .= "  %c = call i32 @memcmp(ptr %p, ptr %n, i64 %nlen)\n";
            $out .= "  %eq = icmp eq i32 %c, 0\n";
            $out .= "  br i1 %eq, label %hit, label %again\n";
            $out .= "again:\n";
            $out .= "  %next = add i64 %idx, 1\n";
            $out .= "  %more = icmp sle i64 %next, %last\n";
            $out .= "  br i1 %more, label %loop, label %miss\n";
            $out .= "hit:\n";
            $out .= "  %dm = and i64 %idx, 281474976710655\n";
            $out .= "  %db = or i64 %dm, -4222124650659840\n";
            $out .= "  ret i64 %db\n";
            $out .= "hitoff:\n";
            $out .= "  %om = and i64 %off2, 281474976710655\n";
            $out .= "  %ob = or i64 %om, -4222124650659840\n";
            $out .= "  ret i64 %ob\n";
            $out .= "miss:\n";
            $out .= "  ret i64 -3940649673949184\n";
            $out .= "}\n";
        }
        if ($this->rt->needsStrcspn) {
            // strcspn($s, $chars, $off, $len): bytes from $off before the first
            // byte that IS in $chars (the whole span if none is). Binary-safe —
            // the scan is bounded by len@-16, never by a NUL, so it cannot
            // overshoot into the rest of the buffer (a `strstr`-style chain of
            // per-char searches can, and that is quadratic on a big document).
            //
            // A 256-bit membership bitmap (4 × i64 on the stack) makes the scan
            // O(span) for ANY charlist size, so the cost never depends on which
            // charlist byte happens to occur first. A single-byte charlist takes
            // the memchr fast path (SIMD in libc).
            $out .= "\ndefine i64 @__mir_strcspn(ptr %s, ptr %cs, i64 %off, i64 %len, i64 %haveLen) {\n";
            $out .= "entry:\n";
            $out .= "  %n = call i64 @__mir_strlen(ptr %s)\n";
            // PHP offset normalization: negative counts from the end, then clamp.
            $out .= "  %oneg = icmp slt i64 %off, 0\n";
            $out .= "  %ofe = add i64 %n, %off\n";
            $out .= "  %ofelt = icmp slt i64 %ofe, 0\n";
            $out .= "  %ofe0 = select i1 %ofelt, i64 0, i64 %ofe\n";
            $out .= "  %ogt = icmp sgt i64 %off, %n\n";
            $out .= "  %ocl = select i1 %ogt, i64 %n, i64 %off\n";
            $out .= "  %o = select i1 %oneg, i64 %ofe0, i64 %ocl\n";
            $out .= "  %avail = sub i64 %n, %o\n";
            // Optional length: negative stops that many bytes from the end.
            $out .= "  %lneg = icmp slt i64 %len, 0\n";
            $out .= "  %lfe = add i64 %avail, %len\n";
            $out .= "  %lfelt = icmp slt i64 %lfe, 0\n";
            $out .= "  %lfe0 = select i1 %lfelt, i64 0, i64 %lfe\n";
            $out .= "  %lgt = icmp sgt i64 %len, %avail\n";
            $out .= "  %lcl = select i1 %lgt, i64 %avail, i64 %len\n";
            $out .= "  %lsel = select i1 %lneg, i64 %lfe0, i64 %lcl\n";
            $out .= "  %hl = icmp ne i64 %haveLen, 0\n";
            $out .= "  %lim = select i1 %hl, i64 %lsel, i64 %avail\n";
            $out .= "  %p = getelementptr inbounds i8, ptr %s, i64 %o\n";
            $out .= "  %cl = call i64 @__mir_strlen(ptr %cs)\n";
            $out .= "  %empty = icmp eq i64 %cl, 0\n";
            $out .= "  br i1 %empty, label %none, label %chk1\n";
            $out .= "chk1:\n";
            $out .= "  %one = icmp eq i64 %cl, 1\n";
            $out .= "  br i1 %one, label %single, label %bitmap\n";
            $out .= "single:\n";
            $out .= "  %c0 = load i8, ptr %cs\n";
            $out .= "  %c0i = zext i8 %c0 to i32\n";
            $out .= "  %hit = call ptr @memchr(ptr %p, i32 %c0i, i64 %lim)\n";
            $out .= "  %miss = icmp eq ptr %hit, null\n";
            $out .= "  br i1 %miss, label %none, label %found\n";
            $out .= "found:\n";
            $out .= "  %hi = ptrtoint ptr %hit to i64\n";
            $out .= "  %pi = ptrtoint ptr %p to i64\n";
            $out .= "  %d = sub i64 %hi, %pi\n";
            $out .= "  ret i64 %d\n";
            // Build the bitmap: 4 i64 words, bit (b & 63) of word (b >> 6).
            $out .= "bitmap:\n";
            $out .= "  %bm = alloca [4 x i64]\n";
            // Four explicit stores, not a memset call — the bitmap is rebuilt on
            // every call and a libc memset of 32 bytes showed up in the profile.
            $out .= "  %bm0 = getelementptr inbounds i64, ptr %bm, i64 0\n";
            $out .= "  store i64 0, ptr %bm0\n";
            $out .= "  %bm1 = getelementptr inbounds i64, ptr %bm, i64 1\n";
            $out .= "  store i64 0, ptr %bm1\n";
            $out .= "  %bm2 = getelementptr inbounds i64, ptr %bm, i64 2\n";
            $out .= "  store i64 0, ptr %bm2\n";
            $out .= "  %bm3 = getelementptr inbounds i64, ptr %bm, i64 3\n";
            $out .= "  store i64 0, ptr %bm3\n";
            $out .= "  br label %bloop\n";
            $out .= "bloop:\n";
            $out .= "  %bi = phi i64 [ 0, %bitmap ], [ %bi1, %bbody ]\n";
            $out .= "  %bmore = icmp slt i64 %bi, %cl\n";
            $out .= "  br i1 %bmore, label %bbody, label %scan\n";
            $out .= "bbody:\n";
            $out .= "  %bcp = getelementptr inbounds i8, ptr %cs, i64 %bi\n";
            $out .= "  %bc = load i8, ptr %bcp\n";
            $out .= "  %bv = zext i8 %bc to i64\n";
            $out .= "  %bw = lshr i64 %bv, 6\n";
            $out .= "  %bb = and i64 %bv, 63\n";
            $out .= "  %bit = shl i64 1, %bb\n";
            $out .= "  %bwp = getelementptr inbounds i64, ptr %bm, i64 %bw\n";
            $out .= "  %bold = load i64, ptr %bwp\n";
            $out .= "  %bnew = or i64 %bold, %bit\n";
            $out .= "  store i64 %bnew, ptr %bwp\n";
            $out .= "  %bi1 = add i64 %bi, 1\n";
            $out .= "  br label %bloop\n";
            $out .= "scan:\n";
            $out .= "  %si = phi i64 [ 0, %bloop ], [ %si1, %snext ]\n";
            $out .= "  %smore = icmp slt i64 %si, %lim\n";
            $out .= "  br i1 %smore, label %sbody, label %none\n";
            $out .= "sbody:\n";
            $out .= "  %scp = getelementptr inbounds i8, ptr %p, i64 %si\n";
            $out .= "  %sc = load i8, ptr %scp\n";
            $out .= "  %sv = zext i8 %sc to i64\n";
            $out .= "  %sw = lshr i64 %sv, 6\n";
            $out .= "  %sb = and i64 %sv, 63\n";
            $out .= "  %sbit = shl i64 1, %sb\n";
            $out .= "  %swp = getelementptr inbounds i64, ptr %bm, i64 %sw\n";
            $out .= "  %sword = load i64, ptr %swp\n";
            $out .= "  %stest = and i64 %sword, %sbit\n";
            $out .= "  %sin = icmp ne i64 %stest, 0\n";
            $out .= "  br i1 %sin, label %sstop, label %snext\n";
            $out .= "snext:\n";
            $out .= "  %si1 = add i64 %si, 1\n";
            $out .= "  br label %scan\n";
            $out .= "sstop:\n";
            $out .= "  ret i64 %si\n";
            $out .= "none:\n";
            $out .= "  ret i64 %lim\n";
            $out .= "}\n";
        }
        if ($this->rt->needsElemUntag) {
            // `__mir_elem_untag(arr, v) -> i64` — the element `v`, read out of
            // `arr` at a site whose STATIC element type is pointer-shaped
            // (string / object), normalised to a raw pointer.
            //
            // The static type is a claim; the array's ELEMENT-HINT nibble is the
            // fact. They part company whenever a cell-element array reaches a
            // concrete-element consumer: symfony's `$this->headers[0]` is a
            // declared `string[]` whose rows arrive as boxed cells, and the raw
            // read handed the NaN tag to a string slot.
            //
            // This is the SOUND half of the withdrawn element decode: it only
            // ever turns a cell INTO its raw payload, which is what the static
            // type already promised the consumer. The unsound direction — a raw
            // element handed on as a tagged cell — is not done here, and an
            // UNKNOWN-typed result never reaches this helper.
            $out .= "\ndefine i64 @__mir_elem_untag(ptr %arr, i64 %v) {\n";
            $out .= "entry:\n";
            // An auto-vivifying base is genuinely null (`$x['a']['b'] = 1`), so
            // the flags load needs the guard.
            $out .= "  %isnull = icmp eq ptr %arr, null\n";
            $out .= "  br i1 %isnull, label %asis, label %chk\n";
            $out .= "asis:\n  ret i64 %v\n";
            $out .= "chk:\n";
            $out .= "  %fp = getelementptr inbounds i8, ptr %arr, i64 "
                  . (string)\Compile\MemoryAbi::ARRAY_FLAGS_OFFSET . "\n";
            $out .= "  %fl = load i64, ptr %fp\n";
            $out .= "  %hn = and i64 %fl, "
                  . (string)\Compile\MemoryAbi::ARRAY_ELEM_HINT_MASK . "\n";
            $out .= "  %isc = icmp eq i64 %hn, "
                  . (string)\Compile\MemoryAbi::ARRAY_ELEM_HINT_CELL . "\n";
            $out .= "  %pay = and i64 %v, 281474976710655\n";
            $out .= "  %r = select i1 %isc, i64 %pay, i64 %v\n";
            $out .= "  ret i64 %r\n";
            $out .= "}\n";
        }
        if ($this->rt->needsStrExplode) {
            // `__mir_str_explode(delim, subj, limit) -> ptr` — single-scan split
            // into a fresh vec[string]. Each segment is a POOLED `__mir_str_alloc`
            // (size-class free-list, sets len=n-1 & rc=1) + memcpy — NOT raw-malloc
            // str_new: the pool reuse is what makes N-segment splitting cheap.
            // limit>1 keeps splitting; the tail block appends the remainder. An
            // empty delim yields [subj] (matches the prelude explode). Replaces the
            // PHP-level prelude explode's 8×(strpos-cell + substr-malloc + append)
            // per call with one C loop.
            //
            // A NEGATIVE limit is a different operation: php returns every
            // component EXCEPT the last -limit, and those are whole components,
            // not the "rest of the string" the positive form's final element
            // carries. `sgt %lim, 1` was false for one, so it fell straight to
            // the tail and answered [subj] — symfony's
            // `explode(':', $name, -1)` in Application::extractNamespace put
            // every command in a namespace of its own, so `list` printed a
            // header per command instead of grouping under `config` / `list`.
            // Handle it with a counting pre-pass: the segment total is
            // occurrences+1, so `keep = total - (-limit)` is known before the
            // split, and the same loop then emits exactly that many components
            // with the tail append suppressed.
            $out .= "\ndefine ptr @__mir_str_explode(ptr %delim, ptr %subj, i64 %limit) {\n";
            $out .= "entry:\n";
            $out .= "  %dlen = call i64 @__mir_strlen(ptr %delim)\n";
            $out .= "  %slen = call i64 @__mir_strlen(ptr %subj)\n";
            $out .= "  %arr0 = call ptr @__mir_array_alloc(i64 0)\n";
            $out .= "  %arrp = alloca ptr\n";
            $out .= "  store ptr %arr0, ptr %arrp\n";
            $out .= "  %posp = alloca i64\n";
            $out .= "  store i64 0, ptr %posp\n";
            $out .= "  %limp = alloca i64\n";
            $out .= "  %cntp = alloca i64\n";
            $out .= "  store i64 0, ptr %cntp\n";
            $out .= "  %isneg = icmp slt i64 %limit, 0\n";
            $out .= "  %de0 = icmp eq i64 %dlen, 0\n";
            // An empty delimiter answers [subj] whatever the limit says (php
            // raises there, so any answer is outside the oracle) — go straight
            // to the tail append, never the negative early-out.
            $out .= "  br i1 %de0, label %dotail, label %setup\n";
            $out .= "setup:\n";
            $out .= "  br i1 %isneg, label %cloop, label %setpos\n";
            // php treats limit 0 as 1.
            $out .= "setpos:\n";
            $out .= "  %z0 = icmp eq i64 %limit, 0\n";
            $out .= "  %l1 = select i1 %z0, i64 1, i64 %limit\n";
            $out .= "  store i64 %l1, ptr %limp\n";
            $out .= "  br label %loop\n";
            // Counting pre-pass — delimiter occurrences, no allocation.
            $out .= "cloop:\n";
            $out .= "  %cpos = load i64, ptr %posp\n";
            $out .= "  %chs = getelementptr inbounds i8, ptr %subj, i64 %cpos\n";
            $out .= "  %chit = call ptr @strstr(ptr %chs, ptr %delim)\n";
            $out .= "  %cmiss = icmp eq ptr %chit, null\n";
            $out .= "  br i1 %cmiss, label %cdone, label %cnext\n";
            $out .= "cnext:\n";
            $out .= "  %chiti = ptrtoint ptr %chit to i64\n";
            $out .= "  %csubji = ptrtoint ptr %subj to i64\n";
            $out .= "  %coff = sub i64 %chiti, %csubji\n";
            $out .= "  %cnewpos = add i64 %coff, %dlen\n";
            $out .= "  store i64 %cnewpos, ptr %posp\n";
            $out .= "  %cn = load i64, ptr %cntp\n";
            $out .= "  %cn1 = add i64 %cn, 1\n";
            $out .= "  store i64 %cn1, ptr %cntp\n";
            $out .= "  br label %cloop\n";
            $out .= "cdone:\n";
            $out .= "  %occ = load i64, ptr %cntp\n";
            $out .= "  %segtotal = add i64 %occ, 1\n";
            $out .= "  %drop = sub i64 0, %limit\n";
            $out .= "  %keep = sub i64 %segtotal, %drop\n";
            $out .= "  %kle = icmp sle i64 %keep, 0\n";
            $out .= "  br i1 %kle, label %kempty, label %kok\n";
            $out .= "kempty:\n";
            $out .= "  %earr = load ptr, ptr %arrp\n";
            $out .= "  ret ptr %earr\n";
            // keep+1 makes the shared loop stop after `keep` components; the
            // tail append is then skipped, which is what drops the remainder.
            $out .= "kok:\n";
            $out .= "  %kp1 = add i64 %keep, 1\n";
            $out .= "  store i64 %kp1, ptr %limp\n";
            $out .= "  store i64 0, ptr %posp\n";
            $out .= "  br label %loop\n";
            $out .= "loop:\n";
            $out .= "  %lim = load i64, ptr %limp\n";
            $out .= "  %limok = icmp sgt i64 %lim, 1\n";
            $out .= "  br i1 %limok, label %search, label %tail\n";
            $out .= "search:\n";
            $out .= "  %pos = load i64, ptr %posp\n";
            $out .= "  %hstart = getelementptr inbounds i8, ptr %subj, i64 %pos\n";
            $out .= "  %hit = call ptr @strstr(ptr %hstart, ptr %delim)\n";
            $out .= "  %miss = icmp eq ptr %hit, null\n";
            $out .= "  br i1 %miss, label %tail, label %emit\n";
            $out .= "emit:\n";
            $out .= "  %hstarti = ptrtoint ptr %hstart to i64\n";
            $out .= "  %hiti = ptrtoint ptr %hit to i64\n";
            $out .= "  %seglen = sub i64 %hiti, %hstarti\n";
            $out .= "  %segsz = add i64 %seglen, 1\n";
            $out .= "  %seg = call ptr @__mir_str_alloc(i64 %segsz)\n";
            $out .= "  call ptr @memcpy(ptr %seg, ptr %hstart, i64 %seglen)\n";
            $out .= "  %segnul = getelementptr inbounds i8, ptr %seg, i64 %seglen\n";
            $out .= "  store i8 0, ptr %segnul\n";
            $out .= "  %segi = ptrtoint ptr %seg to i64\n";
            $out .= "  %arrc = load ptr, ptr %arrp\n";
            $out .= "  %arrn = call ptr @__mir_array_append(ptr %arrc, i64 %segi)\n";
            $out .= "  store ptr %arrn, ptr %arrp\n";
            $out .= "  %subji = ptrtoint ptr %subj to i64\n";
            $out .= "  %hitoff = sub i64 %hiti, %subji\n";
            $out .= "  %newpos = add i64 %hitoff, %dlen\n";
            $out .= "  store i64 %newpos, ptr %posp\n";
            $out .= "  %lim2 = load i64, ptr %limp\n";
            $out .= "  %lim3 = sub i64 %lim2, 1\n";
            $out .= "  store i64 %lim3, ptr %limp\n";
            $out .= "  br label %loop\n";
            $out .= "tail:\n";
            $out .= "  br i1 %isneg, label %notail, label %dotail\n";
            $out .= "notail:\n";
            $out .= "  %narr = load ptr, ptr %arrp\n";
            $out .= $this->explodeHintStampIr('%narr', 'n');
            $out .= "  ret ptr %narr\n";
            $out .= "dotail:\n";
            $out .= "  %fpos = load i64, ptr %posp\n";
            $out .= "  %tstart = getelementptr inbounds i8, ptr %subj, i64 %fpos\n";
            $out .= "  %tlen = sub i64 %slen, %fpos\n";
            $out .= "  %tsegsz = add i64 %tlen, 1\n";
            $out .= "  %tseg = call ptr @__mir_str_alloc(i64 %tsegsz)\n";
            $out .= "  call ptr @memcpy(ptr %tseg, ptr %tstart, i64 %tlen)\n";
            $out .= "  %tsegnul = getelementptr inbounds i8, ptr %tseg, i64 %tlen\n";
            $out .= "  store i8 0, ptr %tsegnul\n";
            $out .= "  %tsegi = ptrtoint ptr %tseg to i64\n";
            $out .= "  %arrc2 = load ptr, ptr %arrp\n";
            $out .= "  %arrn2 = call ptr @__mir_array_append(ptr %arrc2, i64 %tsegi)\n";
            $out .= $this->explodeHintStampIr('%arrn2', 't');
            $out .= "  ret ptr %arrn2\n";
            $out .= "}\n";
        }
        return $out;
    }

    /**
     * Stamp `ARRAY_ELEM_HINT_STR` on an explode result — its segments are raw
     * string pointers, and a reader that meets the array through an erased
     * carrier has no other way to know that ({@see
     * EmitLlvmArrays::elementHintCodeForType}). Named registers, so each return
     * path needs its own `$sfx`.
     */
    private function explodeHintStampIr(string $arr, string $sfx): string
    {
        $out  = "  %hf$sfx = getelementptr inbounds i8, ptr $arr, i64 "
              . (string)\Compile\MemoryAbi::ARRAY_FLAGS_OFFSET . "\n";
        $out .= "  %hv$sfx = load i64, ptr %hf$sfx\n";
        $out .= "  %hc$sfx = and i64 %hv$sfx, "
              . (string)(~\Compile\MemoryAbi::ARRAY_ELEM_HINT_MASK) . "\n";
        $out .= "  %hs$sfx = or i64 %hc$sfx, "
              . (string)\Compile\MemoryAbi::ARRAY_ELEM_HINT_STR . "\n";
        $out .= "  store i64 %hs$sfx, ptr %hf$sfx\n";
        return $out;
    }

}
