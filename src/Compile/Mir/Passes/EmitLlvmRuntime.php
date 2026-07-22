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
            if ($this->rt->needsCc) {
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
            $out .= "  %sbase = getelementptr i8, ptr %tagp, i64 -" . (string)\Compile\MemoryAbi::STRING_RC_AT . "\n";
            $out .= "  call void @__mir_str_reclaim(ptr %sbase)\n";
            $out .= "  br label %done\n";
            $out .= "done:\n";
            $out .= "  ret void\n";
            $out .= "}\n";
            $out .= $this->dropRuntime();
            if ($this->rt->needsCc) { $out .= $this->ccRuntime(); }
        }
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
            $out .= "  call void @free(ptr %h)\n";
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
                $this->memberFlags($mm->visibility, $mm->isStatic, $mm->isAbstract, $mm->isFinal, false),
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
            $rows[] = \Compile\Mir\RuntimeLibrary::rmetaAttrRow($this->strSymBytes($nameSym), $argsFld, $newFld);
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
                $this->strSymBytes($nameSym), 0, $trampFld,
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
        if ($this->rt->needsStrReplaceOne) { $out .= $this->lib->strReplaceOne(); }
        if ($this->rt->needsStrpos) {
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
        if ($this->rt->needsStrExplode) {
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

}
