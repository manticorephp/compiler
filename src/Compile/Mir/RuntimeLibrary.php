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

        // `$s[$i] = $c` — returns a NEW headered string with byte %ix set to the
        // first byte of %chs. Growing past the end pads the gap with spaces
        // (PHP). Negative offset counts from the end; still-negative → no-op copy.
        $out .= "\ndefine ptr @__mir_str_set_char(ptr %s, i64 %i, ptr %chs) {\nentry:\n";
        $out .= "  %len = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %neg = icmp slt i64 %i, 0\n";
        $out .= "  %iadj = add i64 %i, %len\n";
        $out .= "  %ix = select i1 %neg, i64 %iadj, i64 %i\n";
        $out .= "  %bad = icmp slt i64 %ix, 0\n";
        $out .= "  br i1 %bad, label %nop, label %go\n";
        $out .= "nop:\n";
        $out .= "  %cpy = call ptr @__mir_str_new(ptr %s, i64 %len)\n";
        $out .= "  ret ptr %cpy\n";
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

        // append `\"<escaped s>\"`, escaping written inline (single up-front
        // reserve so the buffer never grows mid-scan). Escape set matches
        // __mir_json_escape: \" \\ \\n \\t \\r \\b \\f.
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
        $out .= "  %is34 = icmp eq i8 %c, 34\n";
        $out .= "  %is92 = icmp eq i8 %c, 92\n";
        $out .= "  %is10 = icmp eq i8 %c, 10\n";
        $out .= "  %is9  = icmp eq i8 %c, 9\n";
        $out .= "  %is13 = icmp eq i8 %c, 13\n";
        $out .= "  %is8  = icmp eq i8 %c, 8\n";
        $out .= "  %is12 = icmp eq i8 %c, 12\n";
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
        $out .= "  %qc = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 34, ptr %qc\n";
        $out .= "  %jend = add i64 %j, 1\n";
        $out .= "  %ep2 = getelementptr inbounds i8, ptr %buf, i64 %jend\n";
        $out .= "  store i8 0, ptr %ep2\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %jend)\n";
        $out .= "  ret void\n}\n";

        // recursive walker.
        $out .= "\ndefine void @__mir_json_app(ptr %slotp, i64 %cell) {\n";
        $out .= "entry:\n";
        $out .= "  %tagged = icmp ugt i64 %cell, $T\n";
        $out .= "  br i1 %tagged, label %istag, label %isfloat\n";
        $out .= "isfloat:\n";
        $out .= "  %d = bitcast i64 %cell to double\n";
        $out .= "  %fs = call ptr @__mir_float_to_str(double %d)\n";
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
        $out  = "\ndefine ptr @__mir_str_replace_one(ptr %se, ptr %rp, ptr %sj) {\n";
        $out .= "entry:\n";
        $out .= "  %slen = call i64 @strlen(ptr %se)\n";
        $out .= "  %rlen = call i64 @strlen(ptr %rp)\n";
        $out .= "  %jlen = call i64 @strlen(ptr %sj)\n";
        // Empty search or search longer than subject → copy subject verbatim.
        $out .= "  %semp = icmp eq i64 %slen, 0\n";
        $out .= "  %stoolong = icmp ugt i64 %slen, %jlen\n";
        $out .= "  %nomatchposs = or i1 %semp, %stoolong\n";
        $out .= "  br i1 %nomatchposs, label %copy, label %count\n";
        // ── Pass 1: count matches ──
        $out .= "count:\n";
        $out .= "  br label %cloop\n";
        $out .= "cloop:\n";
        $out .= "  %cpos = phi i64 [0, %count], [%cpos2, %chit]\n";
        $out .= "  %ccnt = phi i64 [0, %count], [%ccnt1, %chit]\n";
        $out .= "  %cfrom = getelementptr inbounds i8, ptr %sj, i64 %cpos\n";
        $out .= "  %cf = call ptr @strstr(ptr %cfrom, ptr %se)\n";
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
        $out .= "  %ff = call ptr @strstr(ptr %ffrom, ptr %se)\n";
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
