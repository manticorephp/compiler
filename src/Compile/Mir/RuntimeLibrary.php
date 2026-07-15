<?php

namespace Compile\Mir;

/**
 * The fixed LLVM text of the runtime helpers — the string core, the amortized
 * append, integer pow, ASCII case conversion, addslashes, the JSON encoder and
 * escaper, single-shot str_replace.
 *
 * Every method here is a pure function of its arguments: it builds a block of
 * IR that never varies with the module being compiled. Which of them get emitted
 * is decided elsewhere (the {@see RuntimeFeatures} demand flags) — this class
 * only knows how to spell them.
 */
final class RuntimeLibrary
{
    /**
     * i64 operand for an object header slot 0: the address of the class's
     * `{ class_id, drop_fn }` descriptor, or 0 for an unknown class.
     */
    public function descSlotValue(?\Compile\Mir\ClassDef $cd): string
    {
        if ($cd === null || $cd->isStruct) { return '0'; }
        return 'ptrtoint (ptr @__mir_cd_' . (string)$cd->classId . ' to i64)';
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
    public function stringCore(): string
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

        $nH     = (string)\Compile\MemoryAbi::STRING_HEADER_SIZE;
        $nHashAt = (string)\Compile\MemoryAbi::STRING_HASH_AT;
        $nCapAt = (string)\Compile\MemoryAbi::STRING_CAP_AT;
        $nLenAt = (string)\Compile\MemoryAbi::STRING_LEN_AT;
        $nRcAt  = (string)\Compile\MemoryAbi::STRING_RC_AT;
        $nTot   = (string)(\Compile\MemoryAbi::STRING_HEADER_SIZE + 1); // header + NUL
        $out .= "\ndefine ptr @__mir_str_new(ptr %src, i64 %n) {\nentry:\n";
        $out .= "  %t = add i64 %n, " . $nTot . "\n";            // header + n + NUL
        $out .= "  %p = call ptr @malloc(i64 %t)\n";
        $out .= "  %ncp = getelementptr inbounds i8, ptr %p, i64 " . $nCapAt . "\n";
        $out .= "  store i64 %n, ptr %ncp\n";                    // cap
        $out .= "  %lp = getelementptr inbounds i8, ptr %p, i64 " . $nLenAt . "\n";
        $out .= "  store i64 %n, ptr %lp\n";                     // len
        $out .= "  %rp = getelementptr inbounds i8, ptr %p, i64 " . $nRcAt . "\n";
        $out .= "  store i64 1, ptr %rp\n";                      // rc
        $out .= "  %hp = getelementptr inbounds i8, ptr %p, i64 " . $nHashAt . "\n";
        $out .= "  store i64 0, ptr %hp\n";                      // hash = 0 (uncomputed)
        $out .= "  %d = getelementptr inbounds i8, ptr %p, i64 " . $nH . "\n";
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

        // `$s[$i]` read — negative index counts from the end; out-of-range → "".
        // Returns a fresh 1-char headered string (binary-safe).
        $out .= "\ndefine ptr @__mir_str_char_at(ptr %s, i64 %i) {\nentry:\n";
        $out .= "  %len = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %neg = icmp slt i64 %i, 0\n";
        $out .= "  %iadj = add i64 %i, %len\n";
        $out .= "  %ix = select i1 %neg, i64 %iadj, i64 %i\n";
        $out .= "  %lo = icmp slt i64 %ix, 0\n";
        $out .= "  %hi = icmp sge i64 %ix, %len\n";
        $out .= "  %oob = or i1 %lo, %hi\n";
        $out .= "  br i1 %oob, label %empty, label %one\n";
        $out .= "empty:\n";
        $out .= "  %e = call ptr @__mir_str_new(ptr null, i64 0)\n";
        $out .= "  ret ptr %e\n";
        $out .= "one:\n";
        $out .= "  %cp = getelementptr inbounds i8, ptr %s, i64 %ix\n";
        $out .= "  %r = call ptr @__mir_str_new(ptr %cp, i64 1)\n";
        $out .= "  ret ptr %r\n}\n";

        // `\$s[\$i]` read as a BYTE — the same access as __mir_str_char_at, minus
        // the allocation. char_at must hand back a `string`, so it mints a fresh
        // 1-char headered buffer for every character read: scanning a 2 MB source
        // costs ~80 MB of arena garbage (measured: 100 MB RSS to read 2 MB). When
        // the character is only ever compared to a 1-char literal or passed to
        // ord() — which is what every scanner does — the string is never observed,
        // and DemoteCharLocals rewrites the read to this instead.
        //
        // Out of range → 0, which is exactly `ord("")`, so a demoted local keeps
        // char_at's own out-of-range behaviour. Negative counts from the end.
        $out .= "\ndefine i64 @__mir_str_byte_at(ptr %s, i64 %i) {\nentry:\n";
        $out .= "  %len = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %neg = icmp slt i64 %i, 0\n";
        $out .= "  %iadj = add i64 %i, %len\n";
        $out .= "  %ix = select i1 %neg, i64 %iadj, i64 %i\n";
        $out .= "  %lo = icmp slt i64 %ix, 0\n";
        $out .= "  %hi = icmp sge i64 %ix, %len\n";
        $out .= "  %oob = or i1 %lo, %hi\n";
        $out .= "  br i1 %oob, label %zero, label %one\n";
        $out .= "zero:\n  ret i64 0\n";
        $out .= "one:\n";
        $out .= "  %cp = getelementptr inbounds i8, ptr %s, i64 %ix\n";
        $out .= "  %b = load i8, ptr %cp\n";
        $out .= "  %z = zext i8 %b to i64\n";
        $out .= "  ret i64 %z\n}\n";

        // isset(\$s[\$i]) — true iff the (end-relative) offset is in range.
        $out .= "\ndefine i1 @__mir_str_offset_isset(ptr %s, i64 %i) {\nentry:\n";
        $out .= "  %len = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %neg = icmp slt i64 %i, 0\n";
        $out .= "  %iadj = add i64 %i, %len\n";
        $out .= "  %ix = select i1 %neg, i64 %iadj, i64 %i\n";
        $out .= "  %lo = icmp slt i64 %ix, 0\n";
        $out .= "  %hi = icmp sge i64 %ix, %len\n";
        $out .= "  %oob = or i1 %lo, %hi\n";
        $out .= "  %ok = xor i1 %oob, true\n";
        $out .= "  ret i1 %ok\n}\n";

        // `$s[$i] = $c` — byte %ix becomes the first byte of %chs. Growing past
        // the end pads the gap with spaces (PHP). Negative offset counts from the
        // end; still-negative → no-op copy.
        //
        // SOLE OWNER + IN RANGE → mutate in place. Copying on every write made a
        // byte-at-a-time loop QUADRATIC: filling a 160 KB buffer allocated 20 GB
        // (php stays flat at 25 MB), because each write memcpy'd the whole string
        // and the arena keeps every copy alive. Same sole-ownership test
        // `__mir_str_append` already uses — rc@-8 == 1, so a shared string (rc>1)
        // or an immortal literal (rc == -1) still copies, and PHP's value
        // semantics hold.
        $out .= "\ndefine ptr @__mir_str_set_char(ptr %s, i64 %i, ptr %chs) {\nentry:\n";
        $out .= "  %len = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %neg = icmp slt i64 %i, 0\n";
        $out .= "  %iadj = add i64 %i, %len\n";
        $out .= "  %ix = select i1 %neg, i64 %iadj, i64 %i\n";
        $out .= "  %bad = icmp slt i64 %ix, 0\n";
        $out .= "  br i1 %bad, label %nop, label %tryinplace\n";
        $out .= "nop:\n";
        $out .= "  %cpy = call ptr @__mir_str_new(ptr %s, i64 %len)\n";
        $out .= "  ret ptr %cpy\n";
        $out .= "tryinplace:\n";
        $out .= "  %rcp = getelementptr i8, ptr %s, i64 -8\n";
        $out .= "  %rc = load i64, ptr %rcp\n";
        $out .= "  %sole = icmp eq i64 %rc, 1\n";
        $out .= "  %fits = icmp slt i64 %ix, %len\n";     // no growth: no realloc
        $out .= "  %canmut = and i1 %sole, %fits\n";
        $out .= "  br i1 %canmut, label %inplace, label %go\n";
        $out .= "inplace:\n";
        $out .= "  %ipd = getelementptr inbounds i8, ptr %s, i64 %ix\n";
        $out .= "  %ipb = load i8, ptr %chs\n";
        $out .= "  store i8 %ipb, ptr %ipd\n";
        // Content changed under the same ptr → invalidate the cached hash, or an
        // assoc keyed by this string would look it up under its old contents.
        $out .= "  %iph = getelementptr inbounds i8, ptr %s, i64 " . (string)\Compile\MemoryAbi::STRING_HASH_OFFSET . "\n";
        $out .= "  store i64 0, ptr %iph\n";
        $out .= "  ret ptr %s\n";
        $out .= "go:\n";
        $out .= "  %ix1 = add i64 %ix, 1\n";
        $out .= "  %grow = icmp sgt i64 %ix1, %len\n";
        $out .= "  %newlen = select i1 %grow, i64 %ix1, i64 %len\n";
        $out .= "  %buf = call ptr @__mir_str_new(ptr null, i64 %newlen)\n";
        $out .= "  call ptr @memcpy(ptr %buf, ptr %s, i64 %len)\n";
        $out .= "  br i1 %grow, label %pad, label %setc\n";
        $out .= "pad:\n";
        $out .= "  %padp = getelementptr inbounds i8, ptr %buf, i64 %len\n";
        $out .= "  %padn = sub i64 %ix, %len\n";
        $out .= "  call ptr @memset(ptr %padp, i32 32, i64 %padn)\n";
        $out .= "  br label %setc\n";
        $out .= "setc:\n";
        $out .= "  %chb = load i8, ptr %chs\n";
        $out .= "  %dst = getelementptr inbounds i8, ptr %buf, i64 %ix\n";
        $out .= "  store i8 %chb, ptr %dst\n";
        $out .= "  ret ptr %buf\n}\n";
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
    public function strAppend(): string
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
        // Content changed under the same ptr → invalidate the cached hash.
        $out .= "  %hinv = getelementptr inbounds i8, ptr %s, i64 " . (string)\Compile\MemoryAbi::STRING_HASH_OFFSET . "\n";
        $out .= "  store i64 0, ptr %hinv\n";
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

    /** strtolower / strtoupper body: transform bytes in [lo,hi] by delta. */
    /**
     * `__mir_ipow(base, exp) -> i64` — integer exponentiation by repeated
     * multiply (exp times). A negative exponent returns 0 (PHP would yield a
     * float; the int-typed path can't carry it — a documented edge).
     */
    public function ipow(): string
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

    public function caseConv(string $fn, int $lo, int $hi, int $delta): string
    {
        $out  = "\ndefine ptr @" . $fn . "(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %slen = call i64 @__mir_strlen(ptr %s)\n";
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

    /** Runtime: backslash-escape `'` `"` `\` (NUL handling is moot for a
     * strlen-scanned C string). Worst case doubles the length. */
    public function addslashes(): string
    {
        $out  = "\ndefine ptr @__mir_addslashes(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %slen = call i64 @strlen(ptr %s)\n";
        $out .= "  %cap0 = mul i64 %slen, 2\n";
        $out .= "  %cap = add i64 %cap0, 1\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %cap)\n";
        $out .= "  br label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [0, %entry], [%i2, %cont]\n";
        $out .= "  %j = phi i64 [0, %entry], [%j2, %cont]\n";
        $out .= "  %done = icmp sge i64 %i, %slen\n";
        $out .= "  br i1 %done, label %fin, label %body\n";
        $out .= "body:\n";
        $out .= "  %sp = getelementptr inbounds i8, ptr %s, i64 %i\n";
        $out .= "  %c = load i8, ptr %sp\n";
        $out .= "  %isq = icmp eq i8 %c, 39\n";
        $out .= "  %isdq = icmp eq i8 %c, 34\n";
        $out .= "  %isbs = icmp eq i8 %c, 92\n";
        $out .= "  %q1 = or i1 %isq, %isdq\n";
        $out .= "  %spec = or i1 %q1, %isbs\n";
        $out .= "  br i1 %spec, label %esc, label %plain\n";
        $out .= "esc:\n";
        $out .= "  %dp = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 92, ptr %dp\n";
        $out .= "  %j1 = add i64 %j, 1\n";
        $out .= "  %dp2 = getelementptr inbounds i8, ptr %buf, i64 %j1\n";
        $out .= "  store i8 %c, ptr %dp2\n";
        $out .= "  %je = add i64 %j1, 1\n";
        $out .= "  br label %cont\n";
        $out .= "plain:\n";
        $out .= "  %dp3 = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 %c, ptr %dp3\n";
        $out .= "  %jp = add i64 %j, 1\n";
        $out .= "  br label %cont\n";
        $out .= "cont:\n";
        $out .= "  %j2 = phi i64 [%je, %esc], [%jp, %plain]\n";
        $out .= "  %i2 = add i64 %i, 1\n";
        $out .= "  br label %loop\n";
        $out .= "fin:\n";
        $out .= "  %np = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 0, ptr %np\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %j)\n";
        $out .= "  ret ptr %buf\n";
        $out .= "}\n";
        return $out;
    }

    /** Runtime: JSON-escape `"` `\` \b \t \n \f \r (worst case doubles len).
     * For `"`/`\` the escape byte is the char itself; the controls map to
     * their letter (b/t/n/f/r). All other bytes copy raw. */
    public function jsonEscape(): string
    {
        $out  = "\ndefine ptr @__mir_json_escape(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %slen = call i64 @strlen(ptr %s)\n";
        $out .= "  %cap0 = mul i64 %slen, 2\n";
        $out .= "  %cap = add i64 %cap0, 1\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %cap)\n";
        $out .= "  br label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [0, %entry], [%i2, %cont]\n";
        $out .= "  %j = phi i64 [0, %entry], [%j2, %cont]\n";
        $out .= "  %done = icmp sge i64 %i, %slen\n";
        $out .= "  br i1 %done, label %fin, label %body\n";
        $out .= "body:\n";
        $out .= "  %sp = getelementptr inbounds i8, ptr %s, i64 %i\n";
        $out .= "  %c = load i8, ptr %sp\n";
        $out .= "  %is34 = icmp eq i8 %c, 34\n";   // "
        $out .= "  %is92 = icmp eq i8 %c, 92\n";   // backslash
        $out .= "  %is10 = icmp eq i8 %c, 10\n";   // \n
        $out .= "  %is9  = icmp eq i8 %c, 9\n";    // \t
        $out .= "  %is13 = icmp eq i8 %c, 13\n";   // \r
        $out .= "  %is8  = icmp eq i8 %c, 8\n";    // \b
        $out .= "  %is12 = icmp eq i8 %c, 12\n";   // \f
        // Escape byte: char itself for " and \\; the letter for the controls.
        $out .= "  %e1 = select i1 %is10, i8 110, i8 %c\n";
        $out .= "  %e2 = select i1 %is9,  i8 116, i8 %e1\n";
        $out .= "  %e3 = select i1 %is13, i8 114, i8 %e2\n";
        $out .= "  %e4 = select i1 %is8,  i8 98,  i8 %e3\n";
        $out .= "  %e5 = select i1 %is12, i8 102, i8 %e4\n";
        $out .= "  %o1 = or i1 %is34, %is92\n";
        $out .= "  %o2 = or i1 %o1, %is10\n";
        $out .= "  %o3 = or i1 %o2, %is9\n";
        $out .= "  %o4 = or i1 %o3, %is13\n";
        $out .= "  %o5 = or i1 %o4, %is8\n";
        $out .= "  %spec = or i1 %o5, %is12\n";
        $out .= "  br i1 %spec, label %esc, label %plain\n";
        $out .= "esc:\n";
        $out .= "  %dp = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 92, ptr %dp\n";
        $out .= "  %j1 = add i64 %j, 1\n";
        $out .= "  %dp2 = getelementptr inbounds i8, ptr %buf, i64 %j1\n";
        $out .= "  store i8 %e5, ptr %dp2\n";
        $out .= "  %je = add i64 %j1, 1\n";
        $out .= "  br label %cont\n";
        $out .= "plain:\n";
        $out .= "  %dp3 = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 %c, ptr %dp3\n";
        $out .= "  %jp = add i64 %j, 1\n";
        $out .= "  br label %cont\n";
        $out .= "cont:\n";
        $out .= "  %j2 = phi i64 [%je, %esc], [%jp, %plain]\n";
        $out .= "  %i2 = add i64 %i, 1\n";
        $out .= "  br label %loop\n";
        $out .= "fin:\n";
        $out .= "  %np = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 0, ptr %np\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %j)\n";
        $out .= "  ret ptr %buf\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * Ryu `mulShift(m, pow5(idx), j)` as one i128 runtime primitive
     * (`__mir_ryu_msp`), backing the shortest-float encoder in PHP. Porting the
     * whole of Ryu's d2d in PHP is clean EXCEPT the 128-bit power-of-five table
     * math; that lives here, in native i128, and PHP holds the readable
     * skeleton. Small-table variant (Ulf Adams' d2s_small_table.h): any pow5
     * index is derived from a 26-entry base table + SPLIT2/OFFSETS, so the whole
     * table is ~90 constants, not ~1200.
     *
     * `__mir_ryu_msp(m, idx, j, inv)` computes `computePow5(idx)` (inv==0) or
     * `computeInvPow5(idx)` (inv!=0) into a 128-bit {lo,hi}, then returns
     * `mulShift64(m, {lo,hi}, j) = (((m*lo)>>64) + m*hi) >> (j-64)`. Every
     * intermediate fits i128: `b2 << (64-delta)` self-balances to ~2^125
     * because delta grows ~2.32 bits per pow5 index exactly as b2 does.
     */
    /** Comma-join decimal-string entries as `i64 <v>` for an LLVM array literal.
     *  @param string[] $a */
    private function ryuI64List(array $a): string
    {
        $out = '';
        $first = true;
        foreach ($a as $v) {
            if (!$first) { $out .= ', '; }
            $first = false;
            $out .= 'i64 ' . $v;
        }
        return $out;
    }

    /** @param string[] $a */
    private function ryuI32List(array $a): string
    {
        $out = '';
        $first = true;
        foreach ($a as $v) {
            if (!$first) { $out .= ', '; }
            $first = false;
            $out .= 'i32 ' . $v;
        }
        return $out;
    }

    public function ryuMsp(): string
    {
        // Every entry is a STRING literal so the array stays uniformly typed —
        // a mixed int|string array infers cell elements, and `(string)$cell`
        // through the concat below mis-unboxes to a tagged value under the
        // native self-build ({@see the cell-unbox hazards}).
        $p5tab = [
            '1', '5', '25', '125', '625', '3125', '15625', '78125', '390625',
            '1953125', '9765625', '48828125', '244140625', '1220703125',
            '6103515625', '30517578125', '152587890625', '762939453125',
            '3814697265625', '19073486328125', '95367431640625',
            '476837158203125', '2384185791015625', '11920928955078125',
            '59604644775390625', '298023223876953125',
        ];
        // DOUBLE_POW5_SPLIT2 (13 pairs), flattened lo,hi.
        $s2 = [
            '0', '1152921504606846976', '0', '1490116119384765625',
            '1032610780636961552', '1925929944387235853',
            '7910200175544436838', '1244603055572228341',
            '16941905809032713930', '1608611746708759036',
            '13024893955298202172', '2079081953128979843',
            '6607496772837067824', '1343575221513417750',
            '17332926989895652603', '1736530273035216783',
            '13037379183483547984', '2244412773384604712',
            '1605989338741628675', '1450417759929778918',
            '9630225068416591280', '1874621017369538693',
            '665883850346957067', '1211445438634777304',
            '14931890668723713708', '1565756531257009982',
        ];
        // DOUBLE_POW5_INV_SPLIT2 (15 pairs), flattened lo,hi.
        $is2 = [
            '1', '2305843009213693952',
            '5955668970331000884', '1784059615882449851',
            '8982663654677661702', '1380349269358112757',
            '7286864317269821294', '2135987035920910082',
            '7005857020398200553', '1652639921975621497',
            '17965325103354776697', '1278668206209430417',
            '8928596168509315048', '1978643211784836272',
            '10075671573058298858', '1530901034580419511',
            '597001226353042382', '1184477304306571148',
            '1527430471115325346', '1832889850782397517',
            '12533209867169019542', '1418129833677084982',
            '5577825024675947042', '2194449627517475473',
            '11006974540203867551', '1697873161311732311',
            '10313493231639821582', '1313665730009899186',
            '12701016819766672773', '2032799256770390445',
        ];
        // POW5_OFFSETS (21) / POW5_INV_OFFSETS (22), u32 each — decimal strings
        // of the ryu hex constants, kept as strings for the same uniform-typing
        // reason as the pow5 tables above.
        $off = [
            '0', '0', '0', '0', '1073741824', '1500076437', '1431590229',
            '1448432917', '1091896580', '1079333904', '1146442053',
            '1146111296', '1163220304', '1073758208', '2521039936',
            '1431721317', '1413824581', '1075134801', '1431671125',
            '1363170645', '261',
        ];
        $ioff = [
            '1414808916', '67458373', '268701696', '4195348', '1073807360',
            '1091917141', '1108', '65604', '1073741824', '1140850753',
            '1346716752', '1431634004', '1365595476', '1073758208', '16777217',
            '66816', '1364284433', '89478484', '1346442496', '1074003968',
            '84148496', '0',
        ];
        $out  = "\n@.ryu.p5tab = private unnamed_addr constant [26 x i64] ["
              . $this->ryuI64List($p5tab) . "]\n";
        $out .= "@.ryu.s2 = private unnamed_addr constant [26 x i64] ["
              . $this->ryuI64List($s2) . "]\n";
        $out .= "@.ryu.is2 = private unnamed_addr constant [30 x i64] ["
              . $this->ryuI64List($is2) . "]\n";
        $out .= "@.ryu.off = private unnamed_addr constant [21 x i32] ["
              . $this->ryuI32List($off) . "]\n";
        $out .= "@.ryu.ioff = private unnamed_addr constant [22 x i32] ["
              . $this->ryuI32List($ioff) . "]\n";

        $out .= "\ndefine i64 @__mir_ryu_msp(i64 %m, i64 %idx, i64 %j, i64 %inv) {\n";
        $out .= "entry:\n";
        $out .= "  %isinv = icmp ne i64 %inv, 0\n";
        $out .= "  br i1 %isinv, label %invb, label %pow\n";

        // ── computePow5(idx) ──
        $out .= "pow:\n";
        $out .= "  %pbase = udiv i64 %idx, 26\n";
        $out .= "  %pbase2 = mul i64 %pbase, 26\n";
        $out .= "  %poff = sub i64 %idx, %pbase2\n";
        $out .= "  %pbi = mul i64 %pbase, 2\n";
        $out .= "  %plop = getelementptr [26 x i64], ptr @.ryu.s2, i64 0, i64 %pbi\n";
        $out .= "  %pmullo = load i64, ptr %plop\n";
        $out .= "  %pbi1 = add i64 %pbi, 1\n";
        $out .= "  %phip = getelementptr [26 x i64], ptr @.ryu.s2, i64 0, i64 %pbi1\n";
        $out .= "  %pmulhi = load i64, ptr %phip\n";
        $out .= "  %poff0 = icmp eq i64 %poff, 0\n";
        $out .= "  br i1 %poff0, label %pdone0, label %pcomp\n";
        $out .= "pdone0:\n  br label %merge\n";
        $out .= "pcomp:\n";
        $out .= "  %pm5p = getelementptr [26 x i64], ptr @.ryu.p5tab, i64 0, i64 %poff\n";
        $out .= "  %pm5 = load i64, ptr %pm5p\n";
        $out .= "  %pm5x = zext i64 %pm5 to i128\n";
        $out .= "  %pmullox = zext i64 %pmullo to i128\n";
        $out .= "  %pmulhix = zext i64 %pmulhi to i128\n";
        $out .= "  %pb0 = mul i128 %pm5x, %pmullox\n";
        $out .= "  %pb2 = mul i128 %pm5x, %pmulhix\n";
        $out .= "  %ppim = mul i64 %idx, 1217359\n";
        $out .= "  %ppis = lshr i64 %ppim, 19\n";
        $out .= "  %ppi = add i64 %ppis, 1\n";
        $out .= "  %ppbm = mul i64 %pbase2, 1217359\n";
        $out .= "  %ppbs = lshr i64 %ppbm, 19\n";
        $out .= "  %ppb = add i64 %ppbs, 1\n";
        $out .= "  %pdelta = sub i64 %ppi, %ppb\n";
        $out .= "  %pdeltax = zext i64 %pdelta to i128\n";
        $out .= "  %pb0s = lshr i128 %pb0, %pdeltax\n";
        $out .= "  %psh = sub i64 64, %pdelta\n";
        $out .= "  %pshx = zext i64 %psh to i128\n";
        $out .= "  %pb2s = shl i128 %pb2, %pshx\n";
        $out .= "  %pw = udiv i64 %idx, 16\n";
        $out .= "  %pwp = getelementptr [21 x i32], ptr @.ryu.off, i64 0, i64 %pw\n";
        $out .= "  %pw32 = load i32, ptr %pwp\n";
        $out .= "  %pw64 = zext i32 %pw32 to i64\n";
        $out .= "  %pr = urem i64 %idx, 16\n";
        $out .= "  %prs = mul i64 %pr, 2\n";
        $out .= "  %pov = lshr i64 %pw64, %prs\n";
        $out .= "  %pov3 = and i64 %pov, 3\n";
        $out .= "  %pov3x = zext i64 %pov3 to i128\n";
        $out .= "  %psum01 = add i128 %pb0s, %pb2s\n";
        $out .= "  %psum = add i128 %psum01, %pov3x\n";
        $out .= "  %plo = trunc i128 %psum to i64\n";
        $out .= "  %psumhi = lshr i128 %psum, 64\n";
        $out .= "  %phi = trunc i128 %psumhi to i64\n";
        $out .= "  br label %merge\n";

        // ── computeInvPow5(idx) ──
        $out .= "invb:\n";
        $out .= "  %iidx25 = add i64 %idx, 25\n";
        $out .= "  %ibase = udiv i64 %iidx25, 26\n";
        $out .= "  %ibase2 = mul i64 %ibase, 26\n";
        $out .= "  %ioff = sub i64 %ibase2, %idx\n";
        $out .= "  %ibi = mul i64 %ibase, 2\n";
        $out .= "  %ilop = getelementptr [30 x i64], ptr @.ryu.is2, i64 0, i64 %ibi\n";
        $out .= "  %imullo = load i64, ptr %ilop\n";
        $out .= "  %ibi1 = add i64 %ibi, 1\n";
        $out .= "  %ihip = getelementptr [30 x i64], ptr @.ryu.is2, i64 0, i64 %ibi1\n";
        $out .= "  %imulhi = load i64, ptr %ihip\n";
        $out .= "  %ioff0 = icmp eq i64 %ioff, 0\n";
        $out .= "  br i1 %ioff0, label %idone0, label %icomp\n";
        $out .= "idone0:\n  br label %merge\n";
        $out .= "icomp:\n";
        $out .= "  %im5p = getelementptr [26 x i64], ptr @.ryu.p5tab, i64 0, i64 %ioff\n";
        $out .= "  %im5 = load i64, ptr %im5p\n";
        $out .= "  %im5x = zext i64 %im5 to i128\n";
        $out .= "  %imullo1 = sub i64 %imullo, 1\n";
        $out .= "  %imullox = zext i64 %imullo1 to i128\n";
        $out .= "  %imulhix = zext i64 %imulhi to i128\n";
        $out .= "  %ib0 = mul i128 %im5x, %imullox\n";
        $out .= "  %ib2 = mul i128 %im5x, %imulhix\n";
        $out .= "  %ipbm = mul i64 %ibase2, 1217359\n";
        $out .= "  %ipbs = lshr i64 %ipbm, 19\n";
        $out .= "  %ipb = add i64 %ipbs, 1\n";
        $out .= "  %ipim = mul i64 %idx, 1217359\n";
        $out .= "  %ipis = lshr i64 %ipim, 19\n";
        $out .= "  %ipi = add i64 %ipis, 1\n";
        $out .= "  %idelta = sub i64 %ipb, %ipi\n";
        $out .= "  %ideltax = zext i64 %idelta to i128\n";
        $out .= "  %ib0s = lshr i128 %ib0, %ideltax\n";
        $out .= "  %ish = sub i64 64, %idelta\n";
        $out .= "  %ishx = zext i64 %ish to i128\n";
        $out .= "  %ib2s = shl i128 %ib2, %ishx\n";
        $out .= "  %iw = udiv i64 %idx, 16\n";
        $out .= "  %iwp = getelementptr [22 x i32], ptr @.ryu.ioff, i64 0, i64 %iw\n";
        $out .= "  %iw32 = load i32, ptr %iwp\n";
        $out .= "  %iw64 = zext i32 %iw32 to i64\n";
        $out .= "  %ir = urem i64 %idx, 16\n";
        $out .= "  %irs = mul i64 %ir, 2\n";
        $out .= "  %iov = lshr i64 %iw64, %irs\n";
        $out .= "  %iov3 = and i64 %iov, 3\n";
        $out .= "  %iov3x = zext i64 %iov3 to i128\n";
        $out .= "  %isum01 = add i128 %ib0s, %ib2s\n";
        $out .= "  %isumc = add i128 %isum01, 1\n";
        $out .= "  %isum = add i128 %isumc, %iov3x\n";
        $out .= "  %ilo = trunc i128 %isum to i64\n";
        $out .= "  %isumhi = lshr i128 %isum, 64\n";
        $out .= "  %ihi = trunc i128 %isumhi to i64\n";
        $out .= "  br label %merge\n";

        // ── mulShift64(m, {lo,hi}, j) ──
        $out .= "merge:\n";
        $out .= "  %lo = phi i64 [%pmullo, %pdone0], [%plo, %pcomp], [%imullo, %idone0], [%ilo, %icomp]\n";
        $out .= "  %hi = phi i64 [%pmulhi, %pdone0], [%phi, %pcomp], [%imulhi, %idone0], [%ihi, %icomp]\n";
        $out .= "  %mx = zext i64 %m to i128\n";
        $out .= "  %lox = zext i64 %lo to i128\n";
        $out .= "  %hix = zext i64 %hi to i128\n";
        $out .= "  %b0 = mul i128 %mx, %lox\n";
        $out .= "  %b2 = mul i128 %mx, %hix\n";
        $out .= "  %b0s = lshr i128 %b0, 64\n";
        $out .= "  %sum = add i128 %b0s, %b2\n";
        $out .= "  %jm = sub i64 %j, 64\n";
        $out .= "  %jmx = zext i64 %jm to i128\n";
        $out .= "  %rsh = lshr i128 %sum, %jmx\n";
        $out .= "  %res = trunc i128 %rsh to i64\n";
        $out .= "  ret i64 %res\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * Native json_encode runtime. A recursive `@__mir_json_app(ptr* slot, i64
     * cell)` appends into the buffer held at `*slot`, growing via
     * `@__mir_json_reserve` (str_alloc + memcpy + release old). Cell tag is the
     * NaN-box nibble `(cell>>48)&0xF`: 1/5=int 2=bool 3=null 4=string 7=array,
     * else (untagged) = float; any other tag falls back to the PHP encoder.
     * array_is_list is replicated (PACKED ⇒ list; else all int keys == index)
     * to byte-match the reference. Fixed tokens are raw byte constants (no
     * string-pool interning, which would land too late in the preamble).
     */
    public function jsonEnc(): string
    {
        $M = '281474976710655';           // PAYLOAD_MASK
        $T = '-4503599627370496';         // tagged threshold (0xFFF0000000000000)
        $out  = "\n@.jkw.true = private unnamed_addr constant [5 x i8] c\"true\\00\", align 1\n";
        $out .= "@.jkw.false = private unnamed_addr constant [6 x i8] c\"false\\00\", align 1\n";
        $out .= "@.jkw.null = private unnamed_addr constant [5 x i8] c\"null\\00\", align 1\n";

        // reserve room for %extra more content bytes (+1 NUL); grow if needed.
        $out .= "\ndefine ptr @__mir_json_reserve(ptr %slotp, i64 %extra) {\n";
        $out .= "entry:\n";
        $out .= "  %buf = load ptr, ptr %slotp\n";
        $out .= "  %lenp = getelementptr inbounds i8, ptr %buf, i64 -16\n";
        $out .= "  %len = load i64, ptr %lenp\n";
        $out .= "  %capp = getelementptr inbounds i8, ptr %buf, i64 -24\n";
        $out .= "  %cap = load i64, ptr %capp\n";
        $out .= "  %need = add i64 %len, %extra\n";
        $out .= "  %need1 = add i64 %need, 1\n";
        $out .= "  %fits = icmp ule i64 %need1, %cap\n";
        $out .= "  br i1 %fits, label %ok, label %grow\n";
        $out .= "ok:\n  ret ptr %buf\n";
        $out .= "grow:\n";
        $out .= "  %nc = shl i64 %need1, 1\n";
        $out .= "  %nb = call ptr @__mir_str_alloc(i64 %nc)\n";
        $out .= "  call ptr @memcpy(ptr %nb, ptr %buf, i64 %len)\n";
        $out .= "  call void @__mir_str_set_len(ptr %nb, i64 %len)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %buf)\n";
        $out .= "  store ptr %nb, ptr %slotp\n";
        $out .= "  ret ptr %nb\n}\n";

        // append %n bytes from %src.
        $out .= "\ndefine void @__mir_json_ncat(ptr %slotp, ptr %src, i64 %n) {\n";
        $out .= "entry:\n";
        $out .= "  %buf = call ptr @__mir_json_reserve(ptr %slotp, i64 %n)\n";
        $out .= "  %lenp = getelementptr inbounds i8, ptr %buf, i64 -16\n";
        $out .= "  %len = load i64, ptr %lenp\n";
        $out .= "  %dst = getelementptr inbounds i8, ptr %buf, i64 %len\n";
        $out .= "  call ptr @memcpy(ptr %dst, ptr %src, i64 %n)\n";
        $out .= "  %nl = add i64 %len, %n\n";
        $out .= "  %ep = getelementptr inbounds i8, ptr %buf, i64 %nl\n";
        $out .= "  store i8 0, ptr %ep\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %nl)\n";
        $out .= "  ret void\n}\n";

        // append one byte %c.
        $out .= "\ndefine void @__mir_json_putc(ptr %slotp, i64 %c) {\n";
        $out .= "entry:\n";
        $out .= "  %buf = call ptr @__mir_json_reserve(ptr %slotp, i64 1)\n";
        $out .= "  %lenp = getelementptr inbounds i8, ptr %buf, i64 -16\n";
        $out .= "  %len = load i64, ptr %lenp\n";
        $out .= "  %dst = getelementptr inbounds i8, ptr %buf, i64 %len\n";
        $out .= "  %cb = trunc i64 %c to i8\n";
        $out .= "  store i8 %cb, ptr %dst\n";
        $out .= "  %nl = add i64 %len, 1\n";
        $out .= "  %ep = getelementptr inbounds i8, ptr %buf, i64 %nl\n";
        $out .= "  store i8 0, ptr %ep\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %nl)\n";
        $out .= "  ret void\n}\n";

        // append decimal of %v straight into the buffer (no temp string).
        $out .= "\ndefine void @__mir_json_int(ptr %slotp, i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %n = call i64 @__mir_int_len(i64 %v)\n";
        $out .= "  %buf = call ptr @__mir_json_reserve(ptr %slotp, i64 %n)\n";
        $out .= "  %lenp = getelementptr inbounds i8, ptr %buf, i64 -16\n";
        $out .= "  %len = load i64, ptr %lenp\n";
        $out .= "  call void @__mir_int_fmt(ptr %buf, i64 %len, i64 %v)\n";
        $out .= "  %nl = add i64 %len, %n\n";
        $out .= "  %ep = getelementptr inbounds i8, ptr %buf, i64 %nl\n";
        $out .= "  store i8 0, ptr %ep\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %nl)\n";
        $out .= "  ret void\n}\n";

        // Append `"<escaped s>"`. Default php flags: `"` `\` `/` and the C0
        // controls escape, and any non-ASCII byte (>=0x80) becomes `\uXXXX`. The
        // hot inline loop handles the ASCII escapes in ONE pass (fast for the
        // common short-word case), writing each byte or its two-char `\x` form.
        // The moment a byte needs UTF-8 decoding (>=0x80) or is a rare control
        // with no short form, it BAILS to the PHP `__mc_json_escape` (which owns
        // the multi-byte + surrogate logic) and rewrites the whole value from the
        // saved offset — the partial inline bytes are simply overwritten (len is
        // only committed at the end).
        $out .= "\ndefine void @__mir_json_estr(ptr %slotp, ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %slen = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %two = shl i64 %slen, 1\n";
        $out .= "  %rsv = add i64 %two, 2\n";
        $out .= "  %buf = call ptr @__mir_json_reserve(ptr %slotp, i64 %rsv)\n";
        $out .= "  %lenp = getelementptr inbounds i8, ptr %buf, i64 -16\n";
        $out .= "  %len0 = load i64, ptr %lenp\n";
        $out .= "  %q0 = getelementptr inbounds i8, ptr %buf, i64 %len0\n";
        $out .= "  store i8 34, ptr %q0\n";
        $out .= "  %j0 = add i64 %len0, 1\n";
        $out .= "  br label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [0, %entry], [%i2, %cont]\n";
        $out .= "  %j = phi i64 [%j0, %entry], [%j2, %cont]\n";
        $out .= "  %done = icmp sge i64 %i, %slen\n";
        $out .= "  br i1 %done, label %fin, label %body\n";
        $out .= "body:\n";
        $out .= "  %sp = getelementptr inbounds i8, ptr %s, i64 %i\n";
        $out .= "  %c = load i8, ptr %sp\n";
        $out .= "  %cz = zext i8 %c to i64\n";
        $out .= "  %is34 = icmp eq i8 %c, 34\n";
        $out .= "  %is92 = icmp eq i8 %c, 92\n";
        $out .= "  %is47 = icmp eq i8 %c, 47\n";
        $out .= "  %is10 = icmp eq i8 %c, 10\n";
        $out .= "  %is9  = icmp eq i8 %c, 9\n";
        $out .= "  %is13 = icmp eq i8 %c, 13\n";
        $out .= "  %is8  = icmp eq i8 %c, 8\n";
        $out .= "  %is12 = icmp eq i8 %c, 12\n";
        // Bail to PHP on a non-ASCII byte or a control with no short form.
        $out .= "  %ge80 = icmp uge i64 %cz, 128\n";
        $out .= "  %lt32 = icmp ult i64 %cz, 32\n";
        $out .= "  %sh1 = or i1 %is10, %is9\n";
        $out .= "  %sh2 = or i1 %sh1, %is13\n";
        $out .= "  %sh3 = or i1 %sh2, %is8\n";
        $out .= "  %short = or i1 %sh3, %is12\n";
        $out .= "  %nshort = xor i1 %short, true\n";
        $out .= "  %rare = and i1 %lt32, %nshort\n";
        $out .= "  %bail = or i1 %ge80, %rare\n";
        $out .= "  br i1 %bail, label %phppath, label %ascii\n";
        $out .= "ascii:\n";
        $out .= "  %e1 = select i1 %is10, i8 110, i8 %c\n";
        $out .= "  %e2 = select i1 %is9,  i8 116, i8 %e1\n";
        $out .= "  %e3 = select i1 %is13, i8 114, i8 %e2\n";
        $out .= "  %e4 = select i1 %is8,  i8 98,  i8 %e3\n";
        $out .= "  %e5 = select i1 %is12, i8 102, i8 %e4\n";
        $out .= "  %o1 = or i1 %is34, %is92\n";
        $out .= "  %o2 = or i1 %o1, %is47\n";
        $out .= "  %o3 = or i1 %o2, %is10\n";
        $out .= "  %o4 = or i1 %o3, %is9\n";
        $out .= "  %o5 = or i1 %o4, %is13\n";
        $out .= "  %o6 = or i1 %o5, %is8\n";
        $out .= "  %spec = or i1 %o6, %is12\n";
        $out .= "  br i1 %spec, label %esc, label %plain\n";
        $out .= "esc:\n";
        $out .= "  %dp = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 92, ptr %dp\n";
        $out .= "  %j1 = add i64 %j, 1\n";
        $out .= "  %dp2 = getelementptr inbounds i8, ptr %buf, i64 %j1\n";
        $out .= "  store i8 %e5, ptr %dp2\n";
        $out .= "  %je = add i64 %j1, 1\n";
        $out .= "  br label %cont\n";
        $out .= "plain:\n";
        $out .= "  %dp3 = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 %c, ptr %dp3\n";
        $out .= "  %jp = add i64 %j, 1\n";
        $out .= "  br label %cont\n";
        $out .= "cont:\n";
        $out .= "  %j2 = phi i64 [%je, %esc], [%jp, %plain]\n";
        $out .= "  %i2 = add i64 %i, 1\n";
        $out .= "  br label %loop\n";
        $out .= "fin:\n";
        $out .= "  %qc = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 34, ptr %qc\n";
        $out .= "  %jend = add i64 %j, 1\n";
        $out .= "  %ep2 = getelementptr inbounds i8, ptr %buf, i64 %jend\n";
        $out .= "  store i8 0, ptr %ep2\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %jend)\n";
        $out .= "  ret void\n";
        // Bail: escape the whole value in PHP, rewrite from the saved offset.
        $out .= "phppath:\n";
        $out .= "  %si64 = ptrtoint ptr %s to i64\n";
        $out .= "  %ei = call i64 @manticore___mc_json_escape(i64 %si64)\n";
        $out .= "  %esp = inttoptr i64 %ei to ptr\n";
        $out .= "  %elen = call i64 @__mir_strlen(ptr %esp)\n";
        $out .= "  %ersv = add i64 %elen, 2\n";
        $out .= "  %ebuf = call ptr @__mir_json_reserve(ptr %slotp, i64 %ersv)\n";
        $out .= "  %eq0 = getelementptr inbounds i8, ptr %ebuf, i64 %len0\n";
        $out .= "  store i8 34, ptr %eq0\n";
        $out .= "  %edst1 = getelementptr inbounds i8, ptr %eq0, i64 1\n";
        $out .= "  call ptr @memcpy(ptr %edst1, ptr %esp, i64 %elen)\n";
        $out .= "  %eqjp = add i64 %len0, 1\n";
        $out .= "  %eqj = add i64 %eqjp, %elen\n";
        $out .= "  %eqc = getelementptr inbounds i8, ptr %ebuf, i64 %eqj\n";
        $out .= "  store i8 34, ptr %eqc\n";
        $out .= "  %eend = add i64 %eqj, 1\n";
        $out .= "  %enul = getelementptr inbounds i8, ptr %ebuf, i64 %eend\n";
        $out .= "  store i8 0, ptr %enul\n";
        $out .= "  call void @__mir_str_set_len(ptr %ebuf, i64 %eend)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %esp)\n";
        $out .= "  ret void\n}\n";

        // recursive walker.
        $out .= "\ndefine void @__mir_json_app(ptr %slotp, i64 %cell) {\n";
        $out .= "entry:\n";
        $out .= "  %tagged = icmp ugt i64 %cell, $T\n";
        $out .= "  br i1 %tagged, label %istag, label %isfloat\n";
        $out .= "isfloat:\n";
        // Shortest round-tripping decimal via the Ryu-backed PHP formatter
        // (__mc_dtoa_bits); the cell already holds the raw double bits. Replaces
        // the `%.14g` snprintf, which was slow AND not shortest.
        $out .= "  %fsi = call i64 @manticore___mc_dtoa_bits(i64 %cell)\n";
        $out .= "  %fs = inttoptr i64 %fsi to ptr\n";
        $out .= "  %fn = call i64 @__mir_strlen(ptr %fs)\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr %fs, i64 %fn)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %fs)\n";
        $out .= "  ret void\n";
        $out .= "istag:\n";
        $out .= "  %sh = lshr i64 %cell, 48\n";
        $out .= "  %nib = and i64 %sh, 15\n";
        $out .= "  %isint1 = icmp eq i64 %nib, 1\n";
        $out .= "  %isint5 = icmp eq i64 %nib, 5\n";
        $out .= "  %isint = or i1 %isint1, %isint5\n";
        $out .= "  br i1 %isint, label %tint, label %t2\n";
        $out .= "tint:\n";
        $out .= "  %iv = call i64 @__manticore_unbox_int(i64 %cell)\n";
        $out .= "  call void @__mir_json_int(ptr %slotp, i64 %iv)\n";
        $out .= "  ret void\n";
        $out .= "t2:\n";
        $out .= "  %isbool = icmp eq i64 %nib, 2\n";
        $out .= "  br i1 %isbool, label %tbool, label %t3\n";
        $out .= "tbool:\n";
        $out .= "  %b = and i64 %cell, 1\n";
        $out .= "  %istrue = icmp ne i64 %b, 0\n";
        $out .= "  br i1 %istrue, label %btrue, label %bfalse\n";
        $out .= "btrue:\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr @.jkw.true, i64 4)\n";
        $out .= "  ret void\n";
        $out .= "bfalse:\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr @.jkw.false, i64 5)\n";
        $out .= "  ret void\n";
        $out .= "t3:\n";
        $out .= "  %isnull = icmp eq i64 %nib, 3\n";
        $out .= "  br i1 %isnull, label %tnull, label %t4\n";
        $out .= "tnull:\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr @.jkw.null, i64 4)\n";
        $out .= "  ret void\n";
        $out .= "t4:\n";
        $out .= "  %isstr = icmp eq i64 %nib, 4\n";
        $out .= "  br i1 %isstr, label %tstr, label %t7\n";
        $out .= "tstr:\n";
        $out .= "  %spp = and i64 %cell, $M\n";
        $out .= "  %sptr = inttoptr i64 %spp to ptr\n";
        $out .= "  call void @__mir_json_estr(ptr %slotp, ptr %sptr)\n";
        $out .= "  ret void\n";
        $out .= "t7:\n";
        $out .= "  %isarr = icmp eq i64 %nib, 7\n";
        $out .= "  br i1 %isarr, label %tarr, label %tobj\n";
        $out .= "tobj:\n";
        $out .= "  %osi = call i64 @manticore___mc_json_enc(i64 %cell)\n";
        $out .= "  %os = inttoptr i64 %osi to ptr\n";
        $out .= "  %on = call i64 @__mir_strlen(ptr %os)\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr %os, i64 %on)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %os)\n";
        $out .= "  ret void\n";
        $out .= "tarr:\n";
        $out .= "  %arr0 = and i64 %cell, $M\n";
        $out .= "  %arr = inttoptr i64 %arr0 to ptr\n";
        $out .= "  %alen = load i64, ptr %arr\n";
        $out .= "  %hashed = call i64 @__mir_array_is_hashed(ptr %arr)\n";
        $out .= "  %ishash = icmp ne i64 %hashed, 0\n";
        $out .= "  br i1 %ishash, label %chklist, label %aslist\n";
        $out .= "chklist:\n";
        $out .= "  br label %kl\n";
        $out .= "kl:\n";
        $out .= "  %ki = phi i64 [0, %chklist], [%ki2, %kcont]\n";
        $out .= "  %kdone = icmp sge i64 %ki, %alen\n";
        $out .= "  br i1 %kdone, label %aslist, label %kbody\n";
        $out .= "kbody:\n";
        $out .= "  %kc = call i64 @__mir_array_key_cell_at(ptr %arr, i64 %ki)\n";
        $out .= "  %ktag = icmp ugt i64 %kc, $T\n";
        $out .= "  br i1 %ktag, label %kchknib, label %asobj\n";
        $out .= "kchknib:\n";
        $out .= "  %ksh = lshr i64 %kc, 48\n";
        $out .= "  %knib = and i64 %ksh, 15\n";
        $out .= "  %kisint = icmp eq i64 %knib, 1\n";
        $out .= "  br i1 %kisint, label %kchkidx, label %asobj\n";
        $out .= "kchkidx:\n";
        $out .= "  %kiv = call i64 @__manticore_unbox_int(i64 %kc)\n";
        $out .= "  %keq = icmp eq i64 %kiv, %ki\n";
        $out .= "  br i1 %keq, label %kcont, label %asobj\n";
        $out .= "kcont:\n";
        $out .= "  %ki2 = add i64 %ki, 1\n";
        $out .= "  br label %kl\n";
        $out .= "aslist:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 91)\n";
        $out .= "  br label %ll\n";
        $out .= "ll:\n";
        $out .= "  %li = phi i64 [0, %aslist], [%li2, %lcont]\n";
        $out .= "  %ldone = icmp sge i64 %li, %alen\n";
        $out .= "  br i1 %ldone, label %lend, label %lbody\n";
        $out .= "lbody:\n";
        $out .= "  %lfirst = icmp eq i64 %li, 0\n";
        $out .= "  br i1 %lfirst, label %lskip, label %lcomma\n";
        $out .= "lcomma:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 44)\n";
        $out .= "  br label %lskip\n";
        $out .= "lskip:\n";
        $out .= "  %lv = call i64 @__mir_array_value_at(ptr %arr, i64 %li)\n";
        $out .= "  call void @__mir_json_app(ptr %slotp, i64 %lv)\n";
        $out .= "  br label %lcont\n";
        $out .= "lcont:\n";
        $out .= "  %li2 = add i64 %li, 1\n";
        $out .= "  br label %ll\n";
        $out .= "lend:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 93)\n";
        $out .= "  ret void\n";
        $out .= "asobj:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 123)\n";
        $out .= "  br label %ol\n";
        $out .= "ol:\n";
        $out .= "  %oi = phi i64 [0, %asobj], [%oi2, %ocont]\n";
        $out .= "  %odone = icmp sge i64 %oi, %alen\n";
        $out .= "  br i1 %odone, label %oend, label %obody\n";
        $out .= "obody:\n";
        $out .= "  %ofirst = icmp eq i64 %oi, 0\n";
        $out .= "  br i1 %ofirst, label %oskip, label %ocomma\n";
        $out .= "ocomma:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 44)\n";
        $out .= "  br label %oskip\n";
        $out .= "oskip:\n";
        $out .= "  %okc = call i64 @__mir_array_key_cell_at(ptr %arr, i64 %oi)\n";
        $out .= "  %oksh = lshr i64 %okc, 48\n";
        $out .= "  %oknib = and i64 %oksh, 15\n";
        $out .= "  %okstr = icmp eq i64 %oknib, 4\n";
        $out .= "  br i1 %okstr, label %okS, label %okI\n";
        $out .= "okS:\n";
        $out .= "  %oksp = and i64 %okc, $M\n";
        $out .= "  %oksptr = inttoptr i64 %oksp to ptr\n";
        $out .= "  call void @__mir_json_estr(ptr %slotp, ptr %oksptr)\n";
        $out .= "  br label %okdone\n";
        $out .= "okI:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 34)\n";
        $out .= "  %okiv = call i64 @__manticore_unbox_int(i64 %okc)\n";
        $out .= "  call void @__mir_json_int(ptr %slotp, i64 %okiv)\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 34)\n";
        $out .= "  br label %okdone\n";
        $out .= "okdone:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 58)\n";
        $out .= "  %ov = call i64 @__mir_array_value_at(ptr %arr, i64 %oi)\n";
        $out .= "  call void @__mir_json_app(ptr %slotp, i64 %ov)\n";
        $out .= "  br label %ocont\n";
        $out .= "ocont:\n";
        $out .= "  %oi2 = add i64 %oi, 1\n";
        $out .= "  br label %ol\n";
        $out .= "oend:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 125)\n";
        $out .= "  ret void\n}\n";

        // entry: alloc a buffer, walk, return it.
        $out .= "\ndefine ptr @__mir_json_enc(i64 %cell) {\n";
        $out .= "entry:\n";
        $out .= "  %buf0 = call ptr @__mir_str_alloc(i64 16)\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf0, i64 0)\n";
        $out .= "  %slot = alloca ptr\n";
        $out .= "  store ptr %buf0, ptr %slot\n";
        $out .= "  call void @__mir_json_app(ptr %slot, i64 %cell)\n";
        $out .= "  %r = load ptr, ptr %slot\n";
        $out .= "  ret ptr %r\n}\n";
        return $out;
    }

    /** Runtime: replace every non-overlapping `%se` in `%sj` with `%rp`, left
     * to right (PHP str_replace semantics; the replacement is never rescanned).
     * Always returns a FRESH string. Empty/absent search → a plain copy. */
    public function strReplaceOne(): string
    {
        // Header-aware lengths (cached len@-16) — never a libc O(n) rescan of an
        // already-headered manticore string. A single-byte search (%is1, the
        // overwhelmingly common explode/implode delimiter) probes with memchr
        // instead of strstr (no substring-match machinery) in both passes.
        $out  = "\ndefine ptr @__mir_str_replace_one(ptr %se, ptr %rp, ptr %sj) {\n";
        $out .= "entry:\n";
        $out .= "  %slen = call i64 @__mir_strlen(ptr %se)\n";
        $out .= "  %rlen = call i64 @__mir_strlen(ptr %rp)\n";
        $out .= "  %jlen = call i64 @__mir_strlen(ptr %sj)\n";
        // Empty search or search longer than subject → copy subject verbatim.
        $out .= "  %semp = icmp eq i64 %slen, 0\n";
        $out .= "  %stoolong = icmp ugt i64 %slen, %jlen\n";
        $out .= "  %nomatchposs = or i1 %semp, %stoolong\n";
        $out .= "  br i1 %nomatchposs, label %copy, label %prep\n";
        $out .= "prep:\n";
        $out .= "  %is1 = icmp eq i64 %slen, 1\n";
        $out .= "  %c0b = load i8, ptr %se\n";
        $out .= "  %c0 = zext i8 %c0b to i32\n";
        $out .= "  br label %cloop\n";
        // ── Pass 1: count matches ──
        $out .= "cloop:\n";
        $out .= "  %cpos = phi i64 [0, %prep], [%cpos2, %chit]\n";
        $out .= "  %ccnt = phi i64 [0, %prep], [%ccnt1, %chit]\n";
        $out .= "  %cfrom = getelementptr inbounds i8, ptr %sj, i64 %cpos\n";
        $out .= "  br i1 %is1, label %cm, label %cs\n";
        $out .= "cm:\n";
        $out .= "  %crem = sub i64 %jlen, %cpos\n";
        $out .= "  %cmr = call ptr @memchr(ptr %cfrom, i32 %c0, i64 %crem)\n";
        $out .= "  br label %cj\n";
        $out .= "cs:\n";
        $out .= "  %csr = call ptr @strstr(ptr %cfrom, ptr %se)\n";
        $out .= "  br label %cj\n";
        $out .= "cj:\n";
        $out .= "  %cf = phi ptr [%cmr, %cm], [%csr, %cs]\n";
        $out .= "  %cnull = icmp eq ptr %cf, null\n";
        $out .= "  br i1 %cnull, label %sized, label %chit\n";
        $out .= "chit:\n";
        $out .= "  %cfi = ptrtoint ptr %cf to i64\n";
        $out .= "  %sji = ptrtoint ptr %sj to i64\n";
        $out .= "  %choff = sub i64 %cfi, %sji\n";
        $out .= "  %cpos2 = add i64 %choff, %slen\n";
        $out .= "  %ccnt1 = add i64 %ccnt, 1\n";
        $out .= "  br label %cloop\n";
        // outlen = jlen + count*rlen - count*slen ; alloc outlen+1
        $out .= "sized:\n";
        $out .= "  %crep = mul i64 %ccnt, %rlen\n";
        $out .= "  %csea = mul i64 %ccnt, %slen\n";
        $out .= "  %o1 = add i64 %jlen, %crep\n";
        $out .= "  %outlen = sub i64 %o1, %csea\n";
        $out .= "  %ocap = add i64 %outlen, 1\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %ocap)\n";
        // ── Pass 2: fill ──
        $out .= "  br label %floop\n";
        $out .= "floop:\n";
        $out .= "  %src = phi i64 [0, %sized], [%src2, %fhit]\n";
        $out .= "  %dst = phi i64 [0, %sized], [%dst2, %fhit]\n";
        $out .= "  %ffrom = getelementptr inbounds i8, ptr %sj, i64 %src\n";
        $out .= "  br i1 %is1, label %fm, label %fs\n";
        $out .= "fm:\n";
        $out .= "  %frem = sub i64 %jlen, %src\n";
        $out .= "  %fmr = call ptr @memchr(ptr %ffrom, i32 %c0, i64 %frem)\n";
        $out .= "  br label %fj\n";
        $out .= "fs:\n";
        $out .= "  %fsr = call ptr @strstr(ptr %ffrom, ptr %se)\n";
        $out .= "  br label %fj\n";
        $out .= "fj:\n";
        $out .= "  %ff = phi ptr [%fmr, %fm], [%fsr, %fs]\n";
        $out .= "  %fnull = icmp eq ptr %ff, null\n";
        $out .= "  br i1 %fnull, label %tail, label %fhit\n";
        $out .= "fhit:\n";
        $out .= "  %ffi = ptrtoint ptr %ff to i64\n";
        $out .= "  %sji2 = ptrtoint ptr %sj to i64\n";
        $out .= "  %fhoff = sub i64 %ffi, %sji2\n";
        $out .= "  %chunk = sub i64 %fhoff, %src\n";
        // copy subject[src .. hit) then the replacement
        $out .= "  %dp = getelementptr inbounds i8, ptr %buf, i64 %dst\n";
        $out .= "  call ptr @memcpy(ptr %dp, ptr %ffrom, i64 %chunk)\n";
        $out .= "  %dst1 = add i64 %dst, %chunk\n";
        $out .= "  %dp2 = getelementptr inbounds i8, ptr %buf, i64 %dst1\n";
        $out .= "  call ptr @memcpy(ptr %dp2, ptr %rp, i64 %rlen)\n";
        $out .= "  %dst2 = add i64 %dst1, %rlen\n";
        $out .= "  %src2 = add i64 %fhoff, %slen\n";
        $out .= "  br label %floop\n";
        // tail: copy subject[src .. jlen)
        $out .= "tail:\n";
        $out .= "  %rem = sub i64 %jlen, %src\n";
        $out .= "  %dpt = getelementptr inbounds i8, ptr %buf, i64 %dst\n";
        $out .= "  call ptr @memcpy(ptr %dpt, ptr %ffrom, i64 %rem)\n";
        $out .= "  %fin = add i64 %dst, %rem\n";
        $out .= "  %np = getelementptr inbounds i8, ptr %buf, i64 %fin\n";
        $out .= "  store i8 0, ptr %np\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %fin)\n";
        $out .= "  ret ptr %buf\n";
        // ── copy path (empty/too-long search) ──
        $out .= "copy:\n";
        $out .= "  %ccap = add i64 %jlen, 1\n";
        $out .= "  %cbuf = call ptr @__mir_str_alloc(i64 %ccap)\n";
        $out .= "  call ptr @memcpy(ptr %cbuf, ptr %sj, i64 %jlen)\n";
        $out .= "  %cnp = getelementptr inbounds i8, ptr %cbuf, i64 %jlen\n";
        $out .= "  store i8 0, ptr %cnp\n";
        $out .= "  call void @__mir_str_set_len(ptr %cbuf, i64 %jlen)\n";
        $out .= "  ret ptr %cbuf\n";
        $out .= "}\n";
        return $out;
    }
}
