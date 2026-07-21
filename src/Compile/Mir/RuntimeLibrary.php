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
     * `{ class_id, drop_fn, rmeta }` descriptor, or 0 for an unknown class.
     */
    public function descSlotValue(?\Compile\Mir\ClassDef $cd): string
    {
        if ($cd === null || $cd->isStruct) { return '0'; }
        return 'ptrtoint (ptr @__mir_cd_' . (string)$cd->classId . ' to i64)';
    }

    /**
     * The LLVM struct type of a class descriptor. ONE spelling, because
     * `@__mir_cd_<id>` is `linkonce_odr` and coalesces BY NAME: two emission
     * sites that disagree on the type define one symbol two ways, and the
     * linker keeps whichever it saw first — the two-bodies-one-symbol trap.
     * There are exactly two emitters ({@see Passes\EmitLlvmRuntime} for the
     * ordinary path, {@see Passes\EmitLlvm} for the enum singleton path) and
     * both MUST route through here.
     *
     * Layout is owned by {@see \Compile\MemoryAbi}: class_id@0, drop_fn@8,
     * rmeta@16.
     */
    public static function descriptorType(): string
    {
        return '{ i64, ptr, ptr }';
    }

    /**
     * The full `@__mir_cd_<id> = linkonce_odr global …` definition.
     *
     * `$rmetaFld` is `ptr null` for a class no reflection reaches — the opt-in
     * gate. The field costs 8 rodata bytes per class either way; appending it
     * leaves class_id@0 and drop_fn@8 where they were, so no reader moves.
     */
    public static function descriptorGlobal(int $id, string $dropFld, string $rmetaFld = 'ptr null'): string
    {
        return '@__mir_cd_' . (string)$id . ' = linkonce_odr global ' . self::descriptorType()
            . ' { i64 ' . (string)$id . ', ' . $dropFld . ', ' . $rmetaFld . " }\n";
    }

    /**
     * The LLVM struct type of the reflection metadata block. Same
     * one-spelling rule as {@see descriptorType}: `@__mc_rmeta_<id>` is
     * `linkonce_odr` and coalesces BY NAME.
     *
     * Layout owned by {@see \Compile\MemoryAbi}: name@0, flags@8, parent_id@16.
     */
    public static function rmetaType(): string
    {
        return '{ ptr, i64, i64, ptr, i64, ptr, i64, ptr, ptr }';
    }

    /** One method/property row:
     *  `{ ptr name, i64 flags, ptr tramp, i64 arity, i64 nparams, ptr params }`. */
    public static function rmetaRowType(): string
    {
        return '{ ptr, i64, ptr, i64, i64, ptr }';
    }

    /** One parameter entry: `{ ptr name, ptr type, i64 flags }`. */
    public static function rmetaParamType(): string
    {
        return '{ ptr, ptr, i64 }';
    }

    /**
     * A method's parameter table: `[N x { ptr name, ptr type, i64 flags }]`, plus
     * the `{ count, ptr }` pair. Empty ⇒ `{ i64 0, ptr null }` (a no-arg method).
     *
     * @param string[] $namesIr per param: the name's data-ptr IR
     * @param string[] $typesIr per param: the type-name data-ptr IR, or 'null'
     * @param int[]    $flags   per param: the packed RMETA_PARAM_* word
     * @return string[] [globalDef, tableSym|'null'] — both STRINGS (the count is
     *   `count($params)`, computed by the caller). A heterogeneous return tuple
     *   would erase to vec[cell] and mis-decode under the native self-host.
     */
    public static function rmetaParamTable(string $sym, array $namesIr, array $typesIr, array $flags): array
    {
        $n = \count($flags);
        if ($n === 0) { return ['', 'null']; }
        $items = [];
        for ($i = 0; $i < $n; $i = $i + 1) {
            $items[] = self::rmetaParamType() . ' { ptr ' . $namesIr[$i] . ', ptr ' . $typesIr[$i]
                 . ', i64 ' . (string)$flags[$i] . ' }';
        }
        $def = $sym . ' = linkonce_odr constant [' . (string)$n . ' x ' . self::rmetaParamType()
             . '] [' . \implode(', ', $items) . "]\n";
        return [$def, $sym];
    }

    /**
     * A method or property table: `[N x { ptr name, i64 flags, ptr tramp, i64
     * arity }]`, plus the `{ count, ptr }` pair that addresses it.
     *
     * An empty table emits no global and yields `{ i64 0, ptr null }` — a reader
     * must check the count first, never dereference the pointer blind.
     *
     * `$rowsIr` are the fully-built `ROWTYPE { … }` bodies, one per row, in order
     * — the CALLER interpolates each row's fields from its concrete locals.
     * Deliberate: passing six index-parallel typed arrays here made rmetaTable
     * read `$nparams[$i]` etc. off ERASED `array` params (a static method is not
     * monomorphized), which mis-decoded an int element under the native
     * self-host (an empty `i64 ,` in the emitted row). A single `string[]` of
     * pre-built rows keeps every field read on the caller's concrete values.
     *
     * @param string[] $rowsIr per row: the full `ROWTYPE { … }` body
     * @return string[] [globalDef, countAndPtrFields]
     */
    public static function rmetaTable(string $sym, array $rowsIr): array
    {
        $n = \count($rowsIr);
        if ($n === 0) { return ['', 'i64 0, ptr null']; }
        $def = $sym . ' = linkonce_odr constant [' . (string)$n . ' x ' . self::rmetaRowType()
             . '] [' . \implode(', ', $rowsIr) . "]\n";
        return [$def, 'i64 ' . (string)$n . ', ptr ' . $sym];
    }

    /** One method/property row body, fields interpolated by the caller. */
    public static function rmetaRow(string $nameIr, int $flags, string $tramp, int $arity, int $nparams, string $paramsIr): string
    {
        return self::rmetaRowType() . ' { ptr ' . $nameIr . ', i64 ' . (string)$flags
             . ', ptr ' . $tramp . ', i64 ' . (string)$arity
             . ', i64 ' . (string)$nparams . ', ptr ' . $paramsIr . ' }';
    }

    /**
     * The full `@__mc_rmeta_<id> = linkonce_odr constant …` definition.
     *
     * `constant`, not `global`: nothing mutates it, so it lands in rodata and
     * the linker may share it.
     *
     * Keyed by class id, and every field is derived from the class itself, so
     * every module that emits it emits the SAME bytes — the ODR invariant this
     * epic rests on. Never fill this from module-local information.
     *
     * `$parentId` is an id rather than a pointer because the parent's rmeta can
     * live in another object file ({@see \Compile\MemoryAbi::RMETA_PARENT_ID_OFFSET}).
     */
    public static function rmetaGlobal(
        string $id,
        string $nameFld,
        int $flags,
        int $parentId,
        string $parentNameFld = 'ptr null',
        string $methodsFlds = 'i64 0, ptr null',
        string $propsFlds = 'i64 0, ptr null',
        string $ctorTrampFld = 'ptr null'
    ): string {
        return '@__mc_rmeta_v2_' . $id . ' = linkonce_odr constant ' . self::rmetaType()
            . ' { ' . $nameFld . ', i64 ' . (string)$flags . ', i64 ' . (string)$parentId
            . ', ' . $parentNameFld . ', ' . $methodsFlds . ', ' . $propsFlds
            . ', ' . $ctorTrampFld . " }\n";
    }

    /** The rmeta pointer field for a descriptor: the class's block, or null
     *  when nothing reflects it (the opt-in gate — Ф1b decides; Ф1a fills all).
     *  `_v2_` since Ф2 widened the layout (rows gained tramp/arity, the struct
     *  gained ctor_tramp) — a version bump so a mismatched object is a link
     *  error, not silent linkonce_odr coalescing onto the wrong shape. */
    public static function rmetaField(int $id): string
    {
        return 'ptr @__mc_rmeta_v2_' . (string)$id;
    }

    /** Registry node: `{ ptr rmeta, ptr next, i64 registered }`. */
    public static function reflNodeType(): string
    {
        return '{ ptr, ptr, i64 }';
    }

    /**
     * One class's registry node + its `@llvm.global_ctors` entry function.
     *
     * `@llvm.global_ctors` is how a name→rmeta lookup exists at all: there is no
     * name-addressable table in the binary otherwise, and the prelude cannot
     * hold a generated one (its bodies are linkonce_odr and shared). It is
     * chosen over a linker section because its IR is byte-identical on Mach-O
     * and ELF, so nothing has to detect the OS at emit time — and `host_os()`
     * rides libc bindings that are empty stubs under the Zend cold seed.
     *
     * **The registration MUST be idempotent, and the guard is not belt-and-braces.**
     * {@see Passes\EmitLlvm::linkonceRuntime} rewrites every preamble `define`
     * to `linkonce_odr`, so this function coalesces to ONE body across objects —
     * but `@llvm.global_ctors` is `appending`, so each object still contributes
     * its own entry pointing at that one body. Two objects ⇒ the ctor runs
     * TWICE. Unguarded, `node->next = head; head = node` run twice makes the
     * node its own successor, and `__mc_refl_find` spins forever on the cycle.
     */
    public static function reflNodeAndCtor(string $key): string
    {
        $sid = $key;
        $node = '@__mc_refl_node_' . $sid;
        $t = self::reflNodeType();
        $out = $node . ' = linkonce_odr global ' . $t . ' { ptr @__mc_rmeta_v2_' . $sid
             . ", ptr null, i64 0 }\n";
        $out .= 'define void @__mc_refl_reg_' . $sid . "() {\nentry:\n";
        $out .= '  %f = getelementptr i8, ptr ' . $node . ", i64 16\n";
        $out .= "  %fv = load i64, ptr %f\n";
        $out .= "  %done = icmp ne i64 %fv, 0\n";
        $out .= "  br i1 %done, label %skip, label %reg\n";
        $out .= "reg:\n";
        $out .= "  store i64 1, ptr %f\n";
        $out .= "  %h = load ptr, ptr @__mc_refl_head\n";
        $out .= '  %np = getelementptr i8, ptr ' . $node . ", i64 8\n";
        $out .= "  store ptr %h, ptr %np\n";
        $out .= '  store ptr ' . $node . ", ptr @__mc_refl_head\n";
        $out .= "  br label %skip\n";
        $out .= "skip:\n  ret void\n}\n";
        return $out;
    }

    /**
     * The list head + the `@llvm.global_ctors` array + `__mc_refl_find`.
     *
     * `$ids` are the classes to register. An EMPTY list still emits the head and
     * `find` — only the ctors array is skipped. Emitting nothing would leave a
     * caller's `@__mc_refl_find` undefined, and this toolchain does not fail on
     * that: the stub generator fills a missing symbol with `return 0`
     * ({@see \Manticore\build_compile_module}), so reflection would silently
     * answer "no such class" instead of erroring. That is the failure mode that
     * once turned `sort()` into a no-op.
     *
     * `find` is a linear walk. That is deliberate for now: the list is the
     * reflectable set, not every class, and a hash index is an optimization
     * with its own cross-module lifetime questions. Duplicate nodes for one
     * class are possible and harmless — first hit wins, and every node for a
     * given class points at the same coalesced rmeta.
     *
     * @param string[] $ids symbol suffixes
     */
    public static function reflRegistry(array $ids): string
    {
        $out = "@__mc_refl_head = linkonce_odr global ptr null\n";
        $entries = [];
        foreach ($ids as $id) {
            $entries[] = '{ i32, ptr, ptr } { i32 65535, ptr @__mc_refl_reg_' . $id . ', ptr null }';
        }
        if (\count($entries) > 0) {
            $out .= '@llvm.global_ctors = appending global [' . (string)\count($entries)
                  . ' x { i32, ptr, ptr }] [' . \implode(', ', $entries) . "]\n";
        }
        $out .= self::reflHash();
        $out .= self::reflIndex();
        // Probe the index; fall back to walking the list if it could not be
        // built (calloc failure). Measured: the walk alone was O(reflectable
        // classes) — 3.7× slower than php at 500 classes — because php has a
        // hash table and this did not.
        $out .= "define i64 @__mc_refl_find(ptr %name) {\nentry:\n";
        $out .= "  %tab = call ptr @__mc_refl_index()\n";
        $out .= "  %notab = icmp eq ptr %tab, null\n";
        $out .= "  br i1 %notab, label %walk, label %hp\n";
        $out .= "hp:\n";
        $out .= "  %cap = load i64, ptr @__mc_refl_idx_cap\n";
        $out .= "  %hmask = sub i64 %cap, 1\n";
        $out .= "  %nh = call i64 @__mc_refl_hash(ptr %name)\n";
        $out .= "  %hi0 = and i64 %nh, %hmask\n";
        $out .= "  br label %hloop\n";
        $out .= "hloop:\n";
        $out .= "  %hi = phi i64 [ %hi0, %hp ], [ %hi1, %hnext ]\n";
        $out .= "  %hsl = getelementptr ptr, ptr %tab, i64 %hi\n";
        $out .= "  %hv = load ptr, ptr %hsl\n";
        // An empty slot means absent: the table always has one (cap >= 2*count),
        // so the probe terminates.
        $out .= "  %hfree = icmp eq ptr %hv, null\n";
        $out .= "  br i1 %hfree, label %miss, label %hcheck\n";
        $out .= "hcheck:\n";
        $out .= '  %hnmp = getelementptr i8, ptr %hv, i64 '
              . (string)\Compile\MemoryAbi::RMETA_NAME_OFFSET . "\n";
        $out .= "  %hnm = load ptr, ptr %hnmp\n";
        // strcmp confirms: a hash match is not a name match.
        $out .= "  %hc = call i32 @strcmp(ptr %hnm, ptr %name)\n";
        $out .= "  %heq = icmp eq i32 %hc, 0\n";
        $out .= "  br i1 %heq, label %hhit, label %hnext\n";
        $out .= "hhit:\n";
        $out .= "  %hr = ptrtoint ptr %hv to i64\n";
        $out .= "  ret i64 %hr\n";
        $out .= "hnext:\n";
        $out .= "  %hia = add i64 %hi, 1\n";
        $out .= "  %hi1 = and i64 %hia, %hmask\n";
        $out .= "  br label %hloop\n";
        $out .= "walk:\n";
        $out .= "  %p0 = load ptr, ptr @__mc_refl_head\n";
        $out .= "  br label %loop\n";
        $out .= "loop:\n";
        $out .= "  %p = phi ptr [ %p0, %walk ], [ %next, %cont ]\n";
        $out .= "  %end = icmp eq ptr %p, null\n";
        $out .= "  br i1 %end, label %miss, label %body\n";
        $out .= "body:\n";
        $out .= "  %m = load ptr, ptr %p\n";
        $out .= '  %nmp = getelementptr i8, ptr %m, i64 '
              . (string)\Compile\MemoryAbi::RMETA_NAME_OFFSET . "\n";
        $out .= "  %nm = load ptr, ptr %nmp\n";
        $out .= "  %c = call i32 @strcmp(ptr %nm, ptr %name)\n";
        $out .= "  %eq = icmp eq i32 %c, 0\n";
        $out .= "  br i1 %eq, label %hit, label %cont\n";
        $out .= "hit:\n";
        $out .= "  %r = ptrtoint ptr %m to i64\n";
        $out .= "  ret i64 %r\n";
        $out .= "cont:\n";
        $out .= "  %nxp = getelementptr i8, ptr %p, i64 8\n";
        $out .= "  %next = load ptr, ptr %nxp\n";
        $out .= "  br label %loop\n";
        $out .= "miss:\n  ret i64 0\n}\n";
        $out .= self::reflMemberLookup();
        $out .= self::reflMemberTramp();
        $out .= self::reflMethodRow();
        return $out;
    }

    /**
     * `__mc_refl_hash(ptr data) -> i64` — FNV-1a 64 of a MIR string, cached.
     *
     * Reads the hash the string header already carries at `data-32`; a literal
     * has it baked in at compile time ({@see Passes\EmitLlvmBuiltins::strGlobalDef},
     * bit-matching {@see Passes\EmitLlvm::fnvHash64}), so a `find('Foo')` never
     * hashes at all. Only a COMPUTED name reaches the loop, and the result is
     * written back so it hashes once.
     *
     * Deliberately NOT `__mir_array_hash_str`, which does the same thing: that
     * one is emitted by the ARRAY runtime, so a program that reflects but never
     * touches an assoc would not have it — and a missing symbol here does not
     * fail the link, it stubs to `return 0` ({@see \Manticore\build_compile_module}).
     * Every hash would then be 0, every key would collide into one bucket, and
     * the table would silently degrade to the linear walk it exists to replace.
     * Correct, and quietly as slow as before — the worst kind of bug.
     *
     * 0 doubles as "not computed", exactly as the string runtime treats it. A
     * genuine FNV of 0 just re-hashes; it costs a hash, never correctness.
     */
    private static function reflHash(): string
    {
        $ho = (string)\Compile\MemoryAbi::STRING_HASH_OFFSET;
        $lo = (string)\Compile\MemoryAbi::STRING_LEN_OFFSET;
        $out = "define i64 @__mc_refl_hash(ptr %p) {\nentry:\n";
        $out .= '  %hp = getelementptr i8, ptr %p, i64 ' . $ho . "\n";
        $out .= "  %hc = load i64, ptr %hp\n";
        $out .= "  %have = icmp ne i64 %hc, 0\n";
        $out .= "  br i1 %have, label %cached, label %calc\n";
        $out .= "cached:\n  ret i64 %hc\n";
        $out .= "calc:\n";
        $out .= '  %lp = getelementptr i8, ptr %p, i64 ' . $lo . "\n";
        $out .= "  %len = load i64, ptr %lp\n";
        $out .= "  %zero = icmp eq i64 %len, 0\n";
        $out .= "  br i1 %zero, label %done, label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [ 0, %calc ], [ %i1, %loop ]\n";
        $out .= "  %h = phi i64 [ -3750763034362895579, %calc ], [ %hm, %loop ]\n";
        $out .= "  %bp = getelementptr i8, ptr %p, i64 %i\n";
        $out .= "  %b = load i8, ptr %bp\n";
        $out .= "  %bz = zext i8 %b to i64\n";
        $out .= "  %hx = xor i64 %h, %bz\n";
        $out .= "  %hm = mul i64 %hx, 1099511628211\n";
        $out .= "  %i1 = add i64 %i, 1\n";
        $out .= "  %more = icmp ult i64 %i1, %len\n";
        $out .= "  br i1 %more, label %loop, label %done\n";
        $out .= "done:\n";
        $out .= "  %hf = phi i64 [ -3750763034362895579, %calc ], [ %hm, %loop ]\n";
        $out .= "  store i64 %hf, ptr %hp\n";
        $out .= "  ret i64 %hf\n}\n";
        return $out;
    }

    /**
     * `__mc_refl_index() -> ptr` — the open-addressed name→rmeta table, built
     * once on first use.
     *
     * Why an index at all, measured rather than assumed: `find` was a linked-list
     * walk with a strcmp per node, i.e. O(reflectable classes). At 500 classes
     * that is 356ms per 200k lookups against php's flat 96ms — 3.7× SLOWER, and
     * a real app has thousands. php has a hash table; this is that.
     *
     * Lazy is safe: every `@llvm.global_ctors` entry has already run before
     * `main`, so the list is complete the first time anything can call `find`.
     * The list stays the REGISTRATION channel — it is what composes across
     * separately-linked objects with no central table to forget — and this is a
     * read cache over it. Nothing is freed: the table lives as long as the
     * process, like the metadata it points at.
     *
     * Capacity is a power of two ≥ 2× the entry count, so the probe always meets
     * an empty slot and terminates. Duplicate registrations of one class (two
     * modules) collapse here: same name, same rmeta, first insert wins.
     */
    private static function reflIndex(): string
    {
        $nameOff = (string)\Compile\MemoryAbi::RMETA_NAME_OFFSET;
        $out = "@__mc_refl_idx = linkonce_odr global ptr null\n";
        $out .= "@__mc_refl_idx_cap = linkonce_odr global i64 0\n";
        $out .= "define ptr @__mc_refl_index() {\nentry:\n";
        $out .= "  %cur = load ptr, ptr @__mc_refl_idx\n";
        $out .= "  %built = icmp ne ptr %cur, null\n";
        $out .= "  br i1 %built, label %ret, label %count\n";
        $out .= "ret:\n  ret ptr %cur\n";
        // Count the list.
        $out .= "count:\n";
        $out .= "  %h0 = load ptr, ptr @__mc_refl_head\n";
        $out .= "  br label %cloop\n";
        $out .= "cloop:\n";
        $out .= "  %cp = phi ptr [ %h0, %count ], [ %cn, %cnext ]\n";
        $out .= "  %cc = phi i64 [ 0, %count ], [ %cc1, %cnext ]\n";
        $out .= "  %cend = icmp eq ptr %cp, null\n";
        $out .= "  br i1 %cend, label %alloc, label %cnext\n";
        $out .= "cnext:\n";
        $out .= "  %cc1 = add i64 %cc, 1\n";
        $out .= "  %cnp = getelementptr i8, ptr %cp, i64 8\n";
        $out .= "  %cn = load ptr, ptr %cnp\n";
        $out .= "  br label %cloop\n";
        // cap = next pow2 >= 2*count, min 8. ctlz gives the bit width.
        $out .= "alloc:\n";
        $out .= "  %n2 = shl i64 %cc, 1\n";
        $out .= "  %n2m = icmp ult i64 %n2, 8\n";
        $out .= "  %n2c = select i1 %n2m, i64 8, i64 %n2\n";
        $out .= "  %nm1 = sub i64 %n2c, 1\n";
        $out .= "  %lz = call i64 @llvm.ctlz.i64(i64 %nm1, i1 false)\n";
        $out .= "  %sh = sub i64 64, %lz\n";
        $out .= "  %cap = shl i64 1, %sh\n";
        $out .= "  %bytes = shl i64 %cap, 3\n";
        $out .= "  %tab = call ptr @calloc(i64 %bytes, i64 1)\n";
        $out .= "  %anull = icmp eq ptr %tab, null\n";
        // calloc failure: leave the index unbuilt and answer null. find() then
        // falls back to the list walk — slow, but correct, and not a crash.
        $out .= "  br i1 %anull, label %fail, label %fill\n";
        $out .= "fail:\n  ret ptr null\n";
        $out .= "fill:\n";
        $out .= "  %mask = sub i64 %cap, 1\n";
        $out .= "  %f0 = load ptr, ptr @__mc_refl_head\n";
        $out .= "  br label %floop\n";
        $out .= "floop:\n";
        $out .= "  %fp = phi ptr [ %f0, %fill ], [ %fn, %fnext ]\n";
        $out .= "  %fend = icmp eq ptr %fp, null\n";
        $out .= "  br i1 %fend, label %store, label %fbody\n";
        $out .= "fbody:\n";
        $out .= "  %fm = load ptr, ptr %fp\n";
        $out .= '  %fnmp = getelementptr i8, ptr %fm, i64 ' . $nameOff . "\n";
        $out .= "  %fnm = load ptr, ptr %fnmp\n";
        $out .= "  %fh = call i64 @__mc_refl_hash(ptr %fnm)\n";
        $out .= "  %fi0 = and i64 %fh, %mask\n";
        $out .= "  br label %ploop\n";
        $out .= "ploop:\n";
        $out .= "  %pi = phi i64 [ %fi0, %fbody ], [ %pi1, %pnext ]\n";
        $out .= "  %psl = getelementptr ptr, ptr %tab, i64 %pi\n";
        $out .= "  %pv = load ptr, ptr %psl\n";
        $out .= "  %pfree = icmp eq ptr %pv, null\n";
        $out .= "  br i1 %pfree, label %put, label %pdup\n";
        // An occupied slot holding the SAME name is the two-modules-registered-
        // one-class case: keep the first, do not insert twice.
        $out .= "pdup:\n";
        $out .= '  %dnmp = getelementptr i8, ptr %pv, i64 ' . $nameOff . "\n";
        $out .= "  %dnm = load ptr, ptr %dnmp\n";
        $out .= "  %dc = call i32 @strcmp(ptr %dnm, ptr %fnm)\n";
        $out .= "  %dsame = icmp eq i32 %dc, 0\n";
        $out .= "  br i1 %dsame, label %fnext, label %pnext\n";
        $out .= "pnext:\n";
        $out .= "  %pia = add i64 %pi, 1\n";
        $out .= "  %pi1 = and i64 %pia, %mask\n";
        $out .= "  br label %ploop\n";
        $out .= "put:\n";
        $out .= "  store ptr %fm, ptr %psl\n";
        $out .= "  br label %fnext\n";
        $out .= "fnext:\n";
        $out .= "  %fnp = getelementptr i8, ptr %fp, i64 8\n";
        $out .= "  %fn = load ptr, ptr %fnp\n";
        $out .= "  br label %floop\n";
        $out .= "store:\n";
        $out .= "  store i64 %cap, ptr @__mc_refl_idx_cap\n";
        $out .= "  store ptr %tab, ptr @__mc_refl_idx\n";
        $out .= "  ret ptr %tab\n}\n";
        return $out;
    }

    /**
     * `__mc_refl_member(handle, name, wantMethods)` — a member's flags word + 1,
     * or 0 when absent.
     *
     * One walker for both tables: they have identical shape, and two near-copies
     * of a strcmp loop is two places to fix a bug. The `+1` is what lets a single
     * i64 answer both "is it there" and "what is it" — flags for a plain public
     * member are 0, so a raw flags word could not distinguish "public method"
     * from "no such method".
     *
     * A null handle or an empty table answers 0 rather than dereferencing: the
     * count is checked before the pointer, because an empty table stores `ptr
     * null` there.
     */
    private static function reflMemberLookup(): string
    {
        $nm = (string)\Compile\MemoryAbi::RMETA_NMETHODS_OFFSET;
        $mt = (string)\Compile\MemoryAbi::RMETA_METHODS_OFFSET;
        $np = (string)\Compile\MemoryAbi::RMETA_NPROPS_OFFSET;
        $pt = (string)\Compile\MemoryAbi::RMETA_PROPS_OFFSET;
        $rs = (string)\Compile\MemoryAbi::RMETA_ROW_SIZE;
        $rf = (string)\Compile\MemoryAbi::RMETA_ROW_FLAGS_OFFSET;
        $out = "define i64 @__mc_refl_member(i64 %h, ptr %name, i64 %want) {\nentry:\n";
        $out .= "  %hz = icmp eq i64 %h, 0\n";
        $out .= "  br i1 %hz, label %miss, label %have\n";
        $out .= "have:\n";
        $out .= "  %m = inttoptr i64 %h to ptr\n";
        $out .= "  %isM = icmp ne i64 %want, 0\n";
        $out .= '  %cntOff = select i1 %isM, i64 ' . $nm . ', i64 ' . $np . "\n";
        $out .= '  %tabOff = select i1 %isM, i64 ' . $mt . ', i64 ' . $pt . "\n";
        $out .= "  %cntP = getelementptr i8, ptr %m, i64 %cntOff\n";
        $out .= "  %cnt = load i64, ptr %cntP\n";
        $out .= "  %tabP = getelementptr i8, ptr %m, i64 %tabOff\n";
        $out .= "  %tab = load ptr, ptr %tabP\n";
        $out .= "  %empty = icmp eq i64 %cnt, 0\n";
        $out .= "  br i1 %empty, label %miss, label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [ 0, %have ], [ %i1, %cont ]\n";
        $out .= '  %roff = mul i64 %i, ' . $rs . "\n";
        $out .= "  %row = getelementptr i8, ptr %tab, i64 %roff\n";
        $out .= "  %rn = load ptr, ptr %row\n";
        $out .= "  %c = call i32 @strcmp(ptr %rn, ptr %name)\n";
        $out .= "  %eq = icmp eq i32 %c, 0\n";
        $out .= "  br i1 %eq, label %hit, label %cont\n";
        $out .= "hit:\n";
        $out .= '  %fp = getelementptr i8, ptr %row, i64 ' . $rf . "\n";
        $out .= "  %fv = load i64, ptr %fp\n";
        $out .= "  %r = add i64 %fv, 1\n";
        $out .= "  ret i64 %r\n";
        $out .= "cont:\n";
        $out .= "  %i1 = add i64 %i, 1\n";
        $out .= "  %done = icmp eq i64 %i1, %cnt\n";
        $out .= "  br i1 %done, label %miss, label %loop\n";
        $out .= "miss:\n  ret i64 0\n}\n";
        return $out;
    }

    /**
     * `__mc_refl_tramp(i64 h, ptr name) -> i64` — a METHOD's invoke-trampoline
     * pointer (as i64), or 0 when the handle is null, the name is absent, or the
     * method is not invokable (its row's tramp is null). The method table only,
     * since properties carry no trampoline. Same walk as {@see reflMemberLookup},
     * returning the row's tramp field ({@see \Compile\MemoryAbi::RMETA_ROW_TRAMP_OFFSET})
     * instead of flags+1.
     */
    private static function reflMemberTramp(): string
    {
        $nm = (string)\Compile\MemoryAbi::RMETA_NMETHODS_OFFSET;
        $mt = (string)\Compile\MemoryAbi::RMETA_METHODS_OFFSET;
        $rs = (string)\Compile\MemoryAbi::RMETA_ROW_SIZE;
        $rt = (string)\Compile\MemoryAbi::RMETA_ROW_TRAMP_OFFSET;
        $out = "define i64 @__mc_refl_tramp(i64 %h, ptr %name) {\nentry:\n";
        $out .= "  %hz = icmp eq i64 %h, 0\n";
        $out .= "  br i1 %hz, label %miss, label %have\n";
        $out .= "have:\n";
        $out .= "  %m = inttoptr i64 %h to ptr\n";
        $out .= '  %cntP = getelementptr i8, ptr %m, i64 ' . $nm . "\n";
        $out .= "  %cnt = load i64, ptr %cntP\n";
        $out .= '  %tabP = getelementptr i8, ptr %m, i64 ' . $mt . "\n";
        $out .= "  %tab = load ptr, ptr %tabP\n";
        $out .= "  %empty = icmp eq i64 %cnt, 0\n";
        $out .= "  br i1 %empty, label %miss, label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [ 0, %have ], [ %i1, %cont ]\n";
        $out .= '  %roff = mul i64 %i, ' . $rs . "\n";
        $out .= "  %row = getelementptr i8, ptr %tab, i64 %roff\n";
        $out .= "  %rn = load ptr, ptr %row\n";
        $out .= "  %c = call i32 @strcmp(ptr %rn, ptr %name)\n";
        $out .= "  %eq = icmp eq i32 %c, 0\n";
        $out .= "  br i1 %eq, label %hit, label %cont\n";
        $out .= "hit:\n";
        $out .= '  %tp = getelementptr i8, ptr %row, i64 ' . $rt . "\n";
        $out .= "  %tv = load ptr, ptr %tp\n";
        $out .= "  %r = ptrtoint ptr %tv to i64\n";
        $out .= "  ret i64 %r\n";
        $out .= "cont:\n";
        $out .= "  %i1 = add i64 %i, 1\n";
        $out .= "  %done = icmp eq i64 %i1, %cnt\n";
        $out .= "  br i1 %done, label %miss, label %loop\n";
        $out .= "miss:\n  ret i64 0\n}\n";
        return $out;
    }

    /**
     * `__mc_refl_mrow(i64 h, ptr name) -> i64` — a method row's ADDRESS (as i64),
     * or 0 when absent. The prelude caches it in a ReflectionMethod, then reads
     * nparams / params / arity off it (one walk, many field reads). Same method
     * table walk as {@see reflMemberTramp}, returning the row pointer.
     */
    private static function reflMethodRow(): string
    {
        $nm = (string)\Compile\MemoryAbi::RMETA_NMETHODS_OFFSET;
        $mt = (string)\Compile\MemoryAbi::RMETA_METHODS_OFFSET;
        $rs = (string)\Compile\MemoryAbi::RMETA_ROW_SIZE;
        $out = "define i64 @__mc_refl_mrow(i64 %h, ptr %name) {\nentry:\n";
        $out .= "  %hz = icmp eq i64 %h, 0\n";
        $out .= "  br i1 %hz, label %miss, label %have\n";
        $out .= "have:\n";
        $out .= "  %m = inttoptr i64 %h to ptr\n";
        $out .= '  %cntP = getelementptr i8, ptr %m, i64 ' . $nm . "\n";
        $out .= "  %cnt = load i64, ptr %cntP\n";
        $out .= '  %tabP = getelementptr i8, ptr %m, i64 ' . $mt . "\n";
        $out .= "  %tab = load ptr, ptr %tabP\n";
        $out .= "  %empty = icmp eq i64 %cnt, 0\n";
        $out .= "  br i1 %empty, label %miss, label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [ 0, %have ], [ %i1, %cont ]\n";
        $out .= '  %roff = mul i64 %i, ' . $rs . "\n";
        $out .= "  %row = getelementptr i8, ptr %tab, i64 %roff\n";
        $out .= "  %rn = load ptr, ptr %row\n";
        $out .= "  %c = call i32 @strcmp(ptr %rn, ptr %name)\n";
        $out .= "  %eq = icmp eq i32 %c, 0\n";
        $out .= "  br i1 %eq, label %hit, label %cont\n";
        $out .= "hit:\n";
        $out .= "  %r = ptrtoint ptr %row to i64\n";
        $out .= "  ret i64 %r\n";
        $out .= "cont:\n";
        $out .= "  %i1 = add i64 %i, 1\n";
        $out .= "  %done = icmp eq i64 %i1, %cnt\n";
        $out .= "  br i1 %done, label %miss, label %loop\n";
        $out .= "miss:\n  ret i64 0\n}\n";
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

        // Bounded byte read: s[i] for i < n, else 0 — mirrors PHP's ord("") == 0
        // on a blind UTF-8 continuation read past the end (and never touches
        // memory past the allocation, unlike a raw load would).
        $out .= "\ndefine i64 @__mir_json_bat(ptr %s, i64 %i, i64 %n) {\n";
        $out .= "entry:\n";
        $out .= "  %in = icmp slt i64 %i, %n\n";
        $out .= "  br i1 %in, label %ld, label %z\n";
        $out .= "ld:\n";
        $out .= "  %p = getelementptr inbounds i8, ptr %s, i64 %i\n";
        $out .= "  %b = load i8, ptr %p\n";
        $out .= "  %bz = zext i8 %b to i64\n";
        $out .= "  ret i64 %bz\n";
        $out .= "z:\n  ret i64 0\n}\n";

        // Write `\uXXXX` (lowercase hex) for %cp at %buf+%j, return the new j.
        // Caller has already reserved the room.
        $out .= "\ndefine i64 @__mir_json_u4(ptr %buf, i64 %j, i64 %cp) {\n";
        $out .= "entry:\n";
        $out .= "  %d0 = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 92, ptr %d0\n";
        $out .= "  %j1 = add i64 %j, 1\n";
        $out .= "  %d1 = getelementptr inbounds i8, ptr %buf, i64 %j1\n";
        $out .= "  store i8 117, ptr %d1\n";
        $j = 2;
        foreach ([12, 8, 4, 0] as $k => $sh) {
            $out .= "  %n$k = lshr i64 %cp, $sh\n";
            $out .= "  %m$k = and i64 %n$k, 15\n";
            $out .= "  %lt$k = icmp ult i64 %m$k, 10\n";
            $out .= "  %a$k = add i64 %m$k, 48\n";
            $out .= "  %b$k = add i64 %m$k, 87\n";
            $out .= "  %h$k = select i1 %lt$k, i64 %a$k, i64 %b$k\n";
            $out .= "  %t$k = trunc i64 %h$k to i8\n";
            $out .= "  %jx$k = add i64 %j, " . ($j + $k) . "\n";
            $out .= "  %dx$k = getelementptr inbounds i8, ptr %buf, i64 %jx$k\n";
            $out .= "  store i8 %t$k, ptr %dx$k\n";
        }
        $out .= "  %jr = add i64 %j, 6\n";
        $out .= "  ret i64 %jr\n}\n";

        // Append `"<escaped s>"`. Default php flags: `"` `\` `/` and the C0
        // controls escape, and any non-ASCII byte (>=0x80) becomes `\uXXXX`. The
        // hot inline loop handles the ASCII escapes in ONE pass (fast for the
        // common short-word case), writing each byte or its two-char `\x` form.
        // The first byte needing UTF-8 decoding (>=0x80) or a rare control with
        // no short form switches to the NATIVE slow loop below (commit the
        // inline bytes, reserve worst-case 6B/byte for the remainder, continue
        // in place) — the old whole-string bail to the compiled-PHP
        // `__mc_json_escape` re-escaped and re-copied everything and its
        // per-char string concat was the 3.2 GB RSS / 8×-slower json_utf8
        // pathology. The decode mirrors __mc_json_escape exactly (blind lead
        // dispatch, ord("")=0 past the end, surrogate pair above the BMP).
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
        // Native slow path: commit the inline bytes written so far, reserve
        // worst-case room for the remainder (6 B per source byte: \uXXXX; a
        // 4-byte sequence's surrogate pair is 12 B for 4 bytes — under the
        // bound), reload the (possibly regrown) buffer and continue in place.
        $out .= "phppath:\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %j)\n";
        $out .= "  %srem = sub i64 %slen, %i\n";
        $out .= "  %srem6 = mul i64 %srem, 6\n";
        $out .= "  %srsv = add i64 %srem6, 2\n";
        $out .= "  %buf2 = call ptr @__mir_json_reserve(ptr %slotp, i64 %srsv)\n";
        $out .= "  br label %sloop\n";
        $out .= "sloop:\n";
        $out .= "  %si = phi i64 [%i, %phppath], [%si2, %scont]\n";
        $out .= "  %sj = phi i64 [%j, %phppath], [%sj2, %scont]\n";
        $out .= "  %sdone = icmp sge i64 %si, %slen\n";
        $out .= "  br i1 %sdone, label %sfin, label %sbody\n";
        $out .= "sbody:\n";
        $out .= "  %ssp = getelementptr inbounds i8, ptr %s, i64 %si\n";
        $out .= "  %sc = load i8, ptr %ssp\n";
        $out .= "  %scz = zext i8 %sc to i64\n";
        $out .= "  %sge80 = icmp uge i64 %scz, 128\n";
        $out .= "  br i1 %sge80, label %sutf, label %sascii\n";
        $out .= "sascii:\n";
        $out .= "  %ss34 = icmp eq i64 %scz, 34\n";
        $out .= "  %ss92 = icmp eq i64 %scz, 92\n";
        $out .= "  %ss47 = icmp eq i64 %scz, 47\n";
        $out .= "  %ss10 = icmp eq i64 %scz, 10\n";
        $out .= "  %ss9  = icmp eq i64 %scz, 9\n";
        $out .= "  %ss13 = icmp eq i64 %scz, 13\n";
        $out .= "  %ss8  = icmp eq i64 %scz, 8\n";
        $out .= "  %ss12 = icmp eq i64 %scz, 12\n";
        $out .= "  %sm1 = select i1 %ss10, i64 110, i64 %scz\n";
        $out .= "  %sm2 = select i1 %ss9,  i64 116, i64 %sm1\n";
        $out .= "  %sm3 = select i1 %ss13, i64 114, i64 %sm2\n";
        $out .= "  %sm4 = select i1 %ss8,  i64 98,  i64 %sm3\n";
        $out .= "  %sm5 = select i1 %ss12, i64 102, i64 %sm4\n";
        $out .= "  %sp1 = or i1 %ss34, %ss92\n";
        $out .= "  %sp2 = or i1 %sp1, %ss47\n";
        $out .= "  %sp3 = or i1 %sp2, %ss10\n";
        $out .= "  %sp4 = or i1 %sp3, %ss9\n";
        $out .= "  %sp5 = or i1 %sp4, %ss13\n";
        $out .= "  %sp6 = or i1 %sp5, %ss8\n";
        $out .= "  %sspec = or i1 %sp6, %ss12\n";
        $out .= "  br i1 %sspec, label %sesc, label %sq\n";
        $out .= "sq:\n";
        $out .= "  %slt32 = icmp ult i64 %scz, 32\n";
        $out .= "  br i1 %slt32, label %sctl, label %splain\n";
        $out .= "sesc:\n";
        $out .= "  %sd1 = getelementptr inbounds i8, ptr %buf2, i64 %sj\n";
        $out .= "  store i8 92, ptr %sd1\n";
        $out .= "  %sjb = add i64 %sj, 1\n";
        $out .= "  %sd2 = getelementptr inbounds i8, ptr %buf2, i64 %sjb\n";
        $out .= "  %smb = trunc i64 %sm5 to i8\n";
        $out .= "  store i8 %smb, ptr %sd2\n";
        $out .= "  %sje = add i64 %sj, 2\n";
        $out .= "  br label %scont\n";
        $out .= "sctl:\n";
        $out .= "  %sjc = call i64 @__mir_json_u4(ptr %buf2, i64 %sj, i64 %scz)\n";
        $out .= "  br label %scont\n";
        $out .= "splain:\n";
        $out .= "  %sd3 = getelementptr inbounds i8, ptr %buf2, i64 %sj\n";
        $out .= "  store i8 %sc, ptr %sd3\n";
        $out .= "  %sjp = add i64 %sj, 1\n";
        $out .= "  br label %scont\n";
        // UTF-8: blind lead dispatch, exactly __mc_json_escape's ladder.
        $out .= "sutf:\n";
        $out .= "  %sil = add i64 %si, 1\n";
        $out .= "  %sf4 = icmp uge i64 %scz, 240\n";
        $out .= "  br i1 %sf4, label %s4, label %se3\n";
        $out .= "se3:\n";
        $out .= "  %sf3 = icmp uge i64 %scz, 224\n";
        $out .= "  br i1 %sf3, label %s3, label %s2\n";
        $out .= "s4:\n";
        $out .= "  %qb1 = call i64 @__mir_json_bat(ptr %s, i64 %sil, i64 %slen)\n";
        $out .= "  %qi2 = add i64 %si, 2\n";
        $out .= "  %qb2 = call i64 @__mir_json_bat(ptr %s, i64 %qi2, i64 %slen)\n";
        $out .= "  %qi3 = add i64 %si, 3\n";
        $out .= "  %qb3 = call i64 @__mir_json_bat(ptr %s, i64 %qi3, i64 %slen)\n";
        $out .= "  %q7 = and i64 %scz, 7\n";
        $out .= "  %qh = shl i64 %q7, 18\n";
        $out .= "  %qm1 = and i64 %qb1, 63\n";
        $out .= "  %qs1 = shl i64 %qm1, 12\n";
        $out .= "  %qm2 = and i64 %qb2, 63\n";
        $out .= "  %qs2 = shl i64 %qm2, 6\n";
        $out .= "  %qm3 = and i64 %qb3, 63\n";
        $out .= "  %qo1 = or i64 %qh, %qs1\n";
        $out .= "  %qo2 = or i64 %qo1, %qs2\n";
        $out .= "  %cp4 = or i64 %qo2, %qm3\n";
        $out .= "  br label %sjoin\n";
        $out .= "s3:\n";
        $out .= "  %tb1 = call i64 @__mir_json_bat(ptr %s, i64 %sil, i64 %slen)\n";
        $out .= "  %ti2 = add i64 %si, 2\n";
        $out .= "  %tb2 = call i64 @__mir_json_bat(ptr %s, i64 %ti2, i64 %slen)\n";
        $out .= "  %t15 = and i64 %scz, 15\n";
        $out .= "  %th = shl i64 %t15, 12\n";
        $out .= "  %tm1 = and i64 %tb1, 63\n";
        $out .= "  %ts1 = shl i64 %tm1, 6\n";
        $out .= "  %tm2 = and i64 %tb2, 63\n";
        $out .= "  %to1 = or i64 %th, %ts1\n";
        $out .= "  %cp3 = or i64 %to1, %tm2\n";
        $out .= "  br label %sjoin\n";
        $out .= "s2:\n";
        $out .= "  %ub1 = call i64 @__mir_json_bat(ptr %s, i64 %sil, i64 %slen)\n";
        $out .= "  %u31 = and i64 %scz, 31\n";
        $out .= "  %uh = shl i64 %u31, 6\n";
        $out .= "  %um1 = and i64 %ub1, 63\n";
        $out .= "  %cp2 = or i64 %uh, %um1\n";
        $out .= "  br label %sjoin\n";
        $out .= "sjoin:\n";
        $out .= "  %cp = phi i64 [%cp4, %s4], [%cp3, %s3], [%cp2, %s2]\n";
        $out .= "  %sdi = phi i64 [4, %s4], [3, %s3], [2, %s2]\n";
        $out .= "  %sbig = icmp ugt i64 %cp, 65535\n";
        $out .= "  br i1 %sbig, label %spair, label %sone\n";
        $out .= "sone:\n";
        $out .= "  %sj1x = call i64 @__mir_json_u4(ptr %buf2, i64 %sj, i64 %cp)\n";
        $out .= "  br label %scont\n";
        $out .= "spair:\n";
        $out .= "  %cpm = sub i64 %cp, 65536\n";
        $out .= "  %hi10 = lshr i64 %cpm, 10\n";
        $out .= "  %hicp = add i64 %hi10, 55296\n";
        $out .= "  %lo10 = and i64 %cpm, 1023\n";
        $out .= "  %locp = add i64 %lo10, 56320\n";
        $out .= "  %sjh = call i64 @__mir_json_u4(ptr %buf2, i64 %sj, i64 %hicp)\n";
        $out .= "  %sjl = call i64 @__mir_json_u4(ptr %buf2, i64 %sjh, i64 %locp)\n";
        $out .= "  br label %scont\n";
        $out .= "scont:\n";
        $out .= "  %sj2 = phi i64 [%sje, %sesc], [%sjc, %sctl], [%sjp, %splain], [%sj1x, %sone], [%sjl, %spair]\n";
        $out .= "  %sadv = phi i64 [1, %sesc], [1, %sctl], [1, %splain], [%sdi, %sone], [%sdi, %spair]\n";
        $out .= "  %si2 = add i64 %si, %sadv\n";
        $out .= "  br label %sloop\n";
        $out .= "sfin:\n";
        $out .= "  %sqc = getelementptr inbounds i8, ptr %buf2, i64 %sj\n";
        $out .= "  store i8 34, ptr %sqc\n";
        $out .= "  %sjend = add i64 %sj, 1\n";
        $out .= "  %snul = getelementptr inbounds i8, ptr %buf2, i64 %sjend\n";
        $out .= "  store i8 0, ptr %snul\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf2, i64 %sjend)\n";
        $out .= "  ret void\n}\n";

        // Float emitter: Ryu digits via the scalar core (__mc_dtoa_scal, two
        // calls — digits and (exp<<1)|sign), formatted straight into the json
        // buffer with the exact __mc_dtoa_core tail semantics (json flavor:
        // lowercase e, no forced .0). Zero heap traffic per float — the old
        // per-float PHP string tail (substr/concat/str_repeat temps + result
        // alloc/copy/free) was ~47% of json_records wall and ALL of its 242 MB
        // RSS churn. Non-finite / ±0 return -1 from the scalar core → the old
        // string path (rare).
        $out .= "\ndefine void @__mir_json_double(ptr %slotp, i64 %cell) {\n";
        $out .= "entry:\n";
        $out .= "  %sig = call i64 @manticore___mc_dtoa_scal(i64 %cell, i64 0)\n";
        $out .= "  %spec = icmp slt i64 %sig, 0\n";
        $out .= "  br i1 %spec, label %fb, label %go\n";
        $out .= "fb:\n";
        $out .= "  %fsi = call i64 @manticore___mc_dtoa_bits(i64 %cell)\n";
        $out .= "  %fs = inttoptr i64 %fsi to ptr\n";
        $out .= "  %fn = call i64 @__mir_strlen(ptr %fs)\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr %fs, i64 %fn)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %fs)\n";
        $out .= "  ret void\n";
        $out .= "go:\n";
        $out .= "  %meta = call i64 @manticore___mc_dtoa_scal(i64 %cell, i64 1)\n";
        $out .= "  %fsign = and i64 %meta, 1\n";
        $out .= "  %eb = lshr i64 %meta, 1\n";
        $out .= "  %fexp = sub i64 %eb, 1024\n";
        $out .= "  %olen = call i64 @__mir_int_len(i64 %sig)\n";
        $out .= "  %dt = alloca [24 x i8]\n";
        $out .= "  call void @__mir_int_fmt(ptr %dt, i64 0, i64 %sig)\n";
        $out .= "  %eneg = icmp slt i64 %fexp, 0\n";
        $out .= "  %nexp = sub i64 0, %fexp\n";
        $out .= "  %aexp = select i1 %eneg, i64 %nexp, i64 %fexp\n";
        $out .= "  %rsv0 = add i64 %olen, %aexp\n";
        $out .= "  %rsv = add i64 %rsv0, 16\n";
        $out .= "  %buf = call ptr @__mir_json_reserve(ptr %slotp, i64 %rsv)\n";
        $out .= "  %lenp = getelementptr inbounds i8, ptr %buf, i64 -16\n";
        $out .= "  %len0 = load i64, ptr %lenp\n";
        $out .= "  %wp = alloca i64\n";
        $out .= "  store i64 %len0, ptr %wp\n";
        $out .= "  %isneg = icmp ne i64 %fsign, 0\n";
        $out .= "  br i1 %isneg, label %wneg, label %fmt\n";
        $out .= "wneg:\n";
        $out .= "  %w0 = load i64, ptr %wp\n";
        $out .= "  %np = getelementptr inbounds i8, ptr %buf, i64 %w0\n";
        $out .= "  store i8 45, ptr %np\n";
        $out .= "  %w1 = add i64 %w0, 1\n";
        $out .= "  store i64 %w1, ptr %wp\n";
        $out .= "  br label %fmt\n";
        $out .= "fmt:\n";
        $out .= "  %esci0 = add i64 %fexp, %olen\n";
        $out .= "  %esci = sub i64 %esci0, 1\n";
        $out .= "  %lo = icmp slt i64 %esci, -4\n";
        $out .= "  %hi = icmp sgt i64 %esci, 16\n";
        $out .= "  %sci = or i1 %lo, %hi\n";
        $out .= "  br i1 %sci, label %fsci, label %ffix\n";
        // Fixed, integer-valued: digits then %fexp zeros (fexp <= 16 here).
        $out .= "ffix:\n";
        $out .= "  %epos = icmp sge i64 %fexp, 0\n";
        $out .= "  br i1 %epos, label %fint, label %ffrac\n";
        $out .= "fint:\n";
        $out .= "  %wa = load i64, ptr %wp\n";
        $out .= "  %da = getelementptr inbounds i8, ptr %buf, i64 %wa\n";
        $out .= "  call ptr @memcpy(ptr %da, ptr %dt, i64 %olen)\n";
        $out .= "  %wb = add i64 %wa, %olen\n";
        $out .= "  store i64 %wb, ptr %wp\n";
        $out .= "  br label %zl\n";
        $out .= "zl:\n";
        $out .= "  %zi = phi i64 [0, %fint], [%zi2, %zb]\n";
        $out .= "  %zd = icmp sge i64 %zi, %fexp\n";
        $out .= "  br i1 %zd, label %fdone, label %zb\n";
        $out .= "zb:\n";
        $out .= "  %wz = load i64, ptr %wp\n";
        $out .= "  %zp = getelementptr inbounds i8, ptr %buf, i64 %wz\n";
        $out .= "  store i8 48, ptr %zp\n";
        $out .= "  %wz1 = add i64 %wz, 1\n";
        $out .= "  store i64 %wz1, ptr %wp\n";
        $out .= "  %zi2 = add i64 %zi, 1\n";
        $out .= "  br label %zl\n";
        // Fixed with a fractional part.
        $out .= "ffrac:\n";
        $out .= "  %dp = add i64 %olen, %fexp\n";
        $out .= "  %dpos = icmp sgt i64 %dp, 0\n";
        $out .= "  br i1 %dpos, label %fmid, label %fsub\n";
        $out .= "fmid:\n";
        $out .= "  %wc = load i64, ptr %wp\n";
        $out .= "  %dc = getelementptr inbounds i8, ptr %buf, i64 %wc\n";
        $out .= "  call ptr @memcpy(ptr %dc, ptr %dt, i64 %dp)\n";
        $out .= "  %wd = add i64 %wc, %dp\n";
        $out .= "  %pp = getelementptr inbounds i8, ptr %buf, i64 %wd\n";
        $out .= "  store i8 46, ptr %pp\n";
        $out .= "  %we = add i64 %wd, 1\n";
        $out .= "  %sp2 = getelementptr inbounds i8, ptr %dt, i64 %dp\n";
        $out .= "  %rest = sub i64 %olen, %dp\n";
        $out .= "  %dpst = getelementptr inbounds i8, ptr %buf, i64 %we\n";
        $out .= "  call ptr @memcpy(ptr %dpst, ptr %sp2, i64 %rest)\n";
        $out .= "  %wf = add i64 %we, %rest\n";
        $out .= "  store i64 %wf, ptr %wp\n";
        $out .= "  br label %fdone\n";
        $out .= "fsub:\n";
        $out .= "  %wg = load i64, ptr %wp\n";
        $out .= "  %z0p = getelementptr inbounds i8, ptr %buf, i64 %wg\n";
        $out .= "  store i8 48, ptr %z0p\n";
        $out .= "  %wg1 = add i64 %wg, 1\n";
        $out .= "  %z1p = getelementptr inbounds i8, ptr %buf, i64 %wg1\n";
        $out .= "  store i8 46, ptr %z1p\n";
        $out .= "  %wg2 = add i64 %wg, 2\n";
        $out .= "  store i64 %wg2, ptr %wp\n";
        $out .= "  %nz = sub i64 0, %dp\n";
        $out .= "  br label %z2l\n";
        $out .= "z2l:\n";
        $out .= "  %z2i = phi i64 [0, %fsub], [%z2i2, %z2b]\n";
        $out .= "  %z2d = icmp sge i64 %z2i, %nz\n";
        $out .= "  br i1 %z2d, label %fsubd, label %z2b\n";
        $out .= "z2b:\n";
        $out .= "  %wz2 = load i64, ptr %wp\n";
        $out .= "  %z2p = getelementptr inbounds i8, ptr %buf, i64 %wz2\n";
        $out .= "  store i8 48, ptr %z2p\n";
        $out .= "  %wz21 = add i64 %wz2, 1\n";
        $out .= "  store i64 %wz21, ptr %wp\n";
        $out .= "  %z2i2 = add i64 %z2i, 1\n";
        $out .= "  br label %z2l\n";
        $out .= "fsubd:\n";
        $out .= "  %wh0 = load i64, ptr %wp\n";
        $out .= "  %dh = getelementptr inbounds i8, ptr %buf, i64 %wh0\n";
        $out .= "  call ptr @memcpy(ptr %dh, ptr %dt, i64 %olen)\n";
        $out .= "  %wh1 = add i64 %wh0, %olen\n";
        $out .= "  store i64 %wh1, ptr %wp\n";
        $out .= "  br label %fdone\n";
        // Scientific: d[0] [ "." d[1..] | ".0" ] e±NN
        $out .= "fsci:\n";
        $out .= "  %wi = load i64, ptr %wp\n";
        $out .= "  %d0 = load i8, ptr %dt\n";
        $out .= "  %d0p = getelementptr inbounds i8, ptr %buf, i64 %wi\n";
        $out .= "  store i8 %d0, ptr %d0p\n";
        $out .= "  %wi1 = add i64 %wi, 1\n";
        $out .= "  store i64 %wi1, ptr %wp\n";
        $out .= "  %one = icmp eq i64 %olen, 1\n";
        $out .= "  br i1 %one, label %sone, label %smany\n";
        $out .= "sone:\n";
        $out .= "  %sp0 = getelementptr inbounds i8, ptr %buf, i64 %wi1\n";
        $out .= "  store i8 46, ptr %sp0\n";
        $out .= "  %wi2 = add i64 %wi1, 1\n";
        $out .= "  %sp1 = getelementptr inbounds i8, ptr %buf, i64 %wi2\n";
        $out .= "  store i8 48, ptr %sp1\n";
        $out .= "  %wi3 = add i64 %wi1, 2\n";
        $out .= "  store i64 %wi3, ptr %wp\n";
        $out .= "  br label %sexp\n";
        $out .= "smany:\n";
        $out .= "  %mp = getelementptr inbounds i8, ptr %buf, i64 %wi1\n";
        $out .= "  store i8 46, ptr %mp\n";
        $out .= "  %wi4 = add i64 %wi1, 1\n";
        $out .= "  %dt1 = getelementptr inbounds i8, ptr %dt, i64 1\n";
        $out .= "  %mrest = sub i64 %olen, 1\n";
        $out .= "  %mdst = getelementptr inbounds i8, ptr %buf, i64 %wi4\n";
        $out .= "  call ptr @memcpy(ptr %mdst, ptr %dt1, i64 %mrest)\n";
        $out .= "  %wi5 = add i64 %wi4, %mrest\n";
        $out .= "  store i64 %wi5, ptr %wp\n";
        $out .= "  br label %sexp\n";
        $out .= "sexp:\n";
        $out .= "  %wj = load i64, ptr %wp\n";
        $out .= "  %ec = getelementptr inbounds i8, ptr %buf, i64 %wj\n";
        $out .= "  store i8 101, ptr %ec\n";
        $out .= "  %wj1 = add i64 %wj, 1\n";
        $out .= "  %eng = icmp slt i64 %esci, 0\n";
        $out .= "  %esgn = select i1 %eng, i64 45, i64 43\n";
        $out .= "  %esgb = trunc i64 %esgn to i8\n";
        $out .= "  %egp = getelementptr inbounds i8, ptr %buf, i64 %wj1\n";
        $out .= "  store i8 %esgb, ptr %egp\n";
        $out .= "  %wj2 = add i64 %wj, 2\n";
        $out .= "  %nesci = sub i64 0, %esci\n";
        $out .= "  %ae = select i1 %eng, i64 %nesci, i64 %esci\n";
        $out .= "  %ael = call i64 @__mir_int_len(i64 %ae)\n";
        $out .= "  call void @__mir_int_fmt(ptr %buf, i64 %wj2, i64 %ae)\n";
        $out .= "  %wj3 = add i64 %wj2, %ael\n";
        $out .= "  store i64 %wj3, ptr %wp\n";
        $out .= "  br label %fdone\n";
        $out .= "fdone:\n";
        $out .= "  %wfin = load i64, ptr %wp\n";
        $out .= "  %nulp = getelementptr inbounds i8, ptr %buf, i64 %wfin\n";
        $out .= "  store i8 0, ptr %nulp\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %wfin)\n";
        $out .= "  ret void\n}\n";

        // recursive walker.
        $out .= "\ndefine void @__mir_json_app(ptr %slotp, i64 %cell) {\n";
        $out .= "entry:\n";
        $out .= "  %tagged = icmp ugt i64 %cell, $T\n";
        $out .= "  br i1 %tagged, label %istag, label %isfloat\n";
        $out .= "isfloat:\n";
        $out .= "  call void @__mir_json_double(ptr %slotp, i64 %cell)\n";
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
        $H = (string) \Compile\MemoryAbi::ARRAY_HEADER_SIZE;
        $ES = (string) \Compile\MemoryAbi::ARRAY_ENTRY_SIZE;
        $KIND_INT = (string) \Compile\MemoryAbi::ARRAY_KIND_INT;
        $KIND_STR = (string) \Compile\MemoryAbi::ARRAY_KIND_STRING;
        $KEY_OFF = (string) \Compile\MemoryAbi::ARRAY_ENTRY_KEY_OFFSET;
        $VAL_OFF = (string) \Compile\MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET;
        // Array walk. All element access is by direct 24-byte-stride entry
        // loads (kind/key/value) — no out-of-line __mir_array_key_cell_at /
        // value_at calls, no per-element key (re)boxing: keys read RAW, which
        // also makes int keys > 2^47 exact (the old cell-key box path masked
        // and leaked). Upfront reserve of ~8 B/element cuts buffer regrows on
        // big documents (heuristic — reserve only ever grows capacity).
        $out .= "tarr:\n";
        $out .= "  %arr0 = and i64 %cell, $M\n";
        $out .= "  %arr = inttoptr i64 %arr0 to ptr\n";
        $out .= "  %alen = load i64, ptr %arr\n";
        $out .= "  %est1 = shl i64 %alen, 3\n";
        $out .= "  %est = add i64 %est1, 16\n";
        $out .= "  %rbuf = call ptr @__mir_json_reserve(ptr %slotp, i64 %est)\n";
        $out .= "  %hashed = call i64 @__mir_array_is_hashed(ptr %arr)\n";
        $out .= "  %ishash = icmp ne i64 %hashed, 0\n";
        $out .= "  br i1 %ishash, label %chklist, label %aslist\n";
        // List check: every entry kind KIND_INT with key == position, raw loads.
        $out .= "chklist:\n";
        $out .= "  br label %kl\n";
        $out .= "kl:\n";
        $out .= "  %ki = phi i64 [0, %chklist], [%ki2, %kcont]\n";
        $out .= "  %kdone = icmp sge i64 %ki, %alen\n";
        $out .= "  br i1 %kdone, label %aslist, label %kbody\n";
        $out .= "kbody:\n";
        $out .= "  %ke0 = mul i64 %ki, $ES\n";
        $out .= "  %ke1 = add i64 %ke0, $H\n";
        $out .= "  %kkp = getelementptr inbounds i8, ptr %arr, i64 %ke1\n";
        $out .= "  %kkind = load i64, ptr %kkp\n";
        $out .= "  %kisint = icmp eq i64 %kkind, $KIND_INT\n";
        $out .= "  br i1 %kisint, label %kchkidx, label %asobj\n";
        $out .= "kchkidx:\n";
        $out .= "  %ke2 = add i64 %ke1, $KEY_OFF\n";
        $out .= "  %kyp = getelementptr inbounds i8, ptr %arr, i64 %ke2\n";
        $out .= "  %kiv = load i64, ptr %kyp\n";
        $out .= "  %keq = icmp eq i64 %kiv, %ki\n";
        $out .= "  br i1 %keq, label %kcont, label %asobj\n";
        $out .= "kcont:\n";
        $out .= "  %ki2 = add i64 %ki, 1\n";
        $out .= "  br label %kl\n";
        // List emit: value addr = packed slot (8 B) or hashed entry value (24 B
        // stride) — the mode is loop-invariant, selected once per element from
        // %ishash without a call.
        $out .= "aslist:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 91)\n";
        $out .= "  %lstride = select i1 %ishash, i64 $ES, i64 8\n";
        $out .= "  %lbias0 = select i1 %ishash, i64 $VAL_OFF, i64 0\n";
        $out .= "  %lbias = add i64 %lbias0, $H\n";
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
        $out .= "  %le0 = mul i64 %li, %lstride\n";
        $out .= "  %le1 = add i64 %le0, %lbias\n";
        $out .= "  %lvp = getelementptr inbounds i8, ptr %arr, i64 %le1\n";
        $out .= "  %lv = load i64, ptr %lvp\n";
        $out .= "  call void @__mir_json_app(ptr %slotp, i64 %lv)\n";
        $out .= "  br label %lcont\n";
        $out .= "lcont:\n";
        $out .= "  %li2 = add i64 %li, 1\n";
        $out .= "  br label %ll\n";
        $out .= "lend:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 93)\n";
        $out .= "  ret void\n";
        // Object emit: raw kind/key/value entry loads per element.
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
        $out .= "  %oe0 = mul i64 %oi, $ES\n";
        $out .= "  %oe1 = add i64 %oe0, $H\n";
        $out .= "  %okp = getelementptr inbounds i8, ptr %arr, i64 %oe1\n";
        $out .= "  %okind = load i64, ptr %okp\n";
        $out .= "  %oe2 = add i64 %oe1, $KEY_OFF\n";
        $out .= "  %oyp = getelementptr inbounds i8, ptr %arr, i64 %oe2\n";
        $out .= "  %okstr = icmp eq i64 %okind, $KIND_STR\n";
        $out .= "  br i1 %okstr, label %okS, label %okI\n";
        $out .= "okS:\n";
        $out .= "  %oksptr = load ptr, ptr %oyp\n";
        $out .= "  call void @__mir_json_estr(ptr %slotp, ptr %oksptr)\n";
        $out .= "  br label %okdone\n";
        $out .= "okI:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 34)\n";
        $out .= "  %okiv = load i64, ptr %oyp\n";
        $out .= "  call void @__mir_json_int(ptr %slotp, i64 %okiv)\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 34)\n";
        $out .= "  br label %okdone\n";
        $out .= "okdone:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, i64 58)\n";
        $out .= "  %oe3 = add i64 %oe1, $VAL_OFF\n";
        $out .= "  %ovp = getelementptr inbounds i8, ptr %arr, i64 %oe3\n";
        $out .= "  %ov = load i64, ptr %ovp\n";
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
