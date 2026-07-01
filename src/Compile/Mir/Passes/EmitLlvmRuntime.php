<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Type;

trait EmitLlvmRuntime
{
    private function allocRuntime(): string
    {
        $out  = "\ndefine ptr @__mir_alloc(i64 %n) {\n";
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
        $out .= "  %t = add i64 %n, 8\n";
        $out .= "  %base = call ptr @malloc(i64 %t)\n";
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
        $out .= "  %t = add i64 %n, 8\n";
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
        $out .= "@__mir_strpool0 = internal global ptr null\n";
        $out .= "@__mir_strpool1 = internal global ptr null\n";
        $out .= "define ptr @__mir_str_alloc(i64 %n) {\n";
        $out .= "entry:\n";
        $out .= $this->profBump(0);
        $out .= "  %le40 = icmp ule i64 %n, 40\n";
        $out .= "  br i1 %le40, label %c0, label %chk1\n";
        $out .= "chk1:\n";
        $out .= "  %le104 = icmp ule i64 %n, 104\n";
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
        $out .= "  %a0 = call ptr @malloc(i64 64)\n";
        $out .= "  br label %i0\n";
        $out .= "i0:\n";
        $out .= "  %b0 = phi ptr [ %h0, %pop0 ], [ %a0, %m0 ]\n";
        $out .= "  store i64 40, ptr %b0\n";                             // cap@0 = 40
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
        $out .= "  %a1 = call ptr @malloc(i64 128)\n";
        $out .= "  br label %i1\n";
        $out .= "i1:\n";
        $out .= "  %b1 = phi ptr [ %h1, %pop1 ], [ %a1, %m1 ]\n";
        $out .= "  store i64 104, ptr %b1\n";                            // cap@0 = 104
        $out .= "  br label %fin\n";
        // large: exact malloc, cap = n
        $out .= "big:\n";
        $out .= "  %tb = add i64 %n, 24\n";
        $out .= "  %ab = call ptr @malloc(i64 %tb)\n";
        $out .= "  store i64 %n, ptr %ab\n";                             // cap@0 = n
        $out .= "  br label %fin\n";
        $out .= "fin:\n";
        $out .= "  %p = phi ptr [ %b0, %i0 ], [ %b1, %i1 ], [ %ab, %big ]\n";
        $out .= "  %lenp = getelementptr inbounds i8, ptr %p, i64 8\n";
        $out .= "  %len0 = sub i64 %n, 1\n";
        $out .= "  store i64 %len0, ptr %lenp\n";                        // len@8 = n-1
        $out .= "  %rcp = getelementptr inbounds i8, ptr %p, i64 16\n";
        $out .= "  store i64 1, ptr %rcp\n";                             // rc@16
        $out .= "  %d = getelementptr inbounds i8, ptr %p, i64 24\n";
        $out .= "  ret ptr %d\n";
        $out .= "}\n";
        // Reclaim a freed string base: recycle into its size-class bin (cap
        // 40/104 — the only pooled caps), else return to libc. Same safety as
        // free (only ever called at rc==0, i.e. no live references).
        $out .= "define void @__mir_str_reclaim(ptr %sbase) {\n";
        $out .= "entry:\n";
        $out .= "  %cap = load i64, ptr %sbase\n";
        $out .= "  %is0 = icmp eq i64 %cap, 40\n";
        $out .= "  br i1 %is0, label %p0, label %k1\n";
        $out .= "p0:\n";
        $out .= "  %o0 = load ptr, ptr @__mir_strpool0\n";
        $out .= "  store ptr %o0, ptr %sbase\n";
        $out .= "  store ptr %sbase, ptr @__mir_strpool0\n";
        $out .= "  ret void\n";
        $out .= "k1:\n";
        $out .= "  %is1 = icmp eq i64 %cap, 104\n";
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
        $out .= $this->stringCoreRuntime();
        if ($this->needsArena) {
            $out .= "define ptr @__mir_str_alloc_arena(i64 %n) {\n";
            $out .= "entry:\n";
            $out .= "  %t = add i64 %n, 24\n";
            $out .= "  %p = call ptr @__mir_arena_alloc(i64 %t)\n";
            $out .= "  store i64 %n, ptr %p\n";
            $out .= "  %lenp = getelementptr inbounds i8, ptr %p, i64 8\n";
            $out .= "  %len0 = sub i64 %n, 1\n";
            $out .= "  store i64 %len0, ptr %lenp\n";
            $out .= "  %rcp = getelementptr inbounds i8, ptr %p, i64 16\n";
            $out .= "  store i64 -1, ptr %rcp\n";
            $out .= "  %d = getelementptr inbounds i8, ptr %p, i64 24\n";
            $out .= "  ret ptr %d\n";
            $out .= "}\n";
        }
        if ($this->needsRc) {
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
            if ($this->needsCc) {
                // Cycle-collector mode: the rc word packs rc | color |
                // buffered, so the live count is the SIGNED low-56-bit field
                // (shl 8 / ashr 8) — color/buffered bits must not poison the
                // zero test. On `rc>0` after a dec, the object MIGHT be a
                // cycle root → cc_add_root (Bacon-Rajan PossibleRoot). On
                // `rc<=0`, a *buffered* object is NOT freed here — the
                // collector owns it (else the candidate list dangles).
                $out .= "  %rc1 = sub i64 %rc, 1\n";
                $out .= "  store i64 %rc1, ptr %rcp\n";
                $out .= "  %rcsh = shl i64 %rc1, 8\n";
                $out .= "  %rcsig = ashr i64 %rcsh, 8\n";
                $out .= "  %zero = icmp sle i64 %rcsig, 0\n";
                $out .= "  br i1 %zero, label %free, label %keep\n";
                $out .= "keep:\n";
                $out .= "  call void @__manticore_cc_add_root(ptr %p)\n";
                $out .= "  br label %done\n";
                $out .= "free:\n";
                $out .= "  %bufb = and i64 %rc1, " . (string)\Compile\MemoryAbi::BUFFERED_MASK . "\n";
                $out .= "  %isbuf = icmp ne i64 %bufb, 0\n";
                $out .= "  br i1 %isbuf, label %done, label %dofree\n";
                $out .= "dofree:\n";
                $out .= "  call void @__mir_drop_dispatch(ptr %p)\n";
                $out .= "  %obase = getelementptr i8, ptr %p, i64 -8\n";
                $out .= "  call void @free(ptr %obase)\n";
                $out .= "  br label %done\n";
            } else {
            $out .= $this->rcVerifyAlive();
            $out .= "  %rc1 = sub i64 %rc, 1\n";
            $out .= "  store i64 %rc1, ptr %rcp\n";
            $out .= "  %zero = icmp sle i64 %rc1, 0\n";
            $out .= "  br i1 %zero, label %free, label %done\n";
            $out .= "free:\n";
            // Recursive drop: release this object's obj-typed properties
            // before freeing it, so nested objects don't leak.
            $out .= "  call void @__mir_drop_dispatch(ptr %p)\n";
            $out .= "  %obase = getelementptr i8, ptr %p, i64 -8\n";
            $out .= "  call void @free(ptr %obase)\n";
            $out .= "  br label %done\n";
            }
            $out .= "str:\n";
            $out .= "  %imm = icmp slt i64 %tag, 0\n";
            $out .= "  br i1 %imm, label %done, label %sdec\n";
            $out .= "sdec:\n";
            $out .= "  %src1 = sub i64 %tag, 1\n";
            $out .= "  store i64 %src1, ptr %tagp\n";
            $out .= "  %szero = icmp sle i64 %src1, 0\n";
            $out .= "  br i1 %szero, label %sfree, label %done\n";
            $out .= "sfree:\n";
            $out .= "  %sbase = getelementptr i8, ptr %tagp, i64 -16\n";
            $out .= "  call void @__mir_str_reclaim(ptr %sbase)\n";
            $out .= "  br label %done\n";
            $out .= "done:\n";
            $out .= "  ret void\n";
            $out .= "}\n";
            $out .= $this->dropRuntime();
            if ($this->needsCc) { $out .= $this->ccRuntime(); }
        }
        if ($this->needsStrRc) {
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
            $out .= "  call void @free(ptr %h)\n";
            $out .= "  br label %done\n";
            $out .= "strchk:\n";
            $out .= "  %imm = icmp slt i64 %rc, 0\n";
            $out .= "  br i1 %imm, label %done, label %dec\n";
            $out .= "dec:\n";
            $out .= $this->profBump(2);
            $out .= "  %rc1 = sub i64 %rc, 1\n";
            $out .= "  store i64 %rc1, ptr %h\n";
            $out .= "  %zero = icmp sle i64 %rc1, 0\n";
            $out .= "  br i1 %zero, label %free, label %done\n";
            $out .= "free:\n";
            $out .= "  %sbase = getelementptr i8, ptr %h, i64 -16\n";
            $out .= "  call void @__mir_str_reclaim(ptr %sbase)\n";
            $out .= "  br label %done\n";
            $out .= "done:\n";
            $out .= "  ret void\n";
            $out .= "}\n";
        }
        if (!$this->needsArena) {
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
        $out .= "  br i1 %nxnull, label %newchunk, label %reusen\n";
        $out .= "reusen:\n";
        $out .= "  %nu = getelementptr i8, ptr %nx, i64 16\n";
        $out .= "  store i64 0, ptr %nu\n";
        $out .= "  store ptr %nx, ptr @__mir_arena_cur\n";
        $out .= "  br label %retry\n";
        $out .= "newchunk:\n";
        // A big alloc gets 2x slack so a vec that outgrew its chunk (the
        // append-realloc copy path) has headroom to extend in place on the
        // next appends — otherwise an exact-sized chunk is full on arrival
        // and every further append re-copies to a fresh chunk (O(n^2)).
        $out .= "  %big = icmp ugt i64 %sz16, 65536\n";
        $out .= "  %dbl = shl i64 %sz16, 1\n";
        $out .= "  %ncap = select i1 %big, i64 %dbl, i64 65536\n";
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
        if ($this->needsArenaReset) {
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
        $descs = '';
        $defs = '';
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
            $descs .= '@__mir_cd_' . $id . ' = linkonce_odr global { i64, ptr } { i64 '
                . $id . ', ' . $dropFld . " }\n";
        }
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
     * i64 operand for an object header slot 0: the address of the class's
     * `{ class_id, drop_fn }` descriptor, or 0 for an unknown class.
     */
    private function descSlotValue(?\Compile\Mir\ClassDef $cd): string
    {
        if ($cd === null || $cd->isStruct) { return '0'; }
        return 'ptrtoint (ptr @__mir_cd_' . (string)$cd->classId . ' to i64)';
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
        $out .= "  call void @free(ptr %base)\n";
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
        $out .= "  call void @free(ptr %fbase)\n";
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
     * Central binary-safe string core (zend_string-style). Every string in
     * the system is a headered value `[cap@-24, len@-16, rc@-8, bytes@0]`;
     * `len` is the single source of truth. These are the ONLY primitives that
     * create / measure / compare strings — producers and readers route through
     * here instead of scattering libc strlen/strcmp/manual-len-stores.
     *
     *   __mir_strlen(s)        O(1) length = len@-16 (null-safe → 0)
     *   __mir_str_set_len(s,n) set len@-16 for in-place builders
     *   __mir_str_new(src,n)   factory: header + copy n bytes + NUL
     *   __mir_str_from_cstr(c) THE boundary: raw C-string → headered string
     *                          (the only libc strlen, at the FFI/OS edge)
     *   __mir_str_cmp(a,b)     binary-safe ordering (memcmp(min)+len tiebreak)
     *   __mir_str_eq(a,b)      binary-safe equality (len then memcmp)
     */
    private function stringCoreRuntime(): string
    {
        // Defensive central length. A genuine headered string carries a small
        // refcount (rc@-8 in [-1, 2^28)) and a content length 0 <= len <= cap.
        // A value that fails the plausibility test is a raw/legacy C-string that
        // hasn't been routed through the factory (an un-migrated internal
        // boundary) — fall back to libc strlen rather than read a garbage `len`.
        // This is the same string-vs-foreign discrimination the rc runtime does
        // at ptr-8; it keeps the reader safe while boundaries are migrated.
        $out  = "\ndefine i64 @__mir_strlen(ptr %s) {\nentry:\n";
        $out .= "  %z = icmp eq ptr %s, null\n";
        $out .= "  br i1 %z, label %nul, label %ok\n";
        $out .= "nul:\n  ret i64 0\n";
        $out .= "ok:\n";
        $out .= "  %rcp = getelementptr inbounds i8, ptr %s, i64 -8\n";
        $out .= "  %rcv = load i64, ptr %rcp\n";
        $out .= "  %cp = getelementptr inbounds i8, ptr %s, i64 -24\n";
        $out .= "  %cv = load i64, ptr %cp\n";
        $out .= "  %lp = getelementptr inbounds i8, ptr %s, i64 -16\n";
        $out .= "  %l = load i64, ptr %lp\n";
        $out .= "  %rlo = icmp slt i64 %rcv, -1\n";
        $out .= "  %rhi = icmp sgt i64 %rcv, 268435456\n";
        $out .= "  %llo = icmp slt i64 %l, 0\n";
        $out .= "  %lhi = icmp sgt i64 %l, %cv\n";
        $out .= "  %b1 = or i1 %rlo, %rhi\n";
        $out .= "  %b2 = or i1 %llo, %lhi\n";
        $out .= "  %bad = or i1 %b1, %b2\n";
        $out .= "  br i1 %bad, label %raw, label %ret\n";
        $out .= "raw:\n";
        $out .= "  %sl = call i64 @strlen(ptr %s)\n";
        $out .= "  ret i64 %sl\n";
        $out .= "ret:\n  ret i64 %l\n}\n";

        $out .= "\ndefine void @__mir_str_set_len(ptr %s, i64 %n) {\nentry:\n";
        $out .= "  %lp = getelementptr inbounds i8, ptr %s, i64 -16\n";
        $out .= "  store i64 %n, ptr %lp\n";
        $out .= "  ret void\n}\n";

        $out .= "\ndefine ptr @__mir_str_new(ptr %src, i64 %n) {\nentry:\n";
        $out .= "  %t = add i64 %n, 25\n";                       // 24 header + n + NUL
        $out .= "  %p = call ptr @malloc(i64 %t)\n";
        $out .= "  store i64 %n, ptr %p\n";                      // cap@0
        $out .= "  %lp = getelementptr inbounds i8, ptr %p, i64 8\n";
        $out .= "  store i64 %n, ptr %lp\n";                     // len@8
        $out .= "  %rp = getelementptr inbounds i8, ptr %p, i64 16\n";
        $out .= "  store i64 1, ptr %rp\n";                      // rc@16
        $out .= "  %d = getelementptr inbounds i8, ptr %p, i64 24\n";
        $out .= "  %has = icmp sgt i64 %n, 0\n";
        $out .= "  %sn = icmp ne ptr %src, null\n";
        $out .= "  %cp = and i1 %has, %sn\n";
        $out .= "  br i1 %cp, label %do, label %term\n";
        $out .= "do:\n";
        $out .= "  call ptr @memcpy(ptr %d, ptr %src, i64 %n)\n";
        $out .= "  br label %term\n";
        $out .= "term:\n";
        $out .= "  %nulp = getelementptr inbounds i8, ptr %d, i64 %n\n";
        $out .= "  store i8 0, ptr %nulp\n";
        $out .= "  ret ptr %d\n}\n";

        $out .= "\ndefine ptr @__mir_str_from_cstr(ptr %c) {\nentry:\n";
        $out .= "  %z = icmp eq ptr %c, null\n";
        $out .= "  br i1 %z, label %empty, label %conv\n";
        $out .= "empty:\n";
        $out .= "  %e = call ptr @__mir_str_new(ptr null, i64 0)\n";
        $out .= "  ret ptr %e\n";
        $out .= "conv:\n";
        $out .= "  %n = call i64 @strlen(ptr %c)\n";
        $out .= "  %r = call ptr @__mir_str_new(ptr %c, i64 %n)\n";
        $out .= "  ret ptr %r\n}\n";

        $out .= "\ndefine i64 @__mir_str_cmp(ptr %a, ptr %b) {\nentry:\n";
        $out .= "  %la = call i64 @__mir_strlen(ptr %a)\n";
        $out .= "  %lb = call i64 @__mir_strlen(ptr %b)\n";
        $out .= "  %alt = icmp slt i64 %la, %lb\n";
        $out .= "  %min = select i1 %alt, i64 %la, i64 %lb\n";
        $out .= "  %c = call i32 @memcmp(ptr %a, ptr %b, i64 %min)\n";
        $out .= "  %c64 = sext i32 %c to i64\n";
        $out .= "  %ne = icmp ne i64 %c64, 0\n";
        $out .= "  br i1 %ne, label %ret, label %tie\n";
        $out .= "ret:\n  ret i64 %c64\n";
        $out .= "tie:\n";
        $out .= "  %d = sub i64 %la, %lb\n";
        $out .= "  ret i64 %d\n}\n";

        $out .= "\ndefine i1 @__mir_str_eq(ptr %a, ptr %b) {\nentry:\n";
        // Pointer-equality fast path: the SAME buffer is trivially equal. Hits
        // for interned / literal keys (a repeated `\$x[\"lit\"]` resolves to one
        // .rodata buffer) and self-compares — O(1), skips both strlen + memcmp.
        $out .= "  %same = icmp eq ptr %a, %b\n";
        $out .= "  br i1 %same, label %yes, label %lencmp\n";
        $out .= "yes:\n  ret i1 1\n";
        $out .= "lencmp:\n";
        $out .= "  %la = call i64 @__mir_strlen(ptr %a)\n";
        $out .= "  %lb = call i64 @__mir_strlen(ptr %b)\n";
        $out .= "  %leneq = icmp eq i64 %la, %lb\n";
        $out .= "  br i1 %leneq, label %chk, label %no\n";
        $out .= "no:\n  ret i1 0\n";
        $out .= "chk:\n";
        $out .= "  %c = call i32 @memcmp(ptr %a, ptr %b, i64 %la)\n";
        $out .= "  %eq = icmp eq i32 %c, 0\n";
        $out .= "  ret i1 %eq\n}\n";
        return $out;
    }

    private function concatRuntime(): string
    {
        $out = $this->concatImpl('@__mir_concat', '@__mir_str_alloc');
        if ($this->needsArena) {
            $out .= $this->concatImpl('@__mir_concat_arena', '@__mir_str_alloc_arena');
        }
        if ($this->needsStrAppend) {
            $out .= $this->strAppendImpl();
        }
        return $out;
    }

    /**
     * Amortized `.=`: append `%b` onto `%s`, returning the (possibly new)
     * accumulator. In place when `%s` is sole-owner (rc==1) with spare
     * capacity (`strlen+addlen < cap`); else allocate an over-allocated
     * (~2×) heap copy, RELEASE the old `%s` (frees a sole owner, decrements
     * a shared one, skips an immortal), and return the copy. The caller's
     * StoreLocal therefore does NOT release-before-overwrite — this helper
     * owns the old value's lifetime, keeping the in-place identity intact.
     */
    private function strAppendImpl(): string
    {
        $out  = "\ndefine ptr @__mir_str_append(ptr %s, ptr %b) {\n";
        $out .= "entry:\n";
        // sole ownership? rc@-8 == 1 (immortal -1 / shared >1 fail → grow).
        $out .= "  %rcp = getelementptr i8, ptr %s, i64 -8\n";
        $out .= "  %rc = load i64, ptr %rcp\n";
        $out .= "  %sole = icmp eq i64 %rc, 1\n";
        $out .= "  br i1 %sole, label %chkcap, label %grow\n";
        $out .= "chkcap:\n";
        // O(1) accumulator length via len@-16 (set_len maintains it each append)
        // — the whole point of the length-prefixed string: `\$s .= …` is O(N),
        // not O(N²) from a libc strlen rescan of the accumulator per append.
        // `%b` (the appended chunk) via __mir_strlen too: O(1) + binary-safe for
        // a headered chunk, libc-strlen fallback for a raw one.
        $out .= "  %la = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %lb = call i64 @__mir_strlen(ptr %b)\n";
        $out .= "  %need = add i64 %la, %lb\n";        // content bytes after append
        $out .= "  %capp = getelementptr i8, ptr %s, i64 -24\n";
        $out .= "  %cap = load i64, ptr %capp\n";
        $out .= "  %fits = icmp slt i64 %need, %cap\n"; // need+1 (NUL) <= cap
        $out .= "  br i1 %fits, label %inplace, label %grow\n";
        $out .= "inplace:\n";
        $out .= "  %dst = getelementptr inbounds i8, ptr %s, i64 %la\n";
        $out .= "  %lb1 = add i64 %lb, 1\n";          // copy b + its NUL
        $out .= "  call ptr @memcpy(ptr %dst, ptr %b, i64 %lb1)\n";
        $out .= "  call void @__mir_str_set_len(ptr %s, i64 %need)\n";
        $out .= "  ret ptr %s\n";
        $out .= "grow:\n";
        $out .= "  %la2 = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %lb2 = call i64 @__mir_strlen(ptr %b)\n";
        $out .= "  %sum = add i64 %la2, %lb2\n";
        $out .= "  %dbl = shl i64 %sum, 1\n";         // over-allocate ~2×(la+lb)
        $out .= "  %ncap = add i64 %dbl, 1\n";        // room for content + NUL
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %ncap)\n";
        $out .= "  call ptr @memcpy(ptr %buf, ptr %s, i64 %la2)\n";
        $out .= "  %dst2 = getelementptr inbounds i8, ptr %buf, i64 %la2\n";
        $out .= "  %lb21 = add i64 %lb2, 1\n";
        $out .= "  call ptr @memcpy(ptr %dst2, ptr %b, i64 %lb21)\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %sum)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %s)\n";
        $out .= "  ret ptr %buf\n";
        $out .= "}\n";
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
        if ($this->needsSubstr) {
            // PHP/Zend substr() normalization, branchless (all `select`):
            //   n = strlen(s)
            //   start = start<0 ? max(0, n+start) : min(start, n)
            //   end = !haveLen ? n
            //       : len<0 ? max(start, n+len) : min(n, start+len)
            //   rlen = end - start  (always >= 0)
            $out .= "\ndefine ptr @__mir_substr(ptr %s, i64 %start, i64 %len, i64 %haveLen) {\n";
            $out .= "entry:\n";
            $out .= "  %n = call i64 @strlen(ptr %s)\n";
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
        if ($this->needsStrRepeat) {
            $out .= "\ndefine ptr @__mir_str_repeat(ptr %s, i64 %n) {\n";
            $out .= "entry:\n";
            $out .= "  %slen = call i64 @strlen(ptr %s)\n";
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
        if ($this->needsIpow) { $out .= $this->ipowRuntime(); }
        if ($this->needsStrtolower) { $out .= $this->caseConvRuntime('__mir_strtolower', 65, 90, 32); }
        if ($this->needsStrtoupper) { $out .= $this->caseConvRuntime('__mir_strtoupper', 97, 122, -32); }
        if ($this->needsAddslashes) { $out .= $this->addslashesRuntime(); }
        if ($this->needsJsonEscape) { $out .= $this->jsonEscapeRuntime(); }
        if ($this->needsStrReplaceOne) { $out .= $this->strReplaceOneRuntime(); }
        if ($this->needsStrpos) {
            // Zend-faithful `int|false`: hit → NaN-boxed int(offset),
            // miss → NaN-boxed bool(false). Callers read the tag.
            // strpos($h, $n, $off): search from byte offset $off (PHP-faithful —
            // a negative offset counts from the end; an offset past the end
            // misses). The returned position is relative to the ORIGINAL $h.
            // Result is NaN-boxed: hit → int cell, miss → `false` cell.
            $out .= "\ndefine i64 @__mir_strpos(ptr %h, ptr %n, i64 %off) {\n";
            $out .= "entry:\n";
            $out .= "  %hlen = call i64 @strlen(ptr %h)\n";
            $out .= "  %isneg = icmp slt i64 %off, 0\n";
            $out .= "  %fromend = add i64 %hlen, %off\n";
            $out .= "  %off1 = select i1 %isneg, i64 %fromend, i64 %off\n";
            $out .= "  %neg2 = icmp slt i64 %off1, 0\n";
            $out .= "  %off2 = select i1 %neg2, i64 0, i64 %off1\n";
            $out .= "  %toobig = icmp sgt i64 %off2, %hlen\n";
            $out .= "  br i1 %toobig, label %miss, label %search\n";
            $out .= "search:\n";
            $out .= "  %hstart = getelementptr i8, ptr %h, i64 %off2\n";
            $out .= "  %p = call ptr @strstr(ptr %hstart, ptr %n)\n";
            $out .= "  %isnull = icmp eq ptr %p, null\n";
            $out .= "  br i1 %isnull, label %miss, label %hit\n";
            $out .= "hit:\n";
            $out .= "  %hi = ptrtoint ptr %h to i64\n";
            $out .= "  %pi = ptrtoint ptr %p to i64\n";
            $out .= "  %d = sub i64 %pi, %hi\n";
            $out .= "  %dm = and i64 %d, 281474976710655\n";
            $out .= "  %db = or i64 %dm, -4222124650659840\n";
            $out .= "  ret i64 %db\n";
            $out .= "miss:\n";
            $out .= "  ret i64 -3940649673949184\n";
            $out .= "}\n";
        }
        if ($this->needsStrExplode) {
            // `__mir_str_explode(delim, subj, limit) -> ptr` — single-scan split
            // into a fresh vec[string]. Each segment is a POOLED `__mir_str_alloc`
            // (size-class free-list, sets len=n-1 & rc=1) + memcpy — NOT raw-malloc
            // str_new: the pool reuse is what makes N-segment splitting cheap.
            // limit>1 keeps splitting; the tail block appends the remainder. An
            // empty delim yields [subj] (matches the prelude explode). Replaces the
            // PHP-level prelude explode's 8×(strpos-cell + substr-malloc + append)
            // per call with one C loop.
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
            $out .= "  store i64 %limit, ptr %limp\n";
            $out .= "  %de0 = icmp eq i64 %dlen, 0\n";
            $out .= "  br i1 %de0, label %tail, label %loop\n";
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
            $out .= "  ret ptr %arrn2\n";
            $out .= "}\n";
        }
        return $out;
    }

    /** strtolower / strtoupper body: transform bytes in [lo,hi] by delta. */
    /**
     * `__mir_ipow(base, exp) -> i64` — integer exponentiation by repeated
     * multiply (exp times). A negative exponent returns 0 (PHP would yield a
     * float; the int-typed path can't carry it — a documented edge).
     */
    private function ipowRuntime(): string
    {
        $out  = "\ndefine i64 @__mir_ipow(i64 %base, i64 %exp) {\n";
        $out .= "entry:\n";
        $out .= "  %neg = icmp slt i64 %exp, 0\n";
        $out .= "  br i1 %neg, label %ret0, label %loop\n";
        $out .= "ret0:\n";
        $out .= "  ret i64 0\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [0, %entry], [%i2, %cont]\n";
        $out .= "  %acc = phi i64 [1, %entry], [%acc2, %cont]\n";
        $out .= "  %done = icmp sge i64 %i, %exp\n";
        $out .= "  br i1 %done, label %fin, label %cont\n";
        $out .= "cont:\n";
        $out .= "  %acc2 = mul i64 %acc, %base\n";
        $out .= "  %i2 = add i64 %i, 1\n";
        $out .= "  br label %loop\n";
        $out .= "fin:\n";
        $out .= "  ret i64 %acc\n";
        $out .= "}\n";
        return $out;
    }

    private function caseConvRuntime(string $fn, int $lo, int $hi, int $delta): string
    {
        $out  = "\ndefine ptr @" . $fn . "(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %slen = call i64 @strlen(ptr %s)\n";
        $out .= "  %sz = add i64 %slen, 1\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %sz)\n";
        $out .= "  br label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [0, %entry], [%i2, %cont]\n";
        $out .= "  %done = icmp sge i64 %i, %slen\n";
        $out .= "  br i1 %done, label %fin, label %body\n";
        $out .= "body:\n";
        $out .= "  %sp = getelementptr inbounds i8, ptr %s, i64 %i\n";
        $out .= "  %c = load i8, ptr %sp\n";
        $out .= "  %ge = icmp sge i8 %c, " . (string)$lo . "\n";
        $out .= "  %le = icmp sle i8 %c, " . (string)$hi . "\n";
        $out .= "  %in = and i1 %ge, %le\n";
        $out .= "  %cc = add i8 %c, " . (string)$delta . "\n";
        $out .= "  %oc = select i1 %in, i8 %cc, i8 %c\n";
        $out .= "  %dp = getelementptr inbounds i8, ptr %buf, i64 %i\n";
        $out .= "  store i8 %oc, ptr %dp\n";
        $out .= "  br label %cont\n";
        $out .= "cont:\n";
        $out .= "  %i2 = add i64 %i, 1\n";
        $out .= "  br label %loop\n";
        $out .= "fin:\n";
        $out .= "  %np = getelementptr inbounds i8, ptr %buf, i64 %slen\n";
        $out .= "  store i8 0, ptr %np\n";
        $out .= "  ret ptr %buf\n";
        $out .= "}\n";
        return $out;
    }
}
