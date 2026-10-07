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
     * rmeta@16, dynamic_method_table@24, props_fn@32, cmp_view_fn@40,
     * cmp_group@48, json_fn@56, visit_fn@64.
     */
    public static function descriptorType(): string
    {
        return '{ i64, ptr, ptr, ptr, ptr, ptr, i64, ptr, ptr }';
    }

    /** `@__mir_cmpview_<id>(ptr %o) -> i64` ({@see \Compile\MemoryAbi::DESCRIPTOR_CMP_VIEW_FN_OFFSET}). */
    public static function cmpViewFnSymbol(int $id): string
    {
        return '@__mir_cmpview_' . (string)$id;
    }

    /**
     * The full `@__mir_cd_<id> = linkonce_odr global …` definition.
     *
     * `$rmetaFld` is `ptr null` for a class no reflection reaches — the opt-in
     * gate. The field costs 8 rodata bytes per class either way; appending it
     * leaves class_id@0 and drop_fn@8 where they were, so no reader moves.
     */
    public static function descriptorGlobal(
        int $id,
        string $dropFld,
        string $rmetaFld = 'ptr null',
        string $dynFld = 'ptr null',
        string $propsFld = 'ptr null',
        string $cmpViewFld = 'ptr null',
        ?int $cmpGroup = null,
        string $jsonFld = 'ptr null',
        string $visitFld = 'ptr null',
    ): string {
        return '@__mir_cd_' . (string)$id . ' = linkonce_odr global ' . self::descriptorType()
            . ' { i64 ' . (string)$id . ', ' . $dropFld . ', ' . $rmetaFld . ', ' . $dynFld
            . ', ' . $propsFld . ', ' . $cmpViewFld . ', i64 ' . (string)($cmpGroup ?? $id) . ', ' . $jsonFld
            . ', ' . $visitFld . " }\n";
    }

    /** {@see \Compile\MemoryAbi::DESCRIPTOR_VISIT_FN_OFFSET} */
    public static function visitFnSymbol(int $id): string
    {
        return '@__mir_pvisit_' . (string)$id;
    }

    /** The synthesized PHP helper behind {@see \Compile\MemoryAbi::DESCRIPTOR_JSON_FN_OFFSET}. */
    public static function jsonSerFn(int $id): string
    {
        return '__mc_jsonser_' . (string)$id;
    }

    /**
     * `@__mir_json_ser(i64 cell) -> i64` — what json encodes IN PLACE of the
     * object in `cell`: its `jsonSerialize()` result, or the object itself for
     * a class without one. Always an owned cell; the caller tells the two
     * apart by the payload.
     */
    public function jsonSer(): string
    {
        $mask = (string)\Compile\MemoryAbi::CELL_PAYLOAD_MASK;
        $out  = "\ndefine i64 @__mir_json_ser(i64 %cell) {\nentry:\n";
        $out .= "  %pay = and i64 %cell, " . $mask . "\n";
        $out .= "  %p = inttoptr i64 %pay to ptr\n";
        $out .= "  %desc = load ptr, ptr %p\n";
        $out .= "  %dn = icmp eq ptr %desc, null\n";
        $out .= "  br i1 %dn, label %self, label %have\n";
        $out .= "have:\n";
        $out .= "  %fp = getelementptr inbounds i8, ptr %desc, i64 "
              . (string)\Compile\MemoryAbi::DESCRIPTOR_JSON_FN_OFFSET . "\n";
        $out .= "  %f = load ptr, ptr %fp\n";
        $out .= "  %fn = icmp eq ptr %f, null\n";
        $out .= "  br i1 %fn, label %self, label %ser\n";
        $out .= "ser:\n";
        $out .= "  %r = call i64 %f(i64 %cell)\n";
        $out .= "  ret i64 %r\n";
        $out .= "self:\n";
        $out .= "  call void @__mir_cell_retain(i64 %cell)\n";
        $out .= "  ret i64 %cell\n}\n";
        return $out;
    }

    /**
     * `@__mir_props_<id>(ptr %o) -> i64` — the class's DECLARED properties as an
     * `assoc[string, cell]`, keyed and ordered by declaration.
     *
     * A generic runtime helper cannot enumerate a user class: it is
     * `linkonce_odr` and coalesces BY NAME, so it must never be specialized from
     * module-local information. The class table it would need does not exist in
     * `manticore_stdlib.o` at all — which is why `json_encode($object)` answered
     * `{}` for every object even after the argument bug above was fixed.
     *
     * A pointer ON THE DESCRIPTOR closes that: the function is a pure function
     * of the CLASS, emitted beside the descriptor wherever the class is defined,
     * so every module agrees on it and `linkonce_odr` may coalesce it. The
     * generic side just loads the field and calls it.
     */
    public static function propsFnSymbol(int $id): string
    {
        return '@__mir_props_' . (string)$id;
    }

    /** Lightweight dynamic-method row: stable name plus uniform thunk pointer. */
    public static function dynamicMethodRowType(): string
    {
        return '{ ptr, ptr, ptr, i64 }';
    }

    /** Lightweight per-class dynamic-method table: rows plus optional __call thunk. */
    public static function dynamicMethodTableType(): string
    {
        // The third field is deliberately table-local rather than a descriptor
        // field: it preserves the ABI-8 descriptor layout and lets an erased
        // dynamic miss reroute to a class's ordinary __call implementation.
        return '{ i64, ptr, ptr }';
    }

    /** Build one immutable dynamic-method table global. */
    public static function dynamicMethodTable(string $sym, array $rowsIr): array
    {
        $n = \count($rowsIr);
        if ($n === 0) { return ['', 'ptr null']; }
        $def = $sym . ' = linkonce_odr constant [' . (string)$n . ' x '
             . self::dynamicMethodRowType() . '] [' . \implode(', ', $rowsIr) . "]\n";
        return [$def, 'ptr ' . $sym];
    }

    /** Build one `{ ptr name, ptr trampoline, ptr declaring class, i64 visibility }` row. */
    public static function dynamicMethodRow(string $nameIr, string $tramp, string $declIr, int $vis): string
    {
        return self::dynamicMethodRowType() . ' { ptr ' . $nameIr . ', ptr ' . $tramp
             . ', ptr ' . $declIr . ', i64 ' . (string)$vis . ' }';
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
        return '{ ptr, i64, i64, ptr, i64, ptr, i64, ptr, ptr, i64, ptr, ptr, ptr }';
    }

    /** One method/property row:
     *  `{ ptr name, i64 flags, ptr tramp, i64 arity, i64 nparams, ptr params,
     *     i64 nattrs, ptr attrs, ptr rettype }`. */
    public static function rmetaRowType(): string
    {
        return '{ ptr, i64, ptr, i64, i64, ptr, i64, ptr, ptr }';
    }

    /** One parameter entry: `{ ptr name, ptr type, i64 flags }`. */
    public static function rmetaParamType(): string
    {
        return '{ ptr, ptr, i64, ptr, i64, ptr }';
    }

    /** One attribute entry: `{ ptr name, ptr args_fn, ptr new_fn, i64 target,
     *  i64 repeated, ptr err }`. */
    public static function rmetaAttrType(): string
    {
        return '{ ptr, ptr, ptr, i64, i64, ptr }';
    }

    /**
     * An attribute table: `[N x { ptr name, ptr args_factory, ptr new_factory }]`
     * plus the `{ count, ptr }` pair. Empty ⇒ `i64 0, ptr null`. `$rowsIr` are
     * pre-built `{ … }` bodies (the caller interpolates each from concrete
     * locals — the same self-host discipline as {@see rmetaTable}).
     *
     * @param string[] $rowsIr
     * @return string[] [globalDef, countAndPtrFields]
     */
    public static function rmetaAttrTable(string $sym, array $rowsIr): array
    {
        $n = \count($rowsIr);
        if ($n === 0) { return ['', 'i64 0, ptr null']; }
        $def = $sym . ' = linkonce_odr constant [' . (string)$n . ' x ' . self::rmetaAttrType()
             . '] [' . \implode(', ', $rowsIr) . "]\n";
        return [$def, 'i64 ' . (string)$n . ', ptr ' . $sym];
    }

    /** One attribute row body: name + the two factory pointer fields (each a
     *  `ptr @manticore_…` or `ptr null`). */
    public static function rmetaAttrRow(string $nameIr, string $argsFld, string $newFld,
                                        int $target, int $repeated, string $errFld): string
    {
        return self::rmetaAttrType() . ' { ptr ' . $nameIr . ', ' . $argsFld . ', ' . $newFld
             . ', i64 ' . (string)$target . ', i64 ' . (string)$repeated . ', ' . $errFld . ' }';
    }

    /**
     * A method's parameter table:
     * `[N x { ptr name, ptr type, i64 flags, ptr attrs, i64 nattrs, ptr default_fn }]`,
     * plus the `{ count, ptr }` pair. Empty ⇒ `{ i64 0, ptr null }` (a no-arg method).
     *
     * @param string[] $namesIr per param: the name's data-ptr IR
     * @param string[] $typesIr per param: the type-name data-ptr IR, or 'null'
     * @param int[]    $flags   per param: the packed RMETA_PARAM_* word
     * @param string[] $attrsIr per param: its attribute table symbol, or 'null'
     * @param int[]    $nattrs  per param: how many attribute rows that table has
     * @param string[] $defFns  per param: `@<mangled default factory>`, or 'null'
     * @return string[] [globalDef, tableSym|'null'] — both STRINGS (the count is
     *   `count($params)`, computed by the caller). A heterogeneous return tuple
     *   would erase to vec[cell] and mis-decode under the native self-host.
     */
    public static function rmetaParamTable(string $sym, array $namesIr, array $typesIr, array $flags,
                                           array $attrsIr = [], array $nattrs = [], array $defFns = []): array
    {
        $n = \count($flags);
        if ($n === 0) { return ['', 'null']; }
        $items = [];
        for ($i = 0; $i < $n; $i = $i + 1) {
            $at = $attrsIr[$i] ?? 'null';
            $an = $nattrs[$i] ?? 0;
            $df = $defFns[$i] ?? 'null';
            $items[] = self::rmetaParamType() . ' { ptr ' . $namesIr[$i] . ', ptr ' . $typesIr[$i]
                 . ', i64 ' . (string)$flags[$i]
                 . ', ptr ' . $at . ', i64 ' . (string)$an . ', ptr ' . $df . ' }';
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

    /** One method/property row body, fields interpolated by the caller.
     *  `$nattrs`/`$attrsIr` are the member's attribute table (0 / 'null' when it
     *  carries none). */
    public static function rmetaRow(string $nameIr, int $flags, string $tramp, int $arity, int $nparams, string $paramsIr, int $nattrs = 0, string $attrsIr = 'null', string $retTypeIr = 'null'): string
    {
        return self::rmetaRowType() . ' { ptr ' . $nameIr . ', i64 ' . (string)$flags
             . ', ptr ' . $tramp . ', i64 ' . (string)$arity
             . ', i64 ' . (string)$nparams . ', ptr ' . $paramsIr
             . ', i64 ' . (string)$nattrs . ', ptr ' . $attrsIr
             . ', ptr ' . $retTypeIr . ' }';
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
        string $ctorTrampFld = 'ptr null',
        string $attrsFlds = 'i64 0, ptr null',
        string $constsFnFld = 'ptr null',
        string $ifacesFnFld = 'ptr null'
    ): string {
        return '@__mc_rmeta_v3_' . $id . ' = linkonce_odr constant ' . self::rmetaType()
            . ' { ' . $nameFld . ', i64 ' . (string)$flags . ', i64 ' . (string)$parentId
            . ', ' . $parentNameFld . ', ' . $methodsFlds . ', ' . $propsFlds
            . ', ' . $ctorTrampFld . ', ' . $attrsFlds . ', ' . $constsFnFld
            . ', ' . $ifacesFnFld . " }\n";
    }

    /** The rmeta pointer field for a descriptor: the class's block, or null
     *  when nothing reflects it (the opt-in gate — Ф1b decides; Ф1a fills all).
     *  `_v2_` since Ф2 widened the layout (rows gained tramp/arity, the struct
     *  gained ctor_tramp) — a version bump so a mismatched object is a link
     *  error, not silent linkonce_odr coalescing onto the wrong shape. */
    public static function rmetaField(int $id): string
    {
        return 'ptr @__mc_rmeta_v3_' . (string)$id;
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
        $out = $node . ' = linkonce_odr global ' . $t . ' { ptr @__mc_rmeta_v3_' . $sid
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
    public static function reflRegistry(array $ids, array $extraCtors = []): string
    {
        $out = "@__mc_refl_head = linkonce_odr global ptr null\n";
        $entries = [];
        foreach ($ids as $id) {
            $entries[] = '{ i32, ptr, ptr } { i32 65535, ptr @__mc_refl_reg_' . $id . ', ptr null }';
        }
        // Ф5 — free-function registry ctors share this one @llvm.global_ctors
        // array (LLVM allows only a single such global per module).
        foreach ($extraCtors as $sym) {
            $entries[] = '{ i32, ptr, ptr } { i32 65535, ptr ' . $sym . ', ptr null }';
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
        $out .= "miss:\n";
        // Not a declared class — it may be an ALIAS. `class_alias('A', 'B')`
        // cannot make a new class in a closed world, but it can make B a second
        // NAME for A's metadata, and every name-resolved question (class_exists,
        // ReflectionClass, an erased `new $n`) comes through here.
        $out .= "  %al = call i64 @__mc_alias_find(ptr %name)\n";
        $out .= "  ret i64 %al\n}\n";
        $out .= self::classAliasRuntime();
        $out .= self::reflMemberLookup();
        $out .= self::reflMemberTramp();
        $out .= self::reflMethodRow();
        $out .= self::reflPropRow();
        return $out;
    }

    /**
     * `class_alias()` — a second NAME for a class's metadata.
     *
     * A closed-world AOT compiler cannot mint a class at run time, but the
     * alias does not ask for one: it asks that a NAME resolve to an existing
     * class. So the alias list sits behind {@see reflRegistry}'s find — a miss
     * on the declared set falls through to it — and every name-resolved
     * question inherits the answer with no further plumbing.
     *
     * NOT indexed with the declared classes: the hash index reads each entry's
     * name out of its RMETA, and an alias's whole point is that its name is not
     * the one in there. A linear list is right for the size — a program has a
     * handful of aliases, against thousands of classes.
     */
    private static function classAliasRuntime(): string
    {
        $out = "@__mc_alias_head = linkonce_odr global ptr null\n";
        // node: { ptr name, i64 rmeta, ptr next }
        $out .= "define i64 @__mc_class_alias(ptr %orig, ptr %alias) {\nentry:\n";
        $out .= "  %m = call i64 @__mc_refl_find(ptr %orig)\n";
        $out .= "  %none = icmp eq i64 %m, 0\n";
        $out .= "  br i1 %none, label %no, label %yes\n";
        $out .= "no:\n  ret i64 0\n";
        $out .= "yes:\n";
        $out .= "  %n = call ptr @__mir_alloc_tagged(i64 24)\n";
        $out .= "  store ptr %alias, ptr %n\n";
        $out .= "  %mp = getelementptr i8, ptr %n, i64 8\n";
        $out .= "  store i64 %m, ptr %mp\n";
        $out .= "  %hd = load ptr, ptr @__mc_alias_head\n";
        $out .= "  %np = getelementptr i8, ptr %n, i64 16\n";
        $out .= "  store ptr %hd, ptr %np\n";
        $out .= "  store ptr %n, ptr @__mc_alias_head\n";
        $out .= "  ret i64 1\n}\n";
        $out .= "define i64 @__mc_alias_find(ptr %name) {\nentry:\n";
        $out .= "  %h0 = load ptr, ptr @__mc_alias_head\n";
        $out .= "  br label %loop\n";
        $out .= "loop:\n";
        $out .= "  %p = phi ptr [ %h0, %entry ], [ %nx, %cont ]\n";
        $out .= "  %end = icmp eq ptr %p, null\n";
        $out .= "  br i1 %end, label %miss, label %body\n";
        $out .= "body:\n";
        $out .= "  %nm = load ptr, ptr %p\n";
        $out .= "  %c = call i32 @strcmp(ptr %nm, ptr %name)\n";
        $out .= "  %eq = icmp eq i32 %c, 0\n";
        $out .= "  br i1 %eq, label %hit, label %cont\n";
        $out .= "hit:\n";
        $out .= "  %mp = getelementptr i8, ptr %p, i64 8\n";
        $out .= "  %mv = load i64, ptr %mp\n";
        $out .= "  ret i64 %mv\n";
        $out .= "cont:\n";
        $out .= "  %nxp = getelementptr i8, ptr %p, i64 16\n";
        $out .= "  %nx = load ptr, ptr %nxp\n";
        $out .= "  br label %loop\n";
        $out .= "miss:\n  ret i64 0\n}\n";
        return $out;
    }

    /**
     * `__mc_refl_prow(i64 h, ptr name) -> i64` — a PROPERTY row's address (as
     * i64), or 0 when absent. The prelude's ReflectionProperty caches it, then
     * reads the property's type + accessor pointers off the extra struct its
     * `params` slot ({@see \Compile\MemoryAbi::RMETA_ROW_PARAMS_OFFSET}) points
     * at. The property table walk — {@see reflMethodRow} over the method table.
     */
    private static function reflPropRow(): string
    {
        $np = (string)\Compile\MemoryAbi::RMETA_NPROPS_OFFSET;
        $pt = (string)\Compile\MemoryAbi::RMETA_PROPS_OFFSET;
        $rs = (string)\Compile\MemoryAbi::RMETA_ROW_SIZE;
        $out = "define i64 @__mc_refl_prow(i64 %h, ptr %name) {\nentry:\n";
        $out .= "  %hz = icmp eq i64 %h, 0\n";
        $out .= "  br i1 %hz, label %miss, label %have\n";
        $out .= "have:\n";
        $out .= "  %m = inttoptr i64 %h to ptr\n";
        $out .= '  %cntP = getelementptr i8, ptr %m, i64 ' . $np . "\n";
        $out .= "  %cnt = load i64, ptr %cntP\n";
        $out .= '  %tabP = getelementptr i8, ptr %m, i64 ' . $pt . "\n";
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
        // The string hash: FNV-1a over little-endian 8-byte words, the tail bytes,
        // then fmix64 — {@see \Compile\Mir\Passes\EmitLlvm::fnvHash64} and
        // __mir_array_hash_str compute the same, and share this cache word.
        $out .= "  br label %wl\n";
        $out .= "wl:\n";
        $out .= "  %wi = phi i64 [ 0, %calc ], [ %wi8, %wb ]\n";
        $out .= "  %wh = phi i64 [ -3750763034362895579, %calc ], [ %whm, %wb ]\n";
        $out .= "  %wi8 = add i64 %wi, 8\n";
        $out .= "  %wfit = icmp sle i64 %wi8, %len\n";
        $out .= "  br i1 %wfit, label %wb, label %tl\n";
        $out .= "wb:\n";
        $out .= "  %wp = getelementptr i8, ptr %p, i64 %wi\n";
        $out .= "  %w = load i64, ptr %wp, align 1\n";
        $out .= "  %whx = xor i64 %wh, %w\n";
        $out .= "  %whm = mul i64 %whx, 1099511628211\n";
        $out .= "  br label %wl\n";
        $out .= "tl:\n";
        $out .= "  %ti = phi i64 [ %wi, %wl ], [ %ti1, %tb ]\n";
        $out .= "  %th = phi i64 [ %wh, %wl ], [ %thm, %tb ]\n";
        $out .= "  %tmore = icmp ult i64 %ti, %len\n";
        $out .= "  br i1 %tmore, label %tb, label %done\n";
        $out .= "tb:\n";
        $out .= "  %bp = getelementptr i8, ptr %p, i64 %ti\n";
        $out .= "  %b = load i8, ptr %bp\n";
        $out .= "  %bz = zext i8 %b to i64\n";
        $out .= "  %thx = xor i64 %th, %bz\n";
        $out .= "  %thm = mul i64 %thx, 1099511628211\n";
        $out .= "  %ti1 = add i64 %ti, 1\n";
        $out .= "  br label %tl\n";
        $out .= "done:\n";
        $out .= "  %f1s = lshr i64 %th, 33\n  %f1 = xor i64 %th, %f1s\n";
        $out .= "  %f2 = mul i64 %f1, -49064778989728563\n";
        $out .= "  %f2s = lshr i64 %f2, 33\n  %f3 = xor i64 %f2, %f2s\n";
        $out .= "  %f4 = mul i64 %f3, -4265267296055464877\n";
        $out .= "  %f4s = lshr i64 %f4, 33\n  %hf = xor i64 %f4, %f4s\n";
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
     * Uniform dynamic-method ABI for fixed-arity, non-reference, non-variadic
     * methods. The caller passes the raw receiver pointer and a boxed argument
     * vector; the method row resolves the compiler-owned trampoline once and the
     * trampoline performs the typed call/default handling.
     */
    /** Runtime lookup in a lightweight `{ count, rows }` method table. */
    public static function dynamicMethodRuntime(): string
    {
        $out = "define i64 @__mc_dyn_method_lookup(i64 %table, ptr %name) {\nentry:\n";
        $out .= "  %tp = inttoptr i64 %table to ptr\n";
        $out .= "  %cp = getelementptr i8, ptr %tp, i64 0\n";
        $out .= "  %cnt = load i64, ptr %cp\n";
        $out .= "  %rp = getelementptr i8, ptr %tp, i64 8\n";
        $out .= "  %rows = load ptr, ptr %rp\n";
        $out .= "  br label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [ 0, %entry ], [ %i1, %next ]\n";
        $out .= "  %done = icmp uge i64 %i, %cnt\n";
        $out .= "  br i1 %done, label %miss, label %check\n";
        $out .= "check:\n";
        $out .= '  %off = mul i64 %i, ' . (string)\Compile\MemoryAbi::DYN_METHOD_ROW_SIZE . "\n";
        $out .= "  %row = getelementptr i8, ptr %rows, i64 %off\n";
        $out .= "  %np = load ptr, ptr %row\n";
        $out .= "  %cmp = call i32 @strcmp(ptr %np, ptr %name)\n";
        $out .= "  %eq = icmp eq i32 %cmp, 0\n";
        $out .= "  br i1 %eq, label %hit, label %next\n";
        $out .= "hit:\n";
        $out .= '  %fp = getelementptr i8, ptr %row, i64 ' . (string)\Compile\MemoryAbi::DYN_METHOD_ROW_TRAMP_OFFSET . "\n";
        $out .= "  %fv = load ptr, ptr %fp\n";
        $out .= "  %fi = ptrtoint ptr %fv to i64\n";
        $out .= "  ret i64 %fi\n";
        $out .= "next:\n";
        $out .= "  %i1 = add i64 %i, 1\n";
        $out .= "  br label %loop\n";
        $out .= "miss:\n  ret i64 0\n}\n";
        // A null trampoline means "declared but not eligible for the uniform
        // ABI". This predicate keeps that case separate from a genuinely absent
        // method, so a class __call fallback cannot steal a real variadic,
        // by-reference, private, or otherwise unsupported method.
        $out .= "define i1 @__mc_dyn_method_has(i64 %table, ptr %name) {\nentry:\n";
        $out .= "  %tp = inttoptr i64 %table to ptr\n";
        $out .= "  %cntp = getelementptr i8, ptr %tp, i64 0\n";
        $out .= "  %cnt = load i64, ptr %cntp\n";
        $out .= "  %rp = getelementptr i8, ptr %tp, i64 8\n";
        $out .= "  %rows = load ptr, ptr %rp\n";
        $out .= "  br label %hloop\n";
        $out .= "hloop:\n";
        $out .= "  %i = phi i64 [ 0, %entry ], [ %i1, %hnext ]\n";
        $out .= "  %done = icmp uge i64 %i, %cnt\n";
        $out .= "  br i1 %done, label %hmiss, label %hcheck\n";
        $out .= "hcheck:\n";
        $out .= '  %off = mul i64 %i, ' . (string)\Compile\MemoryAbi::DYN_METHOD_ROW_SIZE . "\n";
        $out .= "  %row = getelementptr i8, ptr %rows, i64 %off\n";
        $out .= "  %np = load ptr, ptr %row\n";
        $out .= "  %cmp = call i32 @strcmp(ptr %np, ptr %name)\n";
        $out .= "  %eq = icmp eq i32 %cmp, 0\n";
        $out .= "  br i1 %eq, label %hhit, label %hnext\n";
        $out .= "hhit:\n  ret i1 true\n";
        $out .= "hnext:\n";
        $out .= "  %i1 = add i64 %i, 1\n";
        $out .= "  br label %hloop\n";
        $out .= "hmiss:\n  ret i1 false\n}\n";
        $descOff = (string)\Compile\MemoryAbi::DESCRIPTOR_DYN_METHODS_OFFSET;
        // Try-call is the semantics-preserving boundary for erased callers:
        // ordinary rows and the class-specific __call fallback write the result
        // and return 1; an unsupported/missing method returns 0 so the caller
        // can execute the existing inline dispatcher instead of silently turning
        // the PHP call into a null result.
        $out .= "define i1 @__mc_dyn_method_try_call(i64 %obj, ptr %name, ptr %args, ptr %outp) {\nentry:\n";
        $out .= "  %objp = inttoptr i64 %obj to ptr\n";
        $out .= "  %descI = load i64, ptr %objp\n";
        $out .= "  %descNull = icmp eq i64 %descI, 0\n";
        $out .= "  br i1 %descNull, label %miss, label %haveDesc\n";
        $out .= "haveDesc:\n";
        $out .= "  %descp = inttoptr i64 %descI to ptr\n";
        $out .= '  %dynP = getelementptr i8, ptr %descp, i64 ' . $descOff . "\n";
        $out .= "  %dyn = load ptr, ptr %dynP\n";
        $out .= "  %dynNull = icmp eq ptr %dyn, null\n";
        $out .= "  br i1 %dynNull, label %miss, label %lookup\n";
        $out .= "lookup:\n";
        $out .= "  %tableI = ptrtoint ptr %dyn to i64\n";
        $out .= "  %tramp = call i64 @__mc_dyn_method_lookup(i64 %tableI, ptr %name)\n";
        $out .= "  %trampNull = icmp eq i64 %tramp, 0\n";
        $out .= "  br i1 %trampNull, label %declaredCheck, label %invoke\n";
        $out .= "declaredCheck:\n";
        $out .= "  %declared = call i1 @__mc_dyn_method_has(i64 %tableI, ptr %name)\n";
        $out .= "  br i1 %declared, label %miss, label %magicLookup\n";
        $out .= "invoke:\n";
        $out .= "  %fp = inttoptr i64 %tramp to ptr\n";
        $out .= "  %argsI = ptrtoint ptr %args to i64\n";
        $out .= "  %result = call i64 %fp(i64 %obj, i64 %argsI)\n";
        $out .= "  store i64 %result, ptr %outp\n";
        $out .= "  ret i1 true\n";
        $out .= "magicLookup:\n";
        $out .= "  %magicP = getelementptr i8, ptr %dyn, i64 16\n";
        $out .= "  %magic = load ptr, ptr %magicP\n";
        $out .= "  %magicNull = icmp eq ptr %magic, null\n";
        $out .= "  br i1 %magicNull, label %miss, label %invokeMagic\n";
        $out .= "invokeMagic:\n";
        $out .= "  %magicI = ptrtoint ptr %magic to i64\n";
        $out .= "  %nameI = ptrtoint ptr %name to i64\n";
        $out .= "  %magicArgsI = ptrtoint ptr %args to i64\n";
        $out .= "  %magicFp = inttoptr i64 %magicI to ptr\n";
        $out .= "  %magicResult = call i64 %magicFp(i64 %obj, i64 %nameI, i64 %magicArgsI)\n";
        $out .= "  store i64 %magicResult, ptr %outp\n";
        $out .= "  ret i1 true\n";
        $out .= "miss:\n  ret i1 false\n}\n";
        $out .= "define i64 @__mc_dyn_method_call(i64 %obj, ptr %name, ptr %args) {\nentry:\n";
        $out .= "  %objp = inttoptr i64 %obj to ptr\n";
        $out .= "  %descI = load i64, ptr %objp\n";
        $out .= "  %descNull = icmp eq i64 %descI, 0\n";
        $out .= "  br i1 %descNull, label %miss2, label %haveDesc\n";
        $out .= "haveDesc:\n";
        $out .= "  %descp = inttoptr i64 %descI to ptr\n";
        $out .= '  %dynP = getelementptr i8, ptr %descp, i64 ' . $descOff . "\n";
        $out .= "  %dyn = load ptr, ptr %dynP\n";
        $out .= "  %dynNull = icmp eq ptr %dyn, null\n";
        $out .= "  br i1 %dynNull, label %miss2, label %lookup2\n";
        $out .= "lookup2:\n";
        $out .= "  %tableI = ptrtoint ptr %dyn to i64\n";
        $out .= "  %tramp = call i64 @__mc_dyn_method_lookup(i64 %tableI, ptr %name)\n";
        $out .= "  %trampNull = icmp eq i64 %tramp, 0\n";
        $out .= "  br i1 %trampNull, label %magic2, label %invoke2\n";
        $out .= "magic2:\n";
        $out .= "  %magicP = getelementptr i8, ptr %dyn, i64 16\n";
        $out .= "  %magic = load ptr, ptr %magicP\n";
        $out .= "  %magicNull = icmp eq ptr %magic, null\n";
        $out .= "  br i1 %magicNull, label %miss2, label %invokeMagic2\n";
        $out .= "invokeMagic2:\n";
        $out .= "  %magicI = ptrtoint ptr %magic to i64\n";
        $out .= "  %nameI = ptrtoint ptr %name to i64\n";
        $out .= "  %magicArgsI = ptrtoint ptr %args to i64\n";
        $out .= "  %magicFp = inttoptr i64 %magicI to ptr\n";
        $out .= "  %magicResult = call i64 %magicFp(i64 %obj, i64 %nameI, i64 %magicArgsI)\n";
        $out .= "  ret i64 %magicResult\n";
        $out .= "invoke2:\n";
        $out .= "  %fp = inttoptr i64 %tramp to ptr\n";
        $out .= "  %argsI = ptrtoint ptr %args to i64\n";
        $out .= "  %result = call i64 %fp(i64 %obj, i64 %argsI)\n";
        $out .= "  ret i64 %result\n";
        $out .= "miss2:\n  ret i64 0\n}\n";
        $out .= self::dynamicMethodDispatch();
        return $out;
    }

    /**
     * `i1 @__mc_dyn_method_dispatch(i64 obj, ptr name, ptr args, ptr scope,
     *  ptr rel, i64 nrel, ptr outp)` — php's resolution of an ERASED
     * `$obj->$name(...$args)` called from class `scope` ('' = global scope).
     *
     * A declared method the scope may see runs through its trampoline; one it
     * may not see — private outside its declaring class, protected outside that
     * class's hierarchy (`rel` lists the scope's ancestors and descendants) —
     * and an undeclared name go to the class's `__call`, or throw php's Error
     * through `__mc_dyn_method_error`. 1 = handled (result in `outp`); 0 = a
     * visible method with no uniform trampoline (by-ref, variadic, abstract) or
     * a class without a table (prelude): the caller's inline arms own those.
     */
    private static function dynamicMethodDispatch(): string
    {
        $rs = (string)\Compile\MemoryAbi::DYN_METHOD_ROW_SIZE;
        $tr = (string)\Compile\MemoryAbi::DYN_METHOD_ROW_TRAMP_OFFSET;
        $dc = (string)\Compile\MemoryAbi::DYN_METHOD_ROW_DECL_OFFSET;
        $vs = (string)\Compile\MemoryAbi::DYN_METHOD_ROW_VIS_OFFSET;
        $descOff = (string)\Compile\MemoryAbi::DESCRIPTOR_DYN_METHODS_OFFSET;
        $out = "define i1 @__mc_dyn_method_dispatch(i64 %obj, ptr %name, ptr %args, ptr %scope, ptr %rel, i64 %nrel, ptr %outp) {\nentry:\n";
        $out .= "  %objp = inttoptr i64 %obj to ptr\n";
        $out .= "  %descI = load i64, ptr %objp\n";
        $out .= "  %descNull = icmp eq i64 %descI, 0\n";
        $out .= "  br i1 %descNull, label %inline, label %haveDesc\n";
        $out .= "haveDesc:\n";
        $out .= "  %descp = inttoptr i64 %descI to ptr\n";
        $out .= '  %dynP = getelementptr i8, ptr %descp, i64 ' . $descOff . "\n";
        $out .= "  %dyn = load ptr, ptr %dynP\n";
        $out .= "  %dynNull = icmp eq ptr %dyn, null\n";
        $out .= "  br i1 %dynNull, label %inline, label %scan\n";
        $out .= "scan:\n";
        $out .= "  %cnt = load i64, ptr %dyn\n";
        $out .= "  %rowsP = getelementptr i8, ptr %dyn, i64 8\n";
        $out .= "  %rows = load ptr, ptr %rowsP\n";
        $out .= "  br label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [ 0, %scan ], [ %i1, %next ]\n";
        $out .= "  %done = icmp uge i64 %i, %cnt\n";
        $out .= "  br i1 %done, label %absent, label %check\n";
        $out .= "check:\n";
        $out .= '  %off = mul i64 %i, ' . $rs . "\n";
        $out .= "  %row = getelementptr i8, ptr %rows, i64 %off\n";
        $out .= "  %np = load ptr, ptr %row\n";
        $out .= "  %c = call i32 @strcmp(ptr %np, ptr %name)\n";
        $out .= "  %eq = icmp eq i32 %c, 0\n";
        $out .= "  br i1 %eq, label %found, label %next\n";
        $out .= "next:\n";
        $out .= "  %i1 = add i64 %i, 1\n";
        $out .= "  br label %loop\n";
        $out .= "found:\n";
        $out .= '  %visP = getelementptr i8, ptr %row, i64 ' . $vs . "\n";
        $out .= "  %vis = load i64, ptr %visP\n";
        $out .= '  %declP = getelementptr i8, ptr %row, i64 ' . $dc . "\n";
        $out .= "  %decl = load ptr, ptr %declP\n";
        $out .= "  %isPub = icmp eq i64 %vis, " . (string)\Compile\MemoryAbi::DYN_METHOD_VIS_PUBLIC . "\n";
        $out .= "  br i1 %isPub, label %visible, label %notPub\n";
        $out .= "notPub:\n";
        // nrel < 0 checks nothing: a callable ARRAY is invoked wherever it was
        // handed (usort, array_map — prelude PHP here), and php judges it from
        // the scope that handed it over, which that call site does not know.
        $out .= "  %anyScope = icmp slt i64 %nrel, 0\n";
        $out .= "  br i1 %anyScope, label %visible, label %scoped\n";
        $out .= "scoped:\n";
        $out .= "  %sc = call i32 @strcmp(ptr %scope, ptr %decl)\n";
        $out .= "  %same = icmp eq i32 %sc, 0\n";
        $out .= "  br i1 %same, label %visible, label %notSame\n";
        $out .= "notSame:\n";
        $out .= "  %isProt = icmp eq i64 %vis, " . (string)\Compile\MemoryAbi::DYN_METHOD_VIS_PROTECTED . "\n";
        $out .= "  br i1 %isProt, label %relLoop, label %hidden\n";
        $out .= "relLoop:\n";
        $out .= "  %j = phi i64 [ 0, %notSame ], [ %j1, %relNext ]\n";
        $out .= "  %rdone = icmp uge i64 %j, %nrel\n";
        $out .= "  br i1 %rdone, label %hidden, label %relCheck\n";
        $out .= "relCheck:\n";
        $out .= "  %roff = mul i64 %j, 8\n";
        $out .= "  %rp = getelementptr i8, ptr %rel, i64 %roff\n";
        $out .= "  %rn = load ptr, ptr %rp\n";
        $out .= "  %rc = call i32 @strcmp(ptr %rn, ptr %decl)\n";
        $out .= "  %req = icmp eq i32 %rc, 0\n";
        $out .= "  br i1 %req, label %visible, label %relNext\n";
        $out .= "relNext:\n";
        $out .= "  %j1 = add i64 %j, 1\n";
        $out .= "  br label %relLoop\n";
        $out .= "visible:\n";
        $out .= '  %tp = getelementptr i8, ptr %row, i64 ' . $tr . "\n";
        $out .= "  %tramp = load ptr, ptr %tp\n";
        $out .= "  %tnull = icmp eq ptr %tramp, null\n";
        $out .= "  br i1 %tnull, label %inline, label %invoke\n";
        $out .= "invoke:\n";
        $out .= "  %argsI = ptrtoint ptr %args to i64\n";
        $out .= "  %result = call i64 %tramp(i64 %obj, i64 %argsI)\n";
        $out .= "  store i64 %result, ptr %outp\n";
        $out .= "  ret i1 true\n";
        $out .= "hidden:\n";
        $out .= "  br label %magic\n";
        $out .= "absent:\n";
        $out .= "  br label %magic\n";
        $out .= "magic:\n";
        $out .= "  %kind = phi i64 [ %vis, %hidden ], [ -1, %absent ]\n";
        $out .= "  %errDecl = phi ptr [ %decl, %hidden ], [ %name, %absent ]\n";
        $out .= "  %magicP = getelementptr i8, ptr %dyn, i64 16\n";
        $out .= "  %mfn = load ptr, ptr %magicP\n";
        $out .= "  %mnull = icmp eq ptr %mfn, null\n";
        $out .= "  br i1 %mnull, label %error, label %invokeMagic\n";
        $out .= "invokeMagic:\n";
        $out .= "  %nameI = ptrtoint ptr %name to i64\n";
        $out .= "  %margsI = ptrtoint ptr %args to i64\n";
        $out .= "  %mres = call i64 %mfn(i64 %obj, i64 %nameI, i64 %margsI)\n";
        $out .= "  store i64 %mres, ptr %outp\n";
        $out .= "  ret i1 true\n";
        $out .= "error:\n";
        $out .= "  %eNameI = ptrtoint ptr %name to i64\n";
        $out .= "  %eDeclI = ptrtoint ptr %errDecl to i64\n";
        $out .= "  %eScopeI = ptrtoint ptr %scope to i64\n";
        $out .= "  %eObj = or i64 %obj, " . (string)\Compile\MemoryAbi::CELL_OBJ . "\n";
        $out .= "  %eres = call i64 @manticore___mc_dyn_method_error(i64 %eObj,i64 %eNameI, i64 %kind, i64 %eDeclI, i64 %eScopeI)\n";
        $out .= "  store i64 %eres, ptr %outp\n";
        $out .= "  ret i1 true\n";
        $out .= "inline:\n  ret i1 false\n}\n";
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

        // A new LENGTH invalidates the cached hash. The header carries an FNV at
        // -32 that lookups trust (hashPrefilter skips a byte compare when two
        // known hashes differ), and a string mutated IN PLACE — which is the
        // whole point of __mir_str_append — kept the hash of its shorter self.
        // A stale hash makes the filter answer NOT EQUAL for two identical
        // strings: a lookup MISS, not a slow path. 0 means \"uncomputed\"
        // everywhere, so one store restores the invariant.
        $out .= "\ndefine void @__mir_str_set_len(ptr %s, i64 %n) {\nentry:\n";
        $out .= "  %lp = getelementptr inbounds i8, ptr %s, i64 -16\n";
        $out .= "  store i64 %n, ptr %lp\n";
        $out .= "  %hp = getelementptr inbounds i8, ptr %s, i64 -32\n";
        $out .= "  store i64 0, ptr %hp\n";
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
        // `===` over two string CARRIERS, either of which may be a `?string`'s
        // null (0): the same word is equal, a null beside a string is not, and
        // anything else compares bytes. It used to be three blocks and a phi at
        // every comparison site — 8 493 of them in the compiler's own module.
        $out .= "\ndefine i1 @__mir_str_eq_ns(i64 %a, i64 %b) {\nentry:\n";
        $out .= "  %same = icmp eq i64 %a, %b\n";
        $out .= "  br i1 %same, label %yes, label %nz\n";
        $out .= "yes:\n  ret i1 1\n";
        $out .= "nz:\n";
        $out .= "  %an = icmp eq i64 %a, 0\n";
        $out .= "  %bn = icmp eq i64 %b, 0\n";
        $out .= "  %nul = or i1 %an, %bn\n";
        $out .= "  br i1 %nul, label %no, label %cmp\n";
        $out .= "no:\n  ret i1 0\n";
        $out .= "cmp:\n";
        $out .= "  %pa = inttoptr i64 %a to ptr\n";
        $out .= "  %pb = inttoptr i64 %b to ptr\n";
        $out .= "  %r = call i1 @__mir_str_eq(ptr %pa, ptr %pb)\n";
        $out .= "  ret i1 %r\n}\n";

        // ── The 256 single-byte strings, interned ───────────────────────────
        //
        // A 1-character string has exactly 256 possible values, and both
        // producers of one — `$s[$i]` and `chr()` — used to malloc a fresh
        // 34-byte buffer per call. The compiler's own Lexer alone did that
        // once per source byte; DemoteCharLocals exists to dodge it, but only
        // where it can PROVE the character is never observed as a string.
        // Interning removes the allocation unconditionally, including from the
        // reads no proof covers.
        //
        // The table is `zeroinitializer` — .bss, so it costs NOTHING in the
        // binary (a 10 KB static initializer would have been ~10% of a hello
        // world) and is faulted in a page at a time as bytes are first used.
        // rc == 0 is the "not yet built" marker: a live entry carries the
        // immortal -1, which the rc runtime skips on BOTH retain and release,
        // so a caller that frees its string temps cannot free the table, and
        // `.=` on one copies instead of appending in place (that path requires
        // rc == 1). Both hazards are closed by the convention string literals
        // already use, not by a new rule.
        $stride = \Compile\MemoryAbi::STRING_HEADER_SIZE + 8;
        $out .= "\n@__mir_char_table = linkonce_odr global [" . (string)(256 * $stride)
              . " x i8] zeroinitializer, align 16\n";
        $out .= "define ptr @__mir_char_of(i64 %b) {\nentry:\n";
        $out .= "  %bm = and i64 %b, 255\n";
        $out .= "  %off = mul i64 %bm, " . (string)$stride . "\n";
        $out .= "  %ent = getelementptr inbounds i8, ptr @__mir_char_table, i64 %off\n";
        $out .= "  %datap = getelementptr inbounds i8, ptr %ent, i64 " . $nH . "\n";
        $out .= "  %rcp = getelementptr inbounds i8, ptr %ent, i64 " . $nRcAt . "\n";
        $out .= "  %rc = load i64, ptr %rcp\n";
        $out .= "  %fresh = icmp eq i64 %rc, 0\n";
        $out .= "  br i1 %fresh, label %fill, label %ret\n";
        $out .= "fill:\n";
        $out .= "  %hp = getelementptr inbounds i8, ptr %ent, i64 " . $nHashAt . "\n";
        $out .= "  store i64 0, ptr %hp\n";
        $out .= "  %ccp = getelementptr inbounds i8, ptr %ent, i64 " . $nCapAt . "\n";
        $out .= "  store i64 1, ptr %ccp\n";
        $out .= "  %llp = getelementptr inbounds i8, ptr %ent, i64 " . $nLenAt . "\n";
        $out .= "  store i64 1, ptr %llp\n";
        $out .= "  %b8 = trunc i64 %bm to i8\n";
        $out .= "  store i8 %b8, ptr %datap\n";
        $out .= "  %nulp = getelementptr inbounds i8, ptr %datap, i64 1\n";
        $out .= "  store i8 0, ptr %nulp\n";
        // rc LAST: it is the published marker, and nothing may observe an entry
        // as built before its bytes are there.
        $out .= "  store i64 -1, ptr %rcp\n";
        $out .= "  br label %ret\n";
        $out .= "ret:\n";
        $out .= "  ret ptr %datap\n}\n";

        // `$s[$i]` read — negative index counts from the end; out-of-range → "".
        // Returns the interned 1-char string (binary-safe, immortal).
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
        $out .= "  %cb = load i8, ptr %cp\n";
        $out .= "  %cbz = zext i8 %cb to i64\n";
        $out .= "  %r = call ptr @__mir_char_of(i64 %cbz)\n";
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
        //
        // The length is `len@-16` READ DIRECTLY, not __mir_strlen's validated
        // one: a demoted read sits in the innermost loop of every scanner, and
        // the plausibility test (three header loads, a libc-strlen fallback
        // that may write) kept the length from being hoisted — 17% of
        // htmlspecialchars, re-validating the same header per byte. The value
        // here is one the type system calls `string`; every producer of those
        // hands out a headered buffer ({@see stringCore}).
        $out .= "\ndefine i64 @__mir_str_byte_at(ptr %s, i64 %i) {\nentry:\n";
        $out .= "  %lp = getelementptr inbounds i8, ptr %s, i64 -16\n";
        $out .= "  %len = load i64, ptr %lp\n";
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
        // `%b` (the appended chunk) via __mir_strlen: O(1) + binary-safe for a
        // headered chunk, libc-strlen fallback for a raw one. The body takes
        // an explicit byte count so a RANGE of another string can be appended
        // without minting a substr temp first ({@see strAppendSub}).
        $out  = "\ndefine ptr @__mir_str_append(ptr %s, ptr %b) {\n";
        $out .= "entry:\n";
        $out .= "  %lb = call i64 @__mir_strlen(ptr %b)\n";
        $out .= "  %r = call ptr @__mir_str_append_n(ptr %s, ptr %b, i64 %lb)\n";
        $out .= "  ret ptr %r\n";
        $out .= "}\n";
        // `$acc .= substr($src, $start[, $len])` with no temp: Zend's substr
        // normalization ({@see EmitLlvmRuntime::stringBuiltinRuntime}), then
        // the range is appended straight out of `%src`.
        $out .= "\ndefine ptr @__mir_str_append_sub(ptr %s, ptr %src, i64 %start, i64 %len, i64 %haveLen) {\n";
        $out .= "entry:\n";
        $out .= "  %n = call i64 @__mir_strlen(ptr %src)\n";
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
        $out .= "  %p = getelementptr inbounds i8, ptr %src, i64 %start2\n";
        $out .= "  %r = call ptr @__mir_str_append_n(ptr %s, ptr %p, i64 %rlen)\n";
        $out .= "  ret ptr %r\n";
        $out .= "}\n";
        $out .= "\ndefine ptr @__mir_str_append_n(ptr %s, ptr %b, i64 %lb) {\n";
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
        $out .= "  %la = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %need = add i64 %la, %lb\n";        // content bytes after append
        $out .= "  %capp = getelementptr i8, ptr %s, i64 -24\n";
        $out .= "  %cap = load i64, ptr %capp\n";
        $out .= "  %fits = icmp slt i64 %need, %cap\n"; // need+1 (NUL) <= cap
        $out .= "  br i1 %fits, label %inplace, label %growsole\n";
        $out .= "inplace:\n";
        $out .= "  %dst = getelementptr inbounds i8, ptr %s, i64 %la\n";
        $out .= "  call ptr @memcpy(ptr %dst, ptr %b, i64 %lb)\n";
        $out .= "  %nulp = getelementptr inbounds i8, ptr %s, i64 %need\n";
        $out .= "  store i8 0, ptr %nulp\n";
        $out .= "  call void @__mir_str_set_len(ptr %s, i64 %need)\n";
        // Content changed under the same ptr → invalidate the cached hash.
        $out .= "  %hinv = getelementptr inbounds i8, ptr %s, i64 " . (string)\Compile\MemoryAbi::STRING_HASH_OFFSET . "\n";
        $out .= "  store i64 0, ptr %hinv\n";
        $out .= "  ret ptr %s\n";
        // ── SOLE-OWNER grow: extend the block instead of replacing it ──
        // Reached only from `chkcap`, i.e. rc == 1 AND the capacity ran out.
        // The old path allocates a NEW buffer, memcpy's the whole accumulator
        // into it and frees the old one, so a `$s .= …` loop leaves the sum of
        // every previous capacity resident: peak ≈ 2·L (the doubling chain) + L,
        // measured at 82.97 MB for a 30 MB result where php holds ~1.03·L.
        // `realloc` lets the allocator extend in place (or mremap), so nothing
        // but the live buffer stays resident and the copy disappears too.
        //
        // TWO guards, and both are load-bearing:
        //  - rc == 1 (inherited from `chkcap`) — an IMMORTAL literal and an
        //    ARENA string both carry rc = -1 ({@see EmitLlvmRuntime}'s
        //    `__mir_str_alloc_arena`), so neither can reach here; reallocating
        //    either would hand libc an address it never owned.
        //  - cap > the class-1 pool cap — a POOLED block came off a free list
        //    ({@see EmitLlvmRuntime}'s `__mir_str_alloc` classes 0/1) and must
        //    go back to it, never to `realloc`. Only the `big` arm is a plain
        //    `malloc(n + HEADER)`, which is exactly what `realloc` may take.
        $out .= "growsole:\n";
        $out .= "  %isbig = icmp ugt i64 %cap, "
              . (string)\Compile\MemoryAbi::STRING_POOL1_CAP . "\n";
        $out .= "  br i1 %isbig, label %rgrow, label %grow\n";
        $out .= "rgrow:\n";
        // 1.5× + slack rather than the copy path's 2×: with an in-place extend
        // the growth factor no longer buys amortization, so the tighter one is
        // free and halves the steady-state resident.
        $out .= "  %rhalf = lshr i64 %need, 1\n";
        $out .= "  %rsum = add i64 %need, %rhalf\n";
        $out .= "  %rcap = add i64 %rsum, 16\n";
        $out .= "  %rbase = getelementptr inbounds i8, ptr %s, i64 -"
              . (string)\Compile\MemoryAbi::STRING_HEADER_SIZE . "\n";
        $out .= "  %rtot = add i64 %rcap, "
              . (string)\Compile\MemoryAbi::STRING_HEADER_SIZE . "\n";
        $out .= "  %rnb = call ptr @realloc(ptr %rbase, i64 %rtot)\n";
        $out .= "  %rnd = getelementptr inbounds i8, ptr %rnb, i64 "
              . (string)\Compile\MemoryAbi::STRING_HEADER_SIZE . "\n";
        $out .= "  %rdst = getelementptr inbounds i8, ptr %rnd, i64 %la\n";
        $out .= "  call ptr @memcpy(ptr %rdst, ptr %b, i64 %lb)\n";
        $out .= "  %rnulp = getelementptr inbounds i8, ptr %rnd, i64 %need\n";
        $out .= "  store i8 0, ptr %rnulp\n";
        $out .= "  %rcapp = getelementptr inbounds i8, ptr %rnd, i64 -24\n";
        $out .= "  store i64 %rcap, ptr %rcapp\n";
        $out .= "  call void @__mir_str_set_len(ptr %rnd, i64 %need)\n";
        // Content moved and changed → the cached hash is stale.
        $out .= "  %rhinv = getelementptr inbounds i8, ptr %rnd, i64 "
              . (string)\Compile\MemoryAbi::STRING_HASH_OFFSET . "\n";
        $out .= "  store i64 0, ptr %rhinv\n";
        $out .= "  ret ptr %rnd\n";
        $out .= "grow:\n";
        $out .= "  %la2 = call i64 @__mir_strlen(ptr %s)\n";
        $out .= "  %sum = add i64 %la2, %lb\n";
        $out .= "  %dbl = shl i64 %sum, 1\n";         // over-allocate ~2×(la+lb)
        $out .= "  %ncap = add i64 %dbl, 1\n";        // room for content + NUL
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %ncap)\n";
        $out .= "  call ptr @memcpy(ptr %buf, ptr %s, i64 %la2)\n";
        $out .= "  %dst2 = getelementptr inbounds i8, ptr %buf, i64 %la2\n";
        $out .= "  call ptr @memcpy(ptr %dst2, ptr %b, i64 %lb)\n";
        $out .= "  %gnulp = getelementptr inbounds i8, ptr %buf, i64 %sum\n";
        $out .= "  store i8 0, ptr %gnulp\n";
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

    /**
     * Runtime: byte-wise `&` `|` `^` `~` over strings, Zend's `bitwise_*_function`.
     * `%op` 0 and, 1 or, 2 xor, 3 not (`%b` ignored). `&` and `^` answer
     * min(len) bytes; `|` answers max(len), the longer operand's tail copied
     * unchanged; `~` flips every byte of `%a`. Eight bytes a step over the
     * common prefix, then the tail bytes. Returns a fresh +1 string.
     */
    public function strBitop(): string
    {
        $out  = "\ndefine ptr @__mir_str_bitop(ptr %a, ptr %b, i64 %op) {\n";
        $out .= "entry:\n";
        $out .= "  %isnot = icmp eq i64 %op, 3\n";
        $out .= "  %isor = icmp eq i64 %op, 1\n";
        $out .= "  %isxor = icmp eq i64 %op, 2\n";
        $out .= "  %la = call i64 @__mir_strlen(ptr %a)\n";
        $out .= "  %b2 = select i1 %isnot, ptr %a, ptr %b\n";
        $out .= "  %lb = call i64 @__mir_strlen(ptr %b2)\n";
        $out .= "  %alt = icmp ult i64 %la, %lb\n";
        $out .= "  %mn = select i1 %alt, i64 %la, i64 %lb\n";
        $out .= "  %mx = select i1 %alt, i64 %lb, i64 %la\n";
        $out .= "  %n = select i1 %isor, i64 %mx, i64 %mn\n";
        $out .= "  %sz = add i64 %n, 1\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %sz)\n";
        $out .= "  %nw = lshr i64 %mn, 3\n";
        $out .= "  br label %wloop\n";
        $out .= "wloop:\n";
        $out .= "  %w = phi i64 [0, %entry], [%w2, %wbody]\n";
        $out .= "  %wdone = icmp uge i64 %w, %nw\n";
        $out .= "  br i1 %wdone, label %bpre, label %wbody\n";
        $out .= "wbody:\n";
        $out .= "  %wo = shl i64 %w, 3\n";
        $out .= "  %wpa = getelementptr inbounds i8, ptr %a, i64 %wo\n";
        $out .= "  %wpb = getelementptr inbounds i8, ptr %b2, i64 %wo\n";
        $out .= "  %wx = load i64, ptr %wpa, align 1\n";
        $out .= "  %wy = load i64, ptr %wpb, align 1\n";
        $out .= $this->bitopSelect('w', 'i64');
        $out .= "  %wpd = getelementptr inbounds i8, ptr %buf, i64 %wo\n";
        $out .= "  store i64 %wr, ptr %wpd, align 1\n";
        $out .= "  %w2 = add i64 %w, 1\n";
        $out .= "  br label %wloop\n";
        $out .= "bpre:\n";
        $out .= "  %i0 = shl i64 %nw, 3\n";
        $out .= "  br label %bloop\n";
        $out .= "bloop:\n";
        $out .= "  %i = phi i64 [%i0, %bpre], [%i2, %bbody]\n";
        $out .= "  %bdone = icmp uge i64 %i, %mn\n";
        $out .= "  br i1 %bdone, label %tail, label %bbody\n";
        $out .= "bbody:\n";
        $out .= "  %bpa = getelementptr inbounds i8, ptr %a, i64 %i\n";
        $out .= "  %bpb = getelementptr inbounds i8, ptr %b2, i64 %i\n";
        $out .= "  %bx = load i8, ptr %bpa\n";
        $out .= "  %by = load i8, ptr %bpb\n";
        $out .= $this->bitopSelect('b', 'i8');
        $out .= "  %bpd = getelementptr inbounds i8, ptr %buf, i64 %i\n";
        $out .= "  store i8 %br, ptr %bpd\n";
        $out .= "  %i2 = add i64 %i, 1\n";
        $out .= "  br label %bloop\n";
        $out .= "tail:\n";
        $out .= "  %rest = sub i64 %n, %mn\n";
        $out .= "  %long = select i1 %alt, ptr %b2, ptr %a\n";
        $out .= "  %tsrc = getelementptr inbounds i8, ptr %long, i64 %mn\n";
        $out .= "  %tdst = getelementptr inbounds i8, ptr %buf, i64 %mn\n";
        $out .= "  call ptr @memcpy(ptr %tdst, ptr %tsrc, i64 %rest)\n";
        $out .= "  %np = getelementptr inbounds i8, ptr %buf, i64 %n\n";
        $out .= "  store i8 0, ptr %np\n";
        $out .= "  ret ptr %buf\n";
        $out .= "}\n";
        return $out;
    }

    /** `%<p>x op %<p>y` → `%<p>r`, `op` chosen by the enclosing `%isor/%isxor/%isnot`. */
    private function bitopSelect(string $p, string $ty): string
    {
        $x = ' %' . $p . 'x';
        $y = ', %' . $p . 'y';
        $v = ' %' . $p;
        $out  = '  %' . $p . 'and = and ' . $ty . $x . $y . "\n";
        $out .= '  %' . $p . 'or = or ' . $ty . $x . $y . "\n";
        $out .= '  %' . $p . 'xor = xor ' . $ty . $x . $y . "\n";
        $out .= '  %' . $p . 'not = xor ' . $ty . $x . ", -1\n";
        $out .= '  %' . $p . 's1 = select i1 %isor, ' . $ty . $v . 'or, ' . $ty . $v . "and\n";
        $out .= '  %' . $p . 's2 = select i1 %isxor, ' . $ty . $v . 'xor, ' . $ty . $v . "s1\n";
        $out .= '  %' . $p . 'r = select i1 %isnot, ' . $ty . $v . 'not, ' . $ty . $v . "s2\n";
        return $out;
    }

    /**
     * Runtime: `&` `|` `^` `~` over CELLS (`%op` as {@see strBitop}; for `~` the
     * caller passes `%a` twice). Two string cells → the byte-wise string, boxed;
     * anything else → the integer op on the tagged-to-int values, boxed. Either
     * way a fresh +1 cell.
     */
    public function cellBitop(): string
    {
        $mask = (string)\Compile\MemoryAbi::CELL_PAYLOAD_MASK;
        $out  = "\ndefine i64 @__mir_cell_bitop(i64 %a, i64 %b, i64 %op) {\n";
        $out .= "entry:\n";
        $out .= "  %isnot = icmp eq i64 %op, 3\n";
        $out .= "  %isor = icmp eq i64 %op, 1\n";
        $out .= "  %isxor = icmp eq i64 %op, 2\n";
        $out .= "  %a0 = call i64 @__manticore_deref(i64 %a)\n";
        $out .= "  %b0 = call i64 @__manticore_deref(i64 %b)\n";
        $out .= "  %ta = call i64 @__manticore_tag(i64 %a0)\n";
        $out .= "  %tb = call i64 @__manticore_tag(i64 %b0)\n";
        // Tag 4 is the string cell ({@see __manticore_box_ptr}).
        $out .= "  %sa = icmp eq i64 %ta, 4\n";
        $out .= "  %sb = icmp eq i64 %tb, 4\n";
        $out .= "  %ss = and i1 %sa, %sb\n";
        $out .= "  br i1 %ss, label %str, label %int\n";
        $out .= "str:\n";
        $out .= "  %ma = and i64 %a0, " . $mask . "\n";
        $out .= "  %mb = and i64 %b0, " . $mask . "\n";
        $out .= "  %pa = inttoptr i64 %ma to ptr\n";
        $out .= "  %pb = inttoptr i64 %mb to ptr\n";
        $out .= "  %rs = call ptr @__mir_str_bitop(ptr %pa, ptr %pb, i64 %op)\n";
        $out .= "  %cs = call i64 @__manticore_box_ptr(ptr %rs)\n";
        $out .= "  ret i64 %cs\n";
        $out .= "int:\n";
        $out .= "  %ix = call i64 @__manticore_tagged_to_int(i64 %a0)\n";
        $out .= "  %iy = call i64 @__manticore_tagged_to_int(i64 %b0)\n";
        $out .= $this->bitopSelect('i', 'i64');
        $out .= "  %ci = call i64 @__manticore_box_int(i64 %ir)\n";
        $out .= "  ret i64 %ci\n";
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
        $out .= "@.jkw.dz = private unnamed_addr constant [3 x i8] c\".0\\00\", align 1\n";
        $out .= "@.jkw.repl = private unnamed_addr constant [4 x i8] c\"\\EF\\BF\\BD\\00\", align 1\n";
        // The walk's options, as state rather than threaded arguments: every
        // appender reads the flags, and widening their signatures would touch
        // every call. MUTABLE runtime state, so `linkonce_odr` — an `internal`
        // global would give each object file its own copy while the coalesced
        // functions read just one of them. {@see __mir_json_encf} saves and
        // restores all of it, because a jsonSerialize() may encode re-entrantly.
        // `stk` holds the containers on the current path (recursion detection).
        $out .= "@__mir_je_flags = linkonce_odr global i64 0\n";
        $out .= "@__mir_je_max = linkonce_odr global i64 512\n";
        $out .= "@__mir_je_lvl = linkonce_odr global i64 0\n";
        $out .= "@__mir_je_stk = linkonce_odr global [512 x i64] zeroinitializer\n";
        // Immortal object keys already found escape-free (by address).
        $out .= "@__mir_je_kc = linkonce_odr global [64 x i64] zeroinitializer\n";

        // reserve room for %extra more content bytes (+1 NUL); grow if needed.
        // The RUNNING LENGTH lives in the caller's cursor slot %lp, NOT the
        // string header — every appender used to load/store len@-16 plus a NUL
        // per call; now the header length + NUL are committed exactly once, in
        // __mir_json_encf's epilogue.
        // The capacity check is inlined into every appender (it runs once per
        // value); only the regrow is a call.
        $out .= "\ndefine ptr @__mir_json_reserve(ptr %slotp, ptr %lp, i64 %extra) alwaysinline {\n";
        $out .= "entry:\n";
        $out .= "  %buf = load ptr, ptr %slotp\n";
        $out .= "  %len = load i64, ptr %lp\n";
        $out .= "  %capp = getelementptr inbounds i8, ptr %buf, i64 -24\n";
        $out .= "  %cap = load i64, ptr %capp\n";
        $out .= "  %need = add i64 %len, %extra\n";
        $out .= "  %need1 = add i64 %need, 1\n";
        $out .= "  %fits = icmp ule i64 %need1, %cap\n";
        $out .= "  br i1 %fits, label %ok, label %grow\n";
        $out .= "ok:\n  ret ptr %buf\n";
        $out .= "grow:\n";
        $out .= "  %nb = call ptr @__mir_json_grow(ptr %slotp, ptr %buf, i64 %len, i64 %need1)\n";
        $out .= "  ret ptr %nb\n}\n";
        $out .= "\ndefine ptr @__mir_json_grow(ptr %slotp, ptr %buf, i64 %len, i64 %need1) noinline {\n";
        $out .= "entry:\n";
        $out .= "  %nc = shl i64 %need1, 1\n";
        $out .= "  %nb = call ptr @__mir_str_alloc(i64 %nc)\n";
        $out .= "  call ptr @memcpy(ptr %nb, ptr %buf, i64 %len)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %buf)\n";
        $out .= "  store ptr %nb, ptr %slotp\n";
        $out .= "  ret ptr %nb\n}\n";
        // A short copy (a key, a float's digits, one UTF-8 sequence) as a
        // byte loop: libc's memcpy through the PLT stub cost more than the
        // copy itself.
        $out .= '
define void @__mir_json_cpy(ptr %d, ptr %s, i64 %n) alwaysinline {
entry:
  %small = icmp ule i64 %n, 16
  br i1 %small, label %lp, label %big
lp:
  %i = phi i64 [ 0, %entry ], [ %i1, %body ]
  %done = icmp uge i64 %i, %n
  br i1 %done, label %fin, label %body
body:
  %sp = getelementptr inbounds i8, ptr %s, i64 %i
  %b = load i8, ptr %sp
  %dp = getelementptr inbounds i8, ptr %d, i64 %i
  store i8 %b, ptr %dp
  %i1 = add i64 %i, 1
  br label %lp
big:
  call ptr @memcpy(ptr %d, ptr %s, i64 %n)
  br label %fin
fin:
  ret void
}
';

        // append %n bytes from %src.
        $out .= "\ndefine void @__mir_json_ncat(ptr %slotp, ptr %lp, ptr %src, i64 %n) {\n";
        $out .= "entry:\n";
        $out .= "  %buf = call ptr @__mir_json_reserve(ptr %slotp, ptr %lp, i64 %n)\n";
        $out .= "  %len = load i64, ptr %lp\n";
        $out .= "  %dst = getelementptr inbounds i8, ptr %buf, i64 %len\n";
        $out .= "  call ptr @memcpy(ptr %dst, ptr %src, i64 %n)\n";
        $out .= "  %nl = add i64 %len, %n\n";
        $out .= "  store i64 %nl, ptr %lp\n";
        $out .= "  ret void\n}\n";

        // append one byte %c.
        $out .= "\ndefine void @__mir_json_putc(ptr %slotp, ptr %lp, i64 %c) {\n";
        $out .= "entry:\n";
        $out .= "  %buf = call ptr @__mir_json_reserve(ptr %slotp, ptr %lp, i64 1)\n";
        $out .= "  %len = load i64, ptr %lp\n";
        $out .= "  %dst = getelementptr inbounds i8, ptr %buf, i64 %len\n";
        $out .= "  %cb = trunc i64 %c to i8\n";
        $out .= "  store i8 %cb, ptr %dst\n";
        $out .= "  %nl = add i64 %len, 1\n";
        $out .= "  store i64 %nl, ptr %lp\n";
        $out .= "  ret void\n}\n";

        // append decimal of %v straight into the buffer (no temp string).
        $out .= "\ndefine void @__mir_json_int(ptr %slotp, ptr %lp, i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %n = call i64 @__mir_int_len(i64 %v)\n";
        $out .= "  %buf = call ptr @__mir_json_reserve(ptr %slotp, ptr %lp, i64 %n)\n";
        $out .= "  %len = load i64, ptr %lp\n";
        $out .= "  call void @__mir_int_fmt(ptr %buf, i64 %len, i64 %v)\n";
        $out .= "  %nl = add i64 %len, %n\n";
        $out .= "  store i64 %nl, ptr %lp\n";
        $out .= "  ret void\n}\n";

        // Write `\uXXXX` for %cp at %buf+%j, return the new j. Caller has
        // already reserved the room. `u4` is php's general lowercase form;
        // `u4u` the UPPERCASE one php writes for the JSON_HEX_* escapes
        // (`<`, verified against the oracle).
        foreach (['__mir_json_u4' => 87, '__mir_json_u4u' => 55] as $u4fn => $alpha) {
            $out .= "\ndefine i64 @$u4fn(ptr %buf, i64 %j, i64 %cp) {\n";
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
                $out .= "  %b$k = add i64 %m$k, $alpha\n";
                $out .= "  %h$k = select i1 %lt$k, i64 %a$k, i64 %b$k\n";
                $out .= "  %t$k = trunc i64 %h$k to i8\n";
                $out .= "  %jx$k = add i64 %j, " . ($j + $k) . "\n";
                $out .= "  %dx$k = getelementptr inbounds i8, ptr %buf, i64 %jx$k\n";
                $out .= "  store i8 %t$k, ptr %dx$k\n";
            }
            $out .= "  %jr = add i64 %j, 6\n";
            $out .= "  ret i64 %jr\n}\n";
        }

        // `"\n" . str_repeat("    ", lvl)` — a JSON_PRETTY_PRINT line break.
        $out .= '

define void @__mir_json_nl(ptr %slotp, ptr %lp, i64 %lvl) {
entry:
  %n = shl i64 %lvl, 2
  %n1 = add i64 %n, 1
  %buf = call ptr @__mir_json_reserve(ptr %slotp, ptr %lp, i64 %n1)
  %len = load i64, ptr %lp
  %d = getelementptr inbounds i8, ptr %buf, i64 %len
  store i8 10, ptr %d
  br label %lp0
lp0:
  %k = phi i64 [1, %entry], [%k1, %body]
  %fin = icmp ugt i64 %k, %n
  br i1 %fin, label %done, label %body
body:
  %dk = getelementptr inbounds i8, ptr %d, i64 %k
  store i8 32, ptr %dk
  %k1 = add i64 %k, 1
  br label %lp0
done:
  %nl = add i64 %len, %n1
  store i64 %nl, ptr %lp
  ret void
}

';

        // Append `"<escaped s>"` under the walk's flags. The hot inline loop
        // handles the ASCII escapes in ONE pass (SWAR prefilter, 8 clean bytes
        // per step), writing each byte or its two-char `\x` form; `/` escapes
        // unless JSON_UNESCAPED_SLASHES. The first byte that needs UTF-8
        // decoding (>=0x80) or a control with no short form switches to the
        // native slow loop (commit the inline bytes, reserve worst-case 6 B per
        // remaining byte, continue in place), and any JSON_HEX_* flag starts
        // there. The slow loop is php's `php_json_escape_string`: strict UTF-8
        // (`__mir_json_u8`, php_next_utf8_char's rules), JSON_UNESCAPED_UNICODE
        // with U+2028/2029 still escaped unless JSON_UNESCAPED_LINE_TERMINATORS,
        // and an invalid sequence dropped (INVALID_UTF8_IGNORE), replaced by
        // U+FFFD (INVALID_UTF8_SUBSTITUTE), or failing the string — the buffer
        // rewinds to before the opening quote, JSON_ERROR_UTF8 is raised and,
        // under JSON_PARTIAL_OUTPUT_ON_ERROR, `null` stands in for it.
        $out .= '
define void @__mir_json_estr(ptr %slotp, ptr %lp, ptr %s) {
entry:
  %fl = load i64, ptr @__mir_je_flags
  %slen = call i64 @__mir_strlen(ptr %s)
  %two = shl i64 %slen, 1
  %rsv = add i64 %two, 2
  %buf = call ptr @__mir_json_reserve(ptr %slotp, ptr %lp, i64 %rsv)
  %len0 = load i64, ptr %lp
  %q0 = getelementptr inbounds i8, ptr %buf, i64 %len0
  store i8 34, ptr %q0
  %j0 = add i64 %len0, 1
  %rawslf = and i64 %fl, 64
  %rawsl = icmp ne i64 %rawslf, 0
  %nrawsl = xor i1 %rawsl, true
  %hexf = and i64 %fl, 15
  %hexany = icmp ne i64 %hexf, 0
  br i1 %hexany, label %phppath, label %swar
swar:
  %wi = phi i64 [0, %entry], [%wi8, %swcopy], [%i2, %cont]
  %wj = phi i64 [%j0, %entry], [%wj8, %swcopy], [%j2, %cont]
  %wrem = sub i64 %slen, %wi
  %can8 = icmp sge i64 %wrem, 8
  br i1 %can8, label %swtest, label %loop
swtest:
  %wsp = getelementptr inbounds i8, ptr %s, i64 %wi
  %x = load i64, ptr %wsp, align 1
  %notx = xor i64 %x, -1
  %wge80 = and i64 %x, -9187201950435737472
  %wlt1 = sub i64 %x, 2314885530818453536
  %wlt2 = and i64 %wlt1, %notx
  %wlt32 = and i64 %wlt2, -9187201950435737472
  %zq = xor i64 %x, 2459565876494606882
  %zq1 = sub i64 %zq, 72340172838076673
  %zq2 = xor i64 %zq, -1
  %zq3 = and i64 %zq1, %zq2
  %eq34 = and i64 %zq3, -9187201950435737472
  %zb = xor i64 %x, 6655295901103053916
  %zb1 = sub i64 %zb, 72340172838076673
  %zb2 = xor i64 %zb, -1
  %zb3 = and i64 %zb1, %zb2
  %eq92 = and i64 %zb3, -9187201950435737472
  %zs = xor i64 %x, 3399988123389603631
  %zs1 = sub i64 %zs, 72340172838076673
  %zs2 = xor i64 %zs, -1
  %zs3 = and i64 %zs1, %zs2
  %eq47 = and i64 %zs3, -9187201950435737472
  %m1 = or i64 %wge80, %wlt32
  %m2 = or i64 %m1, %eq34
  %m3 = or i64 %m2, %eq92
  %dirty = or i64 %m3, %eq47
  %clean = icmp eq i64 %dirty, 0
  br i1 %clean, label %swcopy, label %loop
swcopy:
  %wdp = getelementptr inbounds i8, ptr %buf, i64 %wj
  store i64 %x, ptr %wdp, align 1
  %wi8 = add i64 %wi, 8
  %wj8 = add i64 %wj, 8
  br label %swar
loop:
  %i = phi i64 [%wi, %swar], [%wi, %swtest]
  %j = phi i64 [%wj, %swar], [%wj, %swtest]
  %done = icmp sge i64 %i, %slen
  br i1 %done, label %fin, label %body
body:
  %sp = getelementptr inbounds i8, ptr %s, i64 %i
  %c = load i8, ptr %sp
  %cz = zext i8 %c to i64
  %is34 = icmp eq i8 %c, 34
  %is92 = icmp eq i8 %c, 92
  %is47 = icmp eq i8 %c, 47
  %is10 = icmp eq i8 %c, 10
  %is9  = icmp eq i8 %c, 9
  %is13 = icmp eq i8 %c, 13
  %is8  = icmp eq i8 %c, 8
  %is12 = icmp eq i8 %c, 12
  %ge80 = icmp uge i64 %cz, 128
  %lt32 = icmp ult i64 %cz, 32
  %sh1 = or i1 %is10, %is9
  %sh2 = or i1 %sh1, %is13
  %sh3 = or i1 %sh2, %is8
  %short = or i1 %sh3, %is12
  %nshort = xor i1 %short, true
  %rare = and i1 %lt32, %nshort
  %bail = or i1 %ge80, %rare
  br i1 %bail, label %phppath, label %ascii
ascii:
  %e1 = select i1 %is10, i8 110, i8 %c
  %e2 = select i1 %is9,  i8 116, i8 %e1
  %e3 = select i1 %is13, i8 114, i8 %e2
  %e4 = select i1 %is8,  i8 98,  i8 %e3
  %e5 = select i1 %is12, i8 102, i8 %e4
  %esc47 = and i1 %is47, %nrawsl
  %o1 = or i1 %is34, %is92
  %o2 = or i1 %o1, %esc47
  %o3 = or i1 %o2, %short
  br i1 %o3, label %esc, label %plain
esc:
  %dp = getelementptr inbounds i8, ptr %buf, i64 %j
  store i8 92, ptr %dp
  %j1 = add i64 %j, 1
  %dp2 = getelementptr inbounds i8, ptr %buf, i64 %j1
  store i8 %e5, ptr %dp2
  %je = add i64 %j1, 1
  br label %cont
plain:
  %dp3 = getelementptr inbounds i8, ptr %buf, i64 %j
  store i8 %c, ptr %dp3
  %jp = add i64 %j, 1
  br label %cont
cont:
  %j2 = phi i64 [%je, %esc], [%jp, %plain]
  %i2 = add i64 %i, 1
  br label %swar
fin:
  %qc = getelementptr inbounds i8, ptr %buf, i64 %j
  store i8 34, ptr %qc
  %jend = add i64 %j, 1
  store i64 %jend, ptr %lp
  ret void
phppath:
  %pi = phi i64 [0, %entry], [%i, %body]
  %pj = phi i64 [%j0, %entry], [%j, %body]
  store i64 %pj, ptr %lp
  %srem = sub i64 %slen, %pi
  %srem6 = mul i64 %srem, 6
  %srsv = add i64 %srem6, 2
  %buf2 = call ptr @__mir_json_reserve(ptr %slotp, ptr %lp, i64 %srsv)
  %rawuf = and i64 %fl, 256
  %rawu = icmp ne i64 %rawuf, 0
  %ltf = and i64 %fl, 2048
  %ltraw = icmp ne i64 %ltf, 0
  %fq = and i64 %fl, 8
  %hq = icmp ne i64 %fq, 0
  %ft = and i64 %fl, 1
  %ht = icmp ne i64 %ft, 0
  %fa = and i64 %fl, 2
  %ha = icmp ne i64 %fa, 0
  %fp = and i64 %fl, 4
  %hp = icmp ne i64 %fp, 0
  br label %sloop
sloop:
  %si = phi i64 [%pi, %phppath], [%si2, %scont]
  %sj = phi i64 [%pj, %phppath], [%sj2, %scont]
  %sdone = icmp sge i64 %si, %slen
  br i1 %sdone, label %sfin, label %sbody
sbody:
  %ssp = getelementptr inbounds i8, ptr %s, i64 %si
  %sc = load i8, ptr %ssp
  %scz = zext i8 %sc to i64
  %sge80 = icmp uge i64 %scz, 128
  br i1 %sge80, label %sutf, label %sascii
sascii:
  %ss34 = icmp eq i64 %scz, 34
  %ss92 = icmp eq i64 %scz, 92
  %ss47 = icmp eq i64 %scz, 47
  %ss10 = icmp eq i64 %scz, 10
  %ss9  = icmp eq i64 %scz, 9
  %ss13 = icmp eq i64 %scz, 13
  %ss8  = icmp eq i64 %scz, 8
  %ss12 = icmp eq i64 %scz, 12
  %ss60 = icmp eq i64 %scz, 60
  %ss62 = icmp eq i64 %scz, 62
  %ss38 = icmp eq i64 %scz, 38
  %ss39 = icmp eq i64 %scz, 39
  %xq = and i1 %ss34, %hq
  %tg = or i1 %ss60, %ss62
  %xt = and i1 %tg, %ht
  %xa = and i1 %ss38, %ha
  %xp = and i1 %ss39, %hp
  %x1 = or i1 %xq, %xt
  %x2 = or i1 %xa, %xp
  %xhex = or i1 %x1, %x2
  br i1 %xhex, label %shex, label %sasc2
sasc2:
  %sm1 = select i1 %ss10, i64 110, i64 %scz
  %sm2 = select i1 %ss9,  i64 116, i64 %sm1
  %sm3 = select i1 %ss13, i64 114, i64 %sm2
  %sm4 = select i1 %ss8,  i64 98,  i64 %sm3
  %sm5 = select i1 %ss12, i64 102, i64 %sm4
  %sesc47 = and i1 %ss47, %nrawsl
  %sp1 = or i1 %ss34, %ss92
  %sp2 = or i1 %sp1, %sesc47
  %sp3 = or i1 %sp2, %ss10
  %sp4 = or i1 %sp3, %ss9
  %sp5 = or i1 %sp4, %ss13
  %sp6 = or i1 %sp5, %ss8
  %sspec = or i1 %sp6, %ss12
  br i1 %sspec, label %sesc, label %sq
sq:
  %slt32 = icmp ult i64 %scz, 32
  br i1 %slt32, label %sctl, label %splain
shex:
  %sjx = call i64 @__mir_json_u4u(ptr %buf2, i64 %sj, i64 %scz)
  br label %scont
sesc:
  %sd1 = getelementptr inbounds i8, ptr %buf2, i64 %sj
  store i8 92, ptr %sd1
  %sjb = add i64 %sj, 1
  %sd2 = getelementptr inbounds i8, ptr %buf2, i64 %sjb
  %smb = trunc i64 %sm5 to i8
  store i8 %smb, ptr %sd2
  %sje = add i64 %sj, 2
  br label %scont
sctl:
  %sjc = call i64 @__mir_json_u4(ptr %buf2, i64 %sj, i64 %scz)
  br label %scont
splain:
  %sd3 = getelementptr inbounds i8, ptr %buf2, i64 %sj
  store i8 %sc, ptr %sd3
  %sjp = add i64 %sj, 1
  br label %scont
sutf:
  %r1 = add i64 %si, 1
  %h2 = icmp slt i64 %r1, %slen
  br i1 %h2, label %f2chk, label %sslow
f2chk:
  %f1p = getelementptr inbounds i8, ptr %s, i64 %r1
  %f1b = load i8, ptr %f1p
  %f1 = zext i8 %f1b to i64
  %f1m = and i64 %f1, 192
  %ft1 = icmp eq i64 %f1m, 128
  %f1v = and i64 %f1, 63
  %c2a = icmp uge i64 %scz, 194
  %c2b = icmp ult i64 %scz, 224
  %c2 = and i1 %c2a, %c2b
  %ok2 = and i1 %c2, %ft1
  br i1 %ok2, label %f2, label %f3chk
f2:
  %f2h0 = and i64 %scz, 31
  %f2h = shl i64 %f2h0, 6
  %f2cp = or i64 %f2h, %f1v
  br label %sgood
f3chk:
  %c3a = icmp uge i64 %scz, 224
  %c3b = icmp ult i64 %scz, 240
  %c3 = and i1 %c3a, %c3b
  %r2 = add i64 %si, 2
  %h3 = icmp slt i64 %r2, %slen
  %c3h = and i1 %c3, %h3
  %c3t = and i1 %c3h, %ft1
  br i1 %c3t, label %f3ld, label %sslow
f3ld:
  %f2p = getelementptr inbounds i8, ptr %s, i64 %r2
  %f2b = load i8, ptr %f2p
  %f2z = zext i8 %f2b to i64
  %f2m = and i64 %f2z, 192
  %ft2 = icmp eq i64 %f2m, 128
  %f2v = and i64 %f2z, 63
  %f3h0 = and i64 %scz, 15
  %f3h = shl i64 %f3h0, 12
  %f3m = shl i64 %f1v, 6
  %f3a = or i64 %f3h, %f3m
  %f3cp = or i64 %f3a, %f2v
  %f3lo = icmp uge i64 %f3cp, 2048
  %f3s0 = icmp ult i64 %f3cp, 55296
  %f3s1 = icmp ugt i64 %f3cp, 57343
  %f3ns = or i1 %f3s0, %f3s1
  %f3o1 = and i1 %ft2, %f3lo
  %ok3 = and i1 %f3o1, %f3ns
  br i1 %ok3, label %f3, label %sslow
f3:
  br label %sgood
sslow:
  %u = call i64 @__mir_json_u8(ptr %s, i64 %si, i64 %slen)
  %ubad = icmp slt i64 %u, 0
  br i1 %ubad, label %sbad, label %sgoodu
sgoodu:
  %cpu = lshr i64 %u, 3
  %sdiu = and i64 %u, 7
  br label %sgood
sgood:
  %cp = phi i64 [ %f2cp, %f2 ], [ %f3cp, %f3 ], [ %cpu, %sgoodu ]
  %sdi = phi i64 [ 2, %f2 ], [ 3, %f3 ], [ %sdiu, %sgoodu ]
  %is2028 = icmp eq i64 %cp, 8232
  %is2029 = icmp eq i64 %cp, 8233
  %islt = or i1 %is2028, %is2029
  %nltraw = xor i1 %ltraw, true
  %mustesc = and i1 %islt, %nltraw
  %nmust = xor i1 %mustesc, true
  %keepraw = and i1 %rawu, %nmust
  br i1 %keepraw, label %sraw, label %suesc
sraw:
  %srd = getelementptr inbounds i8, ptr %buf2, i64 %sj
  call void @__mir_json_cpy(ptr %srd, ptr %ssp, i64 %sdi)
  %sjr = add i64 %sj, %sdi
  br label %scont
suesc:
  %sbig = icmp ugt i64 %cp, 65535
  br i1 %sbig, label %spair, label %sone
sone:
  %sj1x = call i64 @__mir_json_u4(ptr %buf2, i64 %sj, i64 %cp)
  br label %scont
spair:
  %cpm = sub i64 %cp, 65536
  %hi10 = lshr i64 %cpm, 10
  %hicp = add i64 %hi10, 55296
  %lo10 = and i64 %cpm, 1023
  %locp = add i64 %lo10, 56320
  %sjh = call i64 @__mir_json_u4(ptr %buf2, i64 %sj, i64 %hicp)
  %sjl = call i64 @__mir_json_u4(ptr %buf2, i64 %sjh, i64 %locp)
  br label %scont
sbad:
  %badv = sub i64 0, %u
  %igf = and i64 %fl, 1048576
  %ign = icmp ne i64 %igf, 0
  br i1 %ign, label %scont, label %sbad2
sbad2:
  %subf = and i64 %fl, 2097152
  %sub = icmp ne i64 %subf, 0
  br i1 %sub, label %ssub, label %serr
ssub:
  br i1 %rawu, label %ssubr, label %ssubu
ssubr:
  %ssd = getelementptr inbounds i8, ptr %buf2, i64 %sj
  call ptr @memcpy(ptr %ssd, ptr @.jkw.repl, i64 3)
  %sjsr = add i64 %sj, 3
  br label %scont
ssubu:
  %sjsu = call i64 @__mir_json_u4(ptr %buf2, i64 %sj, i64 65533)
  br label %scont
serr:
  store i64 %len0, ptr %lp
  %ue = call i64 @manticore___mc_json_err(i64 5)
  %pof = and i64 %fl, 512
  %po = icmp ne i64 %pof, 0
  br i1 %po, label %spart, label %sret
spart:
  call void @__mir_json_ncat(ptr %slotp, ptr %lp, ptr @.jkw.null, i64 4)
  br label %sret
sret:
  ret void
scont:
  %sj2 = phi i64 [%sjx, %shex], [%sje, %sesc], [%sjc, %sctl], [%sjp, %splain], [%sjr, %sraw], [%sj1x, %sone], [%sjl, %spair], [%sj, %sbad], [%sjsr, %ssubr], [%sjsu, %ssubu]
  %sadv = phi i64 [1, %shex], [1, %sesc], [1, %sctl], [1, %splain], [%sdi, %sraw], [%sdi, %sone], [%sdi, %spair], [%badv, %sbad], [%badv, %ssubr], [%badv, %ssubu]
  %si2 = add i64 %si, %sadv
  br label %sloop
sfin:
  %sqc = getelementptr inbounds i8, ptr %buf2, i64 %sj
  store i8 34, ptr %sqc
  %sjend = add i64 %sj, 1
  store i64 %sjend, ptr %lp
  ret void
}
';

        // Float emitter: Ryu digits via the scalar core (__mc_dtoa_scal, two
        // calls — digits and (exp<<1)|sign), formatted straight into the json
        // buffer with the exact __mc_dtoa_core tail semantics (json flavor:
        // lowercase e, no forced .0). Zero heap traffic per float — the old
        // per-float PHP string tail (substr/concat/str_repeat temps + result
        // alloc/copy/free) was ~47% of json_records wall and ALL of its 242 MB
        // RSS churn. Non-finite / ±0 return -1 from the scalar core → the old
        // string path (rare).
        // `@__mir_json_dfast(bits)`: the shortest decimal of a double that HAS
        // one of <= 15 significant digits, without Ryu. DBL_DIG is 15, so two
        // distinct decimals of <= 15 digits never round to the same double: the
        // first `m / 10^k` (k = 0, 1, …) that reads back as `d` — `m` and `10^k`
        // exact, the IEEE division correctly rounded, which IS strtod's answer —
        // is the unique such decimal, hence the shortest, hence Ryu's. Answers
        // the packed form of `__mc_dtoa_scal(bits, 2)`, or 1 to defer to Ryu (a
        // 16–17-digit value, or one outside [1e-5, 1e15)). Prices, ratios and
        // measurements take this path; Ryu in compiled PHP was ~30% of an
        // API-record encode.
        $out .= '
define i64 @__mir_json_dfast(i64 %bits) {
entry:
  %sign = lshr i64 %bits, 63
  %ab = and i64 %bits, 9223372036854775807
  %d = bitcast i64 %ab to double
  %lo = fcmp oge double %d, 1.0e-5
  %hi = fcmp olt double %d, 1.0e15
  %in = and i1 %lo, %hi
  br i1 %in, label %lp, label %no
lp:
  %k = phi i64 [ 0, %entry ], [ %k1, %next ]
  %p = phi double [ 1.0, %entry ], [ %p1, %next ]
  %t = fmul double %d, %p
  %big = fcmp oge double %t, 1.0e15
  br i1 %big, label %no, label %try
try:
  %th = fadd double %t, 0.5
  %m = fptosi double %th to i64
  %mf = sitofp i64 %m to double
  %q = fdiv double %mf, %p
  %hit = fcmp oeq double %q, %d
  br i1 %hit, label %strip, label %next
next:
  %k1 = add i64 %k, 1
  %p1 = fmul double %p, 10.0
  %more = icmp ult i64 %k1, 16
  br i1 %more, label %lp, label %no
strip:
  %e0 = sub i64 0, %k
  br label %sl
sl:
  %sm = phi i64 [ %m, %strip ], [ %sm1, %sd ]
  %se = phi i64 [ %e0, %strip ], [ %se1, %sd ]
  %r = urem i64 %sm, 10
  %z = icmp eq i64 %r, 0
  br i1 %z, label %sd, label %pack
sd:
  %sm1 = udiv i64 %sm, 10
  %se1 = add i64 %se, 1
  br label %sl
pack:
  %ps = shl i64 %sm, 13
  %pe0 = add i64 %se, 1024
  %pe = shl i64 %pe0, 2
  %pg = shl i64 %sign, 1
  %pk0 = or i64 %ps, %pe
  %pk = or i64 %pk0, %pg
  ret i64 %pk
no:
  ret i64 1
}
';
        $out .= "\ndefine void @__mir_json_double(ptr %slotp, ptr %lp, i64 %cell) {\n";
        $out .= "entry:\n";
        // The exact short-decimal path first; then Ryu's one-call packed form
        // (digits <= 51 bits); flag bit set → re-run as two scalar calls;
        // scal(0) < 0 → string path.
        $out .= "  %pq = call i64 @__mir_json_dfast(i64 %cell)\n";
        $out .= "  %pqf = and i64 %pq, 1\n";
        $out .= "  %pqok = icmp eq i64 %pqf, 0\n";
        $out .= "  br i1 %pqok, label %fastq, label %ryu\n";
        $out .= "ryu:\n";
        $out .= "  %pr = call i64 @manticore___mc_dtoa_scal(i64 %cell, i64 2)\n";
        $out .= "  br label %fastq\n";
        $out .= "fastq:\n";
        $out .= "  %pk = phi i64 [ %pq, %entry ], [ %pr, %ryu ]\n";
        $out .= "  %pfl = and i64 %pk, 1\n";
        $out .= "  %pbad = icmp ne i64 %pfl, 0\n";
        $out .= "  br i1 %pbad, label %twoc, label %fastu\n";
        $out .= "fastu:\n";
        $out .= "  %psig = lshr i64 %pk, 13\n";
        $out .= "  %peb0 = lshr i64 %pk, 2\n";
        $out .= "  %peb = and i64 %peb0, 2047\n";
        $out .= "  %pexp = sub i64 %peb, 1024\n";
        $out .= "  %psg0 = lshr i64 %pk, 1\n";
        $out .= "  %psgn = and i64 %psg0, 1\n";
        $out .= "  br label %go\n";
        $out .= "twoc:\n";
        $out .= "  %sig2 = call i64 @manticore___mc_dtoa_scal(i64 %cell, i64 0)\n";
        $out .= "  %spec = icmp slt i64 %sig2, 0\n";
        $out .= "  br i1 %spec, label %fb, label %twoc2\n";
        $out .= "twoc2:\n";
        $out .= "  %meta = call i64 @manticore___mc_dtoa_scal(i64 %cell, i64 1)\n";
        $out .= "  %msgn = and i64 %meta, 1\n";
        $out .= "  %meb = lshr i64 %meta, 1\n";
        $out .= "  %mexp = sub i64 %meb, 1024\n";
        $out .= "  br label %go\n";
        $out .= "fb:\n";
        $out .= "  %fsi = call i64 @manticore___mc_dtoa_bits(i64 %cell)\n";
        $out .= "  %fs = inttoptr i64 %fsi to ptr\n";
        $out .= "  %fn = call i64 @__mir_strlen(ptr %fs)\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr %lp, ptr %fs, i64 %fn)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %fs)\n";
        // JSON_PRESERVE_ZERO_FRACTION: ±0 is `0.0` / `-0.0`. INF and NAN (the
        // other fallback inputs) are `0` either way — php writes the zero bare.
        $out .= "  %fbz0 = and i64 %cell, 9223372036854775807\n";
        $out .= "  %fbz = icmp eq i64 %fbz0, 0\n";
        $out .= "  %fbfl = load i64, ptr @__mir_je_flags\n";
        $out .= "  %fbpz0 = and i64 %fbfl, 1024\n";
        $out .= "  %fbpz = icmp ne i64 %fbpz0, 0\n";
        $out .= "  %fbdz = and i1 %fbz, %fbpz\n";
        $out .= "  br i1 %fbdz, label %fbdot, label %fbret\n";
        $out .= "fbdot:\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr %lp, ptr @.jkw.dz, i64 2)\n";
        $out .= "  br label %fbret\n";
        $out .= "fbret:\n";
        $out .= "  ret void\n";
        $out .= "go:\n";
        $out .= "  %sig = phi i64 [%psig, %fastu], [%sig2, %twoc2]\n";
        $out .= "  %fexp = phi i64 [%pexp, %fastu], [%mexp, %twoc2]\n";
        $out .= "  %fsign = phi i64 [%psgn, %fastu], [%msgn, %twoc2]\n";
        $out .= "  %olen = call i64 @__mir_int_len(i64 %sig)\n";
        $out .= "  %dt = alloca [24 x i8]\n";
        $out .= "  call void @__mir_int_fmt(ptr %dt, i64 0, i64 %sig)\n";
        $out .= "  %eneg = icmp slt i64 %fexp, 0\n";
        $out .= "  %nexp = sub i64 0, %fexp\n";
        $out .= "  %aexp = select i1 %eneg, i64 %nexp, i64 %fexp\n";
        $out .= "  %rsv0 = add i64 %olen, %aexp\n";
        $out .= "  %rsv = add i64 %rsv0, 16\n";
        $out .= "  %buf = call ptr @__mir_json_reserve(ptr %slotp, ptr %lp, i64 %rsv)\n";
        $out .= "  %len0 = load i64, ptr %lp\n";
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
        $out .= "  call void @__mir_json_cpy(ptr %da, ptr %dt, i64 %olen)\n";
        $out .= "  %wb = add i64 %wa, %olen\n";
        $out .= "  store i64 %wb, ptr %wp\n";
        $out .= "  br label %zl\n";
        $out .= "zl:\n";
        $out .= "  %zi = phi i64 [0, %fint], [%zi2, %zb]\n";
        $out .= "  %zd = icmp sge i64 %zi, %fexp\n";
        $out .= "  br i1 %zd, label %fpz, label %zb\n";
        $out .= "fpz:\n";
        $out .= "  %pzfl = load i64, ptr @__mir_je_flags\n";
        $out .= "  %pzf = and i64 %pzfl, 1024\n";
        $out .= "  %pzon = icmp ne i64 %pzf, 0\n";
        $out .= "  br i1 %pzon, label %fpzw, label %fdone\n";
        $out .= "fpzw:\n";
        $out .= "  %wpz = load i64, ptr %wp\n";
        $out .= "  %pzp = getelementptr inbounds i8, ptr %buf, i64 %wpz\n";
        $out .= "  store i8 46, ptr %pzp\n";
        $out .= "  %wpz1 = add i64 %wpz, 1\n";
        $out .= "  %pzp1 = getelementptr inbounds i8, ptr %buf, i64 %wpz1\n";
        $out .= "  store i8 48, ptr %pzp1\n";
        $out .= "  %wpz2 = add i64 %wpz, 2\n";
        $out .= "  store i64 %wpz2, ptr %wp\n";
        $out .= "  br label %fdone\n";
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
        $out .= "  call void @__mir_json_cpy(ptr %dc, ptr %dt, i64 %dp)\n";
        $out .= "  %wd = add i64 %wc, %dp\n";
        $out .= "  %pp = getelementptr inbounds i8, ptr %buf, i64 %wd\n";
        $out .= "  store i8 46, ptr %pp\n";
        $out .= "  %we = add i64 %wd, 1\n";
        $out .= "  %sp2 = getelementptr inbounds i8, ptr %dt, i64 %dp\n";
        $out .= "  %rest = sub i64 %olen, %dp\n";
        $out .= "  %dpst = getelementptr inbounds i8, ptr %buf, i64 %we\n";
        $out .= "  call void @__mir_json_cpy(ptr %dpst, ptr %sp2, i64 %rest)\n";
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
        $out .= "  call void @__mir_json_cpy(ptr %dh, ptr %dt, i64 %olen)\n";
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
        $out .= "  call void @__mir_json_cpy(ptr %mdst, ptr %dt1, i64 %mrest)\n";
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
        $out .= "  store i64 %wfin, ptr %lp\n";
        $out .= "  ret void\n}\n";

        // INLINE one structural byte %ch — the fast path is a cursor bump with
        // ZERO calls (reserve is only reached on an actual grow), replacing the
        // `call __mir_json_putc` (which itself CALLED __mir_json_reserve — two
        // out-of-line calls per `{ } [ ] , : "`). Object encode was call-bound:
        // a 3-key row emits ~7 structural chars, so ~14 calls/row vanished. All
        // inside this ONE function ⇒ no per-call-site IR cost; clang -O2 folds
        // adjacent inlined reserves. Control lands in `jpN_ok` (a fresh block);
        // subsequent IR continues there. %len loaded pre-branch dominates the
        // merge (grow keeps the cursor); %b is RELOADED post-branch so it picks
        // up a relocated buffer on the grow path and the same buffer otherwise.
        $jp = 0;
        $inlinePutc = function (int $ch) use (&$jp): string {
            $u = $jp; $jp = $jp + 1;
            $o  = "  %jp{$u}buf = load ptr, ptr %slotp\n";
            $o .= "  %jp{$u}capp = getelementptr inbounds i8, ptr %jp{$u}buf, i64 -24\n";
            $o .= "  %jp{$u}cap = load i64, ptr %jp{$u}capp\n";
            $o .= "  %jp{$u}len = load i64, ptr %lp\n";
            $o .= "  %jp{$u}need = add i64 %jp{$u}len, 2\n";
            $o .= "  %jp{$u}fits = icmp ule i64 %jp{$u}need, %jp{$u}cap\n";
            $o .= "  br i1 %jp{$u}fits, label %jp{$u}ok, label %jp{$u}grow\n";
            $o .= "jp{$u}grow:\n";
            $o .= "  %jp{$u}g = call ptr @__mir_json_reserve(ptr %slotp, ptr %lp, i64 1)\n";
            $o .= "  br label %jp{$u}ok\n";
            $o .= "jp{$u}ok:\n";
            $o .= "  %jp{$u}b = load ptr, ptr %slotp\n";
            $o .= "  %jp{$u}dst = getelementptr inbounds i8, ptr %jp{$u}b, i64 %jp{$u}len\n";
            $o .= "  store i8 {$ch}, ptr %jp{$u}dst\n";
            $o .= "  %jp{$u}nl = add i64 %jp{$u}len, 1\n";
            $o .= "  store i64 %jp{$u}nl, ptr %lp\n";
            return $o;
        };

        // recursive walker.
        $out .= "\ndefine void @__mir_json_app(ptr %slotp, ptr %lp, i64 %cellin) {\n";
        $out .= "entry:\n";
        // A REF cell (nibble 9) has no arm below and never can have one: what
        // json encodes is what the reference REFERS TO. Without this it fell
        // through to the array/object arm, masked the payload to the box
        // pointer, loaded the boxed int inside and dereferenced THAT as an
        // object — EXC_BAD_ACCESS at 0xfff1000000000027, a tagged int used as
        // an address. Deref first and the whole dispatch below is unchanged.
        $out .= "  %cell = call i64 @__manticore_deref(i64 %cellin)\n";
        $out .= "  %tagged = icmp ugt i64 %cell, $T\n";
        $out .= "  br i1 %tagged, label %istag, label %isfloat\n";
        $out .= "isfloat:\n";
        $out .= "  call void @__mir_json_double(ptr %slotp, ptr %lp, i64 %cell)\n";
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
        $out .= "  call void @__mir_json_int(ptr %slotp, ptr %lp, i64 %iv)\n";
        $out .= "  ret void\n";
        $out .= "t2:\n";
        $out .= "  %isbool = icmp eq i64 %nib, 2\n";
        $out .= "  br i1 %isbool, label %tbool, label %t3\n";
        $out .= "tbool:\n";
        $out .= "  %b = and i64 %cell, 1\n";
        $out .= "  %istrue = icmp ne i64 %b, 0\n";
        $out .= "  br i1 %istrue, label %btrue, label %bfalse\n";
        $out .= "btrue:\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr %lp, ptr @.jkw.true, i64 4)\n";
        $out .= "  ret void\n";
        $out .= "bfalse:\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr %lp, ptr @.jkw.false, i64 5)\n";
        $out .= "  ret void\n";
        $out .= "t3:\n";
        $out .= "  %isnull = icmp eq i64 %nib, 3\n";
        $out .= "  br i1 %isnull, label %tnull, label %t4\n";
        $out .= "tnull:\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr %lp, ptr @.jkw.null, i64 4)\n";
        $out .= "  ret void\n";
        $out .= "t4:\n";
        $out .= "  %isstr = icmp eq i64 %nib, 4\n";
        $out .= "  br i1 %isstr, label %tstr, label %t7\n";
        $out .= "tstr:\n";
        // JSON_NUMERIC_CHECK: a numeric string encodes as the number it reads
        // as. php's is_numeric_string decides, so the compiled-PHP twin
        // answers — null for "not numeric" (or INF, which php keeps a string).
        $out .= "  %nfl = load i64, ptr @__mir_je_flags\n";
        $out .= "  %nck0 = and i64 %nfl, 32\n";
        $out .= "  %nck = icmp ne i64 %nck0, 0\n";
        $out .= "  br i1 %nck, label %tnum, label %tstr2\n";
        $out .= "tnum:\n";
        $out .= "  %nr = call i64 @manticore___mc_json_numcheck(i64 %cell)\n";
        $out .= "  %nrnul = icmp eq i64 %nr, " . (string)\Compile\MemoryAbi::CELL_NULL . "\n";
        $out .= "  br i1 %nrnul, label %tstr2, label %tnum2\n";
        $out .= "tnum2:\n";
        $out .= "  call void @__mir_json_app(ptr %slotp, ptr %lp, i64 %nr)\n";
        $out .= "  call void @__mir_cell_drop(i64 %nr)\n";
        $out .= "  ret void\n";
        $out .= "tstr2:\n";
        $out .= "  %spp = and i64 %cell, $M\n";
        $out .= "  %sptr = inttoptr i64 %spp to ptr\n";
        $out .= "  call void @__mir_json_estr(ptr %slotp, ptr %lp, ptr %sptr)\n";
        $out .= "  ret void\n";
        $out .= "t7:\n";
        $out .= "  %isarr = icmp eq i64 %nib, 7\n";
        $out .= "  br i1 %isarr, label %tarr, label %tobj\n";
        $out .= "tobj:\n";
        // A class that carries a props_fn on its DESCRIPTOR encodes from its
        // declared properties, right here in the generic helper — the class
        // table this would otherwise need does not exist in the stdlib, which
        // is why every object used to answer `{}`. The result is an
        // `assoc[string,cell]`, walked as a container named by the OBJECT.
        $out .= "  %opay = and i64 %cell, " . (string)\Compile\MemoryAbi::CELL_PAYLOAD_MASK . "\n";
        $out .= "  %optr = inttoptr i64 %opay to ptr\n";
        // JsonSerializable: the method's result is encoded in the object's
        // place. The same object back means the class has none.
        $out .= "  %ojs = call i64 @__mir_json_ser(i64 %cell)\n";
        $out .= "  %ojp = and i64 %ojs, " . (string)\Compile\MemoryAbi::CELL_PAYLOAD_MASK . "\n";
        $out .= "  %ojsame = icmp eq i64 %ojp, %opay\n";
        $out .= "  br i1 %ojsame, label %tobjself, label %tobjser\n";
        $out .= "tobjser:\n";
        // `Manticore\Ds\JsonNumber` (prelude/ds.php) is a number php's ints
        // cannot hold — a UInt64Array element past 2^63: its jsonSerialize()
        // string is written BARE. Recognised by its stable class id.
        $out .= "  %ocdp = load ptr, ptr %optr\n";
        $out .= "  %ocid = load i64, ptr %ocdp\n";
        $out .= "  %oraw = icmp eq i64 %ocid, " . (string)\Compile\Mir\ClassDef::stableId('Manticore\\Ds\\JsonNumber') . "\n";
        $out .= "  %ojtg = lshr i64 %ojs, 48\n";
        $out .= "  %ojnb = and i64 %ojtg, 15\n";
        $out .= "  %ojst = icmp eq i64 %ojnb, 4\n";
        $out .= "  %ojtd = icmp ugt i64 %ojs, $T\n";
        $out .= "  %ojss = and i1 %ojst, %ojtd\n";
        $out .= "  %orawok = and i1 %oraw, %ojss\n";
        $out .= "  br i1 %orawok, label %tobjraw, label %tobjser2\n";
        $out .= "tobjraw:\n";
        $out .= "  %orp = and i64 %ojs, $M\n";
        $out .= "  %orpp = inttoptr i64 %orp to ptr\n";
        $out .= "  %orl = call i64 @__mir_strlen(ptr %orpp)\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr %lp, ptr %orpp, i64 %orl)\n";
        $out .= "  call void @__mir_cell_drop(i64 %ojs)\n";
        $out .= "  ret void\n";
        $out .= "tobjser2:\n";
        $out .= "  call void @__mir_json_app(ptr %slotp, ptr %lp, i64 %ojs)\n";
        $out .= "  call void @__mir_cell_drop(i64 %ojs)\n";
        $out .= "  ret void\n";
        $out .= "tobjself:\n";
        $out .= "  call void @__mir_cell_drop(i64 %ojs)\n";
        // A backed case has a json_fn ({@see jsonSer}) and never gets here; a
        // case that does is a pure enum: JSON_ERROR_NON_BACKED_ENUM, and php
        // writes `0` in its place.
        $out .= "  %oetp = getelementptr inbounds i8, ptr %optr, i64 -8\n";
        $out .= "  %oet = load i64, ptr %oetp\n";
        $out .= "  %oen = icmp eq i64 %oet, " . (string)\Compile\MemoryAbi::ENUM_TAG_MAGIC . "\n";
        $out .= "  br i1 %oen, label %tenum, label %tobjd\n";
        $out .= "tenum:\n";
        $out .= "  %oene = call i64 @manticore___mc_json_err(i64 11)\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, ptr %lp, i64 48)\n";
        $out .= "  ret void\n";
        $out .= "tobjd:\n";
        $out .= "  %odesc = load ptr, ptr %optr\n";
        $out .= "  %odn = icmp eq ptr %odesc, null\n";
        $out .= "  br i1 %odn, label %tobjpunt, label %tobjpf\n";
        // visit_fn ({@see \Compile\MemoryAbi::DESCRIPTOR_VISIT_FN_OFFSET}):
        // the public properties walked straight into the buffer through
        // `@__mir_json_pcb` — no map per object. props_fn (a FRESH map) is the
        // fallback for a class whose descriptor has no walker.
        $out .= "tobjpf:\n";
        $out .= "  %ovfp = getelementptr inbounds i8, ptr %odesc, i64 " . (string)\Compile\MemoryAbi::DESCRIPTOR_VISIT_FN_OFFSET . "\n";
        $out .= "  %ovf = load ptr, ptr %ovfp\n";
        $out .= "  %ovh = icmp ne ptr %ovf, null\n";
        $out .= "  br i1 %ovh, label %tobjvis, label %tobjpf2\n";
        $out .= "tobjvis:\n";
        $out .= "  %vl = call i64 @__mir_json_enter(ptr %slotp, ptr %lp, i64 %opay)\n";
        $out .= "  %vok = icmp ne i64 %vl, 0\n";
        $out .= "  br i1 %vok, label %tobjvis2, label %tobjvret\n";
        $out .= "tobjvret:\n";
        $out .= "  ret void\n";
        $out .= "tobjvis2:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, ptr %lp, i64 123)\n";
        $out .= "  %vctx = alloca [4 x i64]\n";
        $out .= "  store ptr %slotp, ptr %vctx\n";
        $out .= "  %vc1 = getelementptr inbounds i64, ptr %vctx, i64 1\n";
        $out .= "  store ptr %lp, ptr %vc1\n";
        $out .= "  %vc2 = getelementptr inbounds i64, ptr %vctx, i64 2\n";
        $out .= "  store i64 0, ptr %vc2\n";
        $out .= "  %vc3 = getelementptr inbounds i64, ptr %vctx, i64 3\n";
        $out .= "  store i64 %vl, ptr %vc3\n";
        $out .= "  call void %ovf(ptr %optr, ptr %vctx, ptr @__mir_json_pcb)\n";
        $out .= "  %vcnt = load i64, ptr %vc2\n";
        $out .= "  %vfl = load i64, ptr @__mir_je_flags\n";
        $out .= "  %vpf = and i64 %vfl, 128\n";
        $out .= "  %vpr = icmp ne i64 %vpf, 0\n";
        $out .= "  %vne = icmp sgt i64 %vcnt, 0\n";
        $out .= "  %vpnl = and i1 %vpr, %vne\n";
        $out .= "  %vl0 = sub i64 %vl, 1\n";
        $out .= "  br i1 %vpnl, label %tobjvnl, label %tobjvcl\n";
        $out .= "tobjvnl:\n";
        $out .= "  call void @__mir_json_nl(ptr %slotp, ptr %lp, i64 %vl0)\n";
        $out .= "  br label %tobjvcl\n";
        $out .= "tobjvcl:\n";
        $out .= "  call void @__mir_json_putc(ptr %slotp, ptr %lp, i64 125)\n";
        $out .= "  store i64 %vl0, ptr @__mir_je_lvl\n";
        $out .= "  ret void\n";
        $out .= "tobjpf2:\n";
        $out .= "  %opfp = getelementptr inbounds i8, ptr %odesc, i64 "
              . (string)\Compile\MemoryAbi::DESCRIPTOR_PROPS_FN_OFFSET . "\n";
        $out .= "  %opf = load ptr, ptr %opfp\n";
        $out .= "  %ohas = icmp ne ptr %opf, null\n";
        $out .= "  br i1 %ohas, label %tobjprops, label %tobjpunt\n";
        $out .= "tobjprops:\n";
        $out .= "  %oarr = call i64 %opf(ptr %optr)\n";
        $out .= "  %oarrp = inttoptr i64 %oarr to ptr\n";
        $out .= "  call void @__mir_json_cont(ptr %slotp, ptr %lp, ptr %oarrp, i64 %opay, i64 1)\n";
        // The props array co-owns every value it boxed ({@see
        // EmitLlvmBuiltins::emitDeclaredPropsArray} mirror-retains a borrowed
        // one), so it is ours to give back.
        $out .= "  call void @__mir_array_release_cell(ptr %oarrp)\n";
        $out .= "  ret void\n";
        $out .= "tobjpunt:\n";
        // ★ ALL FOUR ARGUMENTS. `__mc_json_enc(mixed $v, int $flags = 0,
        // int $maxDepth = 512, int $depth = 0)` is a four-parameter PHP body —
        // a DEFAULT in php is not a default in the ABI, it is filled by the
        // CALLER. This punt passed the cell alone, so `$flags`, `$maxDepth` and
        // `$depth` were whatever the argument registers happened to hold; a
        // `$maxDepth` of 0 makes the walker's own `$depth >= $maxDepth` guard
        // true on entry, raise error 1, and `json_encode` turn that into
        // `false`. Every `json_encode($object)` returned false — the whole
        // `json_objects` bench row, and every API serializer.
        $out .= "  %pfl = load i64, ptr @__mir_je_flags\n";
        $out .= "  %pmx = load i64, ptr @__mir_je_max\n";
        $out .= "  %plv = load i64, ptr @__mir_je_lvl\n";
        $out .= "  %osi = call i64 @manticore___mc_json_enc(i64 %cell, i64 %pfl, i64 %pmx, i64 %plv)\n";
        $out .= "  %os = inttoptr i64 %osi to ptr\n";
        $out .= "  %on = call i64 @__mir_strlen(ptr %os)\n";
        $out .= "  call void @__mir_json_ncat(ptr %slotp, ptr %lp, ptr %os, i64 %on)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %os)\n";
        $out .= "  ret void\n";
        $H = (string) \Compile\MemoryAbi::ARRAY_HEADER_SIZE;
        $ES = (string) \Compile\MemoryAbi::ARRAY_ENTRY_SIZE;
        $KIND_INT = (string) \Compile\MemoryAbi::ARRAY_KIND_INT;
        $KIND_STR = (string) \Compile\MemoryAbi::ARRAY_KIND_STRING;
        $KEY_OFF = (string) \Compile\MemoryAbi::ARRAY_ENTRY_KEY_OFFSET;
        $VAL_OFF = (string) \Compile\MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET;
        $out .= "tarr:\n";
        $out .= "  %arr0 = and i64 %cell, $M\n";
        $out .= "  %arr = inttoptr i64 %arr0 to ptr\n";
        $out .= "  call void @__mir_json_cont(ptr %slotp, ptr %lp, ptr %arr, i64 %arr0, i64 0)\n";
        $out .= "  ret void\n}\n";

        // One container: an array (`%isobj` 0, a list unless JSON_FORCE_OBJECT
        // or its keys say otherwise) or an object's property array (`%isobj` 1,
        // always `{}`). `%ident` names it on the path — the array buffer, or the
        // OBJECT for a property array, which is minted fresh per visit. php's
        // php_json_encode_array: the depth counts every container, empty ones
        // included, and one deeper than `$depth` is JSON_ERROR_DEPTH; one
        // already on the path is JSON_ERROR_RECURSION and encodes as `null`.
        // Past the limit only JSON_PARTIAL_OUTPUT_ON_ERROR keeps descending
        // (its output survives); otherwise the result is discarded anyway.
        //
        // All element access is by direct entry loads (kind/key/value) — no
        // out-of-line value_at calls, no per-element key (re)boxing: keys read
        // RAW, which also keeps int keys > 2^47 exact. Upfront reserve of
        // ~8 B/element cuts buffer regrows on big documents.
        // Entering a container — an array, or an object walked through its
        // descriptor — is one rule: `@__mir_json_enter` raises the level,
        // applies the depth limit and the recursion check, and answers the new
        // level, or 0 when it wrote `null` instead and the caller must skip.
        $out .= '
define i64 @__mir_json_enter(ptr %slotp, ptr %lp, i64 %ident) {
entry:
  %fl = load i64, ptr @__mir_je_flags
  %lvl0 = load i64, ptr @__mir_je_lvl
  %lvl = add i64 %lvl0, 1
  store i64 %lvl, ptr @__mir_je_lvl
  %max = load i64, ptr @__mir_je_max
  %over = icmp sgt i64 %lvl, %max
  br i1 %over, label %deep, label %rchk
deep:
  %de = call i64 @manticore___mc_json_err(i64 1)
  %dpf = and i64 %fl, 512
  %dnp = icmp eq i64 %dpf, 0
  %dhard = icmp sgt i64 %lvl, 10000
  %dbail = or i1 %dnp, %dhard
  br i1 %dbail, label %bail, label %rchk
bail:
  call void @__mir_json_ncat(ptr %slotp, ptr %lp, ptr @.jkw.null, i64 4)
  store i64 %lvl0, ptr @__mir_je_lvl
  ret i64 0
rchk:
  %inst = icmp slt i64 %lvl0, 512
  %lim = select i1 %inst, i64 %lvl0, i64 512
  br label %rl
rl:
  %rk = phi i64 [0, %rchk], [%rk2, %rn]
  %rdone = icmp sge i64 %rk, %lim
  br i1 %rdone, label %rpush, label %rb
rb:
  %rp = getelementptr [512 x i64], ptr @__mir_je_stk, i64 0, i64 %rk
  %rv = load i64, ptr %rp
  %rhit = icmp eq i64 %rv, %ident
  br i1 %rhit, label %recur, label %rn
rn:
  %rk2 = add i64 %rk, 1
  br label %rl
recur:
  %re = call i64 @manticore___mc_json_err(i64 6)
  call void @__mir_json_ncat(ptr %slotp, ptr %lp, ptr @.jkw.null, i64 4)
  store i64 %lvl0, ptr @__mir_je_lvl
  ret i64 0
rpush:
  br i1 %inst, label %rst, label %ok
rst:
  %rsp = getelementptr [512 x i64], ptr @__mir_je_stk, i64 0, i64 %lvl0
  store i64 %ident, ptr %rsp
  br label %ok
ok:
  ret i64 %lvl
}
';
        // ponytail: the 10000-level floor above is a hard stop under
        // PARTIAL_OUTPUT so a reference cycle longer than the 512-entry path
        // stack cannot recurse off the C stack.
        $out .= "\ndefine void @__mir_json_cont(ptr %slotp, ptr %lp, ptr %arr, i64 %ident, i64 %isobj) {\n";
        $out .= "entry:\n";
        $out .= "  %fl = load i64, ptr @__mir_je_flags\n";
        $out .= "  %lvl = call i64 @__mir_json_enter(ptr %slotp, ptr %lp, i64 %ident)\n";
        $out .= "  %entered = icmp ne i64 %lvl, 0\n";
        $out .= "  %lvl0 = sub i64 %lvl, 1\n";
        $out .= "  br i1 %entered, label %body, label %skip\n";
        $out .= "skip:\n";
        $out .= "  ret void\n";
        $out .= "body:\n";
        // Compact out tombstones first so the list/object walk sees no holes.
        $out .= "  %alen = call i64 @__mir_array_live_len(ptr %arr)\n";
        // The buffer's element hint: a cell array may hold a raw-hinted buffer
        // (an array boxed FLAT into a cell slot), so each value is decoded by
        // it before the recursive walk — the identity on a CELL-hinted one.
        $out .= "  %jhfp = getelementptr inbounds i8, ptr %arr, i64 " . (string)\Compile\MemoryAbi::ARRAY_FLAGS_OFFSET . "\n";
        $out .= "  %jhfl = load i64, ptr %jhfp\n";
        $out .= "  %jhint = and i64 %jhfl, " . (string)\Compile\MemoryAbi::ARRAY_ELEM_HINT_MASK . "\n";
        $out .= "  %est1 = shl i64 %alen, 3\n";
        $out .= "  %est = add i64 %est1, 16\n";
        $out .= "  %rbuf = call ptr @__mir_json_reserve(ptr %slotp, ptr %lp, i64 %est)\n";
        $out .= "  %pf = and i64 %fl, 128\n";
        $out .= "  %pretty = icmp ne i64 %pf, 0\n";
        $out .= "  %nonempty = icmp sgt i64 %alen, 0\n";
        $out .= "  %pnl = and i1 %pretty, %nonempty\n";
        $out .= "  %hxf = and i64 %fl, 15\n";
        $out .= "  %hexany = icmp ne i64 %hxf, 0\n";
        $out .= "  %hashed = call i64 @__mir_array_is_hashed(ptr %arr)\n";
        $out .= "  %ishash = icmp ne i64 %hashed, 0\n";
        $out .= "  %fof = and i64 %fl, 16\n";
        $out .= "  %fobj = icmp ne i64 %fof, 0\n";
        $out .= "  %iso = icmp ne i64 %isobj, 0\n";
        $out .= "  %wobj = or i1 %iso, %fobj\n";
        $out .= "  br i1 %wobj, label %asobj, label %pick\n";
        $out .= "pick:\n";
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
        $out .= $inlinePutc(91);
        // The inlined putc ends control in `jp{N}ok`, so a phi below must name
        // THAT block as its entry predecessor, not the original label.
        $aslistExit = "jp" . ($jp - 1) . "ok";
        $out .= "  %lstride = select i1 %ishash, i64 $ES, i64 8\n";
        $out .= "  %lbias0 = select i1 %ishash, i64 $VAL_OFF, i64 0\n";
        $out .= "  %lbias = add i64 %lbias0, $H\n";
        $out .= "  br label %ll\n";
        $out .= "ll:\n";
        $out .= "  %li = phi i64 [0, %{$aslistExit}], [%li2, %lval]\n";
        $out .= "  %ldone = icmp sge i64 %li, %alen\n";
        $out .= "  br i1 %ldone, label %lend, label %lbody\n";
        $out .= "lbody:\n";
        $out .= "  %lfirst = icmp eq i64 %li, 0\n";
        $out .= "  br i1 %lfirst, label %lsep, label %lcomma\n";
        $out .= "lcomma:\n";
        $out .= $inlinePutc(44);
        $out .= "  br label %lsep\n";
        $out .= "lsep:\n";
        $out .= "  br i1 %pretty, label %lnl, label %lval\n";
        $out .= "lnl:\n";
        $out .= "  call void @__mir_json_nl(ptr %slotp, ptr %lp, i64 %lvl)\n";
        $out .= "  br label %lval\n";
        $out .= "lval:\n";
        $out .= "  %le0 = mul i64 %li, %lstride\n";
        $out .= "  %le1 = add i64 %le0, %lbias\n";
        $out .= "  %lvp = getelementptr inbounds i8, ptr %arr, i64 %le1\n";
        $out .= "  %lv0 = load i64, ptr %lvp\n";
        $out .= "  %lv = call i64 @__mir_box_by_repr(i64 %lv0, i64 %jhint)\n";
        $out .= "  call void @__mir_json_app(ptr %slotp, ptr %lp, i64 %lv)\n";
        $out .= "  %li2 = add i64 %li, 1\n";
        $out .= "  br label %ll\n";
        $out .= "lend:\n";
        $out .= "  br i1 %pnl, label %lnl2, label %lclose\n";
        $out .= "lnl2:\n";
        $out .= "  call void @__mir_json_nl(ptr %slotp, ptr %lp, i64 %lvl0)\n";
        $out .= "  br label %lclose\n";
        $out .= "lclose:\n";
        $out .= $inlinePutc(93);
        $out .= "  br label %done\n";
        // Object emit. A HASHED array reads kind/key/value per entry; a PACKED
        // one (only JSON_FORCE_OBJECT sends a packed list here) is keyed by
        // position.
        $out .= "asobj:\n";
        $out .= $inlinePutc(123);
        $asobjExit = "jp" . ($jp - 1) . "ok";
        $out .= "  br label %ol\n";
        $out .= "ol:\n";
        $out .= "  %oi = phi i64 [0, %{$asobjExit}], [%oi2, %oval]\n";
        $out .= "  %odone = icmp sge i64 %oi, %alen\n";
        $out .= "  br i1 %odone, label %oend, label %obody\n";
        $out .= "obody:\n";
        $out .= "  %ofirst = icmp eq i64 %oi, 0\n";
        $out .= "  br i1 %ofirst, label %osep, label %ocomma\n";
        $out .= "ocomma:\n";
        $out .= $inlinePutc(44);
        $out .= "  br label %osep\n";
        $out .= "osep:\n";
        $out .= "  br i1 %pretty, label %onl, label %okey\n";
        $out .= "onl:\n";
        $out .= "  call void @__mir_json_nl(ptr %slotp, ptr %lp, i64 %lvl)\n";
        $out .= "  br label %okey\n";
        $out .= "okey:\n";
        $out .= "  %oe0 = mul i64 %oi, $ES\n";
        $out .= "  %oe1 = add i64 %oe0, $H\n";
        $out .= "  br i1 %ishash, label %okH, label %okP\n";
        $out .= "okP:\n";
        $out .= $inlinePutc(34);
        $out .= "  call void @__mir_json_int(ptr %slotp, ptr %lp, i64 %oi)\n";
        $out .= $inlinePutc(34);
        $out .= "  br label %okdone\n";
        $out .= "okH:\n";
        $out .= "  %okp = getelementptr inbounds i8, ptr %arr, i64 %oe1\n";
        $out .= "  %okind = load i64, ptr %okp\n";
        $out .= "  %oe2 = add i64 %oe1, $KEY_OFF\n";
        $out .= "  %oyp = getelementptr inbounds i8, ptr %arr, i64 %oe2\n";
        $out .= "  %okstr = icmp eq i64 %okind, $KIND_STR\n";
        $out .= "  br i1 %okstr, label %okS, label %okI\n";
        // Object string KEY: the hot repeated case (every object entry). A key
        // is almost always a short escape-free identifier, so scan it inline
        // and, when clean, emit `"key"` with one reserve + memcpy — no estr
        // CALL, no strlen CALL. The moment a byte needs escaping (control / " /
        // \\ / / / non-ASCII), or any JSON_HEX_* flag is set, fall back to the
        // full estr. Measured: object encode was ~51 ns PER KEY through the
        // estr call chain, the dominant object-emit cost.
        $out .= "okS:\n";
        $out .= "  %oksptr = load ptr, ptr %oyp\n";
        $out .= "  call void @__mir_json_skey(ptr %slotp, ptr %lp, ptr %oksptr)\n";
        $out .= "  br label %okcolon\n";
        $out .= "okI:\n";
        $out .= $inlinePutc(34);
        $out .= "  %okiv = load i64, ptr %oyp\n";
        $out .= "  call void @__mir_json_int(ptr %slotp, ptr %lp, i64 %okiv)\n";
        $out .= $inlinePutc(34);
        $out .= "  br label %okdone\n";
        $out .= "okdone:\n";
        $out .= $inlinePutc(58);
        $out .= "  br label %okcolon\n";
        $out .= "okcolon:\n";
        $out .= "  br i1 %pretty, label %ocsp, label %oval\n";
        $out .= "ocsp:\n";
        $out .= $inlinePutc(32);
        $out .= "  br label %oval\n";
        $out .= "oval:\n";
        $out .= "  %oeh = add i64 %oe1, $VAL_OFF\n";
        $out .= "  %oep0 = shl i64 %oi, 3\n";
        $out .= "  %oep = add i64 %oep0, $H\n";
        $out .= "  %oe3 = select i1 %ishash, i64 %oeh, i64 %oep\n";
        $out .= "  %ovp = getelementptr inbounds i8, ptr %arr, i64 %oe3\n";
        $out .= "  %ov0 = load i64, ptr %ovp\n";
        $out .= "  %ov = call i64 @__mir_box_by_repr(i64 %ov0, i64 %jhint)\n";
        $out .= "  call void @__mir_json_app(ptr %slotp, ptr %lp, i64 %ov)\n";
        $out .= "  %oi2 = add i64 %oi, 1\n";
        $out .= "  br label %ol\n";
        $out .= "oend:\n";
        $out .= "  br i1 %pnl, label %onl2, label %oclose\n";
        $out .= "onl2:\n";
        $out .= "  call void @__mir_json_nl(ptr %slotp, ptr %lp, i64 %lvl0)\n";
        $out .= "  br label %oclose\n";
        $out .= "oclose:\n";
        $out .= $inlinePutc(125);
        $out .= "  br label %done\n";
        $out .= "done:\n";
        $out .= "  store i64 %lvl0, ptr @__mir_je_lvl\n";
        $out .= "  ret void\n}\n";

        // `"key":` for a string object key. The hot repeated case: a key is
        // almost always a short escape-free identifier, so it is scanned here
        // and, when clean, written with one reserve and a short copy; the
        // moment a byte needs escaping (control / " / \\ / / / non-ASCII), or
        // a JSON_HEX_* flag is set, it goes through estr. A literal key is
        // IMMORTAL (rc -1, never freed, so its address is never reused): one
        // found clean is remembered by address for the rest of the program —
        // a record list repeats the same few keys per row.
        $out .= '
define void @__mir_json_skey(ptr %slotp, ptr %lp, ptr %k) alwaysinline {
entry:
  %fl = load i64, ptr @__mir_je_flags
  %hxf = and i64 %fl, 15
  %hexany = icmp ne i64 %hxf, 0
  %klenp = getelementptr inbounds i8, ptr %k, i64 -16
  %klen = load i64, ptr %klenp
  %krcp = getelementptr inbounds i8, ptr %k, i64 ' . (string)\Compile\MemoryAbi::STRING_RC_OFFSET . '
  %krc = load i64, ptr %krcp
  %kimm = icmp eq i64 %krc, -1
  %kpi = ptrtoint ptr %k to i64
  %kh0 = lshr i64 %kpi, 4
  %kh = and i64 %kh0, 63
  %kcp = getelementptr [64 x i64], ptr @__mir_je_kc, i64 0, i64 %kh
  %kcv = load i64, ptr %kcp
  %kch = icmp eq i64 %kcv, %kpi
  br i1 %hexany, label %kslow, label %kcache
kcache:
  br i1 %kch, label %kclean, label %ksscan
ksscan:
  %ksi = phi i64 [0, %kcache], [%ksi2, %ksn]
  %ksdone = icmp sge i64 %ksi, %klen
  br i1 %ksdone, label %kclean, label %ksb
ksb:
  %ksp = getelementptr inbounds i8, ptr %k, i64 %ksi
  %ksc = load i8, ptr %ksp
  %ksz = zext i8 %ksc to i64
  %kslt = icmp ult i64 %ksz, 32
  %ksq = icmp eq i64 %ksz, 34
  %ksbs = icmp eq i64 %ksz, 92
  %kssl = icmp eq i64 %ksz, 47
  %kshi = icmp uge i64 %ksz, 128
  %ksd1 = or i1 %kslt, %ksq
  %ksd2 = or i1 %ksd1, %ksbs
  %ksd3 = or i1 %ksd2, %kssl
  %ksdirty = or i1 %ksd3, %kshi
  br i1 %ksdirty, label %kslow, label %ksn
ksn:
  %ksi2 = add i64 %ksi, 1
  br label %ksscan
kslow:
  call void @__mir_json_estr(ptr %slotp, ptr %lp, ptr %k)
  call void @__mir_json_putc(ptr %slotp, ptr %lp, i64 58)
  ret void
kclean:
  br i1 %kimm, label %kremember, label %kwrite
kremember:
  store i64 %kpi, ptr %kcp
  br label %kwrite
kwrite:
  %krsv = add i64 %klen, 3
  %kbuf = call ptr @__mir_json_reserve(ptr %slotp, ptr %lp, i64 %krsv)
  %kw0 = load i64, ptr %lp
  %kq0 = getelementptr inbounds i8, ptr %kbuf, i64 %kw0
  store i8 34, ptr %kq0
  %kw1 = add i64 %kw0, 1
  %kdst = getelementptr inbounds i8, ptr %kbuf, i64 %kw1
  call void @__mir_json_cpy(ptr %kdst, ptr %k, i64 %klen)
  %kw2 = add i64 %kw1, %klen
  %kq1 = getelementptr inbounds i8, ptr %kbuf, i64 %kw2
  store i8 34, ptr %kq1
  %kw3 = add i64 %kw2, 1
  %kq2 = getelementptr inbounds i8, ptr %kbuf, i64 %kw3
  store i8 58, ptr %kq2
  %kw4 = add i64 %kw3, 1
  store i64 %kw4, ptr %lp
  ret void
}

';
        // One `key: value` of an object walked through its descriptor's
        // visit_fn. `%ctx` is the walk: [slotp, lp, count, level]. A null
        // `%k` is the int key `%ik` (a dynamic property named by a number).
        $out .= '
define void @__mir_json_pair(ptr %ctx, ptr %k, i64 %ik, i64 %cell) {
entry:
  %slotp = load ptr, ptr %ctx
  %lpp = getelementptr inbounds i64, ptr %ctx, i64 1
  %lp = load ptr, ptr %lpp
  %cp = getelementptr inbounds i64, ptr %ctx, i64 2
  %cnt = load i64, ptr %cp
  %cnt1 = add i64 %cnt, 1
  store i64 %cnt1, ptr %cp
  %fl = load i64, ptr @__mir_je_flags
  %pf = and i64 %fl, 128
  %pretty = icmp ne i64 %pf, 0
  %first = icmp eq i64 %cnt, 0
  br i1 %first, label %sep, label %comma
comma:
  call void @__mir_json_putc(ptr %slotp, ptr %lp, i64 44)
  br label %sep
sep:
  br i1 %pretty, label %nl, label %key
nl:
  %lvp = getelementptr inbounds i64, ptr %ctx, i64 3
  %lv = load i64, ptr %lvp
  call void @__mir_json_nl(ptr %slotp, ptr %lp, i64 %lv)
  br label %key
key:
  %isn = icmp eq ptr %k, null
  br i1 %isn, label %ikey, label %skey
skey:
  call void @__mir_json_skey(ptr %slotp, ptr %lp, ptr %k)
  br label %val
ikey:
  call void @__mir_json_putc(ptr %slotp, ptr %lp, i64 34)
  call void @__mir_json_int(ptr %slotp, ptr %lp, i64 %ik)
  call void @__mir_json_putc(ptr %slotp, ptr %lp, i64 34)
  call void @__mir_json_putc(ptr %slotp, ptr %lp, i64 58)
  br label %val
val:
  br i1 %pretty, label %sp, label %app
sp:
  call void @__mir_json_putc(ptr %slotp, ptr %lp, i64 32)
  br label %app
app:
  call void @__mir_json_app(ptr %slotp, ptr %lp, i64 %cell)
  ret void
}

';
        // The visit_fn callback: a declared property, or (null key) the
        // dynamic bag as a raw array — every entry, string or int keyed.
        $out .= '
define void @__mir_json_pcb(ptr %ctx, ptr %key, i64 %cell) {
entry:
  %isbag = icmp eq ptr %key, null
  br i1 %isbag, label %bag, label %one
one:
  call void @__mir_json_pair(ptr %ctx, ptr %key, i64 0, i64 %cell)
  ret void
bag:
  %arr = inttoptr i64 %cell to ptr
  %nul = icmp eq ptr %arr, null
  br i1 %nul, label %done, label %walk
walk:
  %alen = call i64 @__mir_array_live_len(ptr %arr)
  %hfp = getelementptr inbounds i8, ptr %arr, i64 ' . (string)\Compile\MemoryAbi::ARRAY_FLAGS_OFFSET . '
  %hfl = load i64, ptr %hfp
  %hint = and i64 %hfl, ' . (string)\Compile\MemoryAbi::ARRAY_ELEM_HINT_MASK . '
  %hashed = call i64 @__mir_array_is_hashed(ptr %arr)
  %ishash = icmp ne i64 %hashed, 0
  br label %lp
lp:
  %i = phi i64 [0, %walk], [%i1, %next]
  %fin = icmp sge i64 %i, %alen
  br i1 %fin, label %done, label %ent
ent:
  br i1 %ishash, label %eh, label %ep
ep:
  %po0 = shl i64 %i, 3
  %po = add i64 %po0, ' . $H . '
  %pvp = getelementptr inbounds i8, ptr %arr, i64 %po
  %pv0 = load i64, ptr %pvp
  %pv = call i64 @__mir_box_by_repr(i64 %pv0, i64 %hint)
  call void @__mir_json_pair(ptr %ctx, ptr null, i64 %i, i64 %pv)
  br label %next
eh:
  %e0 = mul i64 %i, ' . $ES . '
  %e1 = add i64 %e0, ' . $H . '
  %kp = getelementptr inbounds i8, ptr %arr, i64 %e1
  %kind = load i64, ptr %kp
  %e2 = add i64 %e1, ' . $KEY_OFF . '
  %kyp = getelementptr inbounds i8, ptr %arr, i64 %e2
  %kw = load i64, ptr %kyp
  %e3 = add i64 %e1, ' . $VAL_OFF . '
  %vp = getelementptr inbounds i8, ptr %arr, i64 %e3
  %v0 = load i64, ptr %vp
  %v = call i64 @__mir_box_by_repr(i64 %v0, i64 %hint)
  %isstr = icmp eq i64 %kind, ' . $KIND_STR . '
  br i1 %isstr, label %es, label %ei
es:
  %ks = inttoptr i64 %kw to ptr
  call void @__mir_json_pair(ptr %ctx, ptr %ks, i64 0, i64 %v)
  br label %next
ei:
  call void @__mir_json_pair(ptr %ctx, ptr null, i64 %kw, i64 %v)
  br label %next
next:
  %i1 = add i64 %i, 1
  br label %lp
done:
  ret void
}

';
        // Entry: install the options (saving the outer walk's — a
        // jsonSerialize() may call json_encode itself), reset the error slot as
        // php does, walk into ONE growing buffer and commit len/NUL once.
        $out .= '
define ptr @__mir_json_encf(i64 %cell, i64 %flags, i64 %depth) {
entry:
  %of = load i64, ptr @__mir_je_flags
  %om = load i64, ptr @__mir_je_max
  %ol = load i64, ptr @__mir_je_lvl
  store i64 %flags, ptr @__mir_je_flags
  store i64 %depth, ptr @__mir_je_max
  store i64 0, ptr @__mir_je_lvl
  %e0 = call i64 @manticore___mc_json_err(i64 0)
  %buf0 = call ptr @__mir_str_alloc(i64 16)
  %slot = alloca ptr
  store ptr %buf0, ptr %slot
  %lcur = alloca i64
  store i64 0, ptr %lcur
  call void @__mir_json_app(ptr %slot, ptr %lcur, i64 %cell)
  store i64 %of, ptr @__mir_je_flags
  store i64 %om, ptr @__mir_je_max
  store i64 %ol, ptr @__mir_je_lvl
  %r = load ptr, ptr %slot
  %flen = load i64, ptr %lcur
  %fnul = getelementptr inbounds i8, ptr %r, i64 %flen
  store i8 0, ptr %fnul
  call void @__mir_str_set_len(ptr %r, i64 %flen)
  ret ptr %r
}
';
        return $out;
    }

    /**
     * The `strtod` declaration every module that converts a string to a float
     * carries, together with `@__mir_php_strtod(ptr s, ptr end)`: strtod with
     * php's reading. libc's also reads hex (`0x1A` → 26), `inf`/`infinity`
     * and `nan`, none of which php calls a number — `(float)"0x1A"` is 0.0 and
     * `is_numeric("inf")` false. Past the leading whitespace and sign, an `i` or
     * `n` converts nothing (+0.0, `*end = s`), and `0x` stops after the `0`
     * (a signed zero); every other input is libc's own decimal parse. Every
     * php-level conversion calls this, never `@strtod` directly.
     */
    public static function strtodDecl(): string
    {
        return 'declare double @strtod(ptr, ptr)
define double @__mir_php_strtod(ptr %s, ptr %end) {
entry:
  br label %ws
ws:
  %p = phi ptr [ %s, %entry ], [ %p1, %wsn ]
  %c = load i8, ptr %p
  %w0 = icmp eq i8 %c, 32
  %w1 = sub i8 %c, 9
  %w2 = icmp ult i8 %w1, 5
  %isw = or i1 %w0, %w2
  br i1 %isw, label %wsn, label %sgn
wsn:
  %p1 = getelementptr inbounds i8, ptr %p, i64 1
  br label %ws
sgn:
  %ispl = icmp eq i8 %c, 43
  %ismi = icmp eq i8 %c, 45
  %issg = or i1 %ispl, %ismi
  %p2 = getelementptr inbounds i8, ptr %p, i64 1
  %q = select i1 %issg, ptr %p2, ptr %p
  %d = load i8, ptr %q
  %dl = or i8 %d, 32
  %isi = icmp eq i8 %dl, 105
  %isn = icmp eq i8 %dl, 110
  %word = or i1 %isi, %isn
  br i1 %word, label %none, label %chk0
chk0:
  %is0 = icmp eq i8 %d, 48
  br i1 %is0, label %chkx, label %libc
chkx:
  %q1 = getelementptr inbounds i8, ptr %q, i64 1
  %x = load i8, ptr %q1
  %xl = or i8 %x, 32
  %isx = icmp eq i8 %xl, 120
  br i1 %isx, label %zero, label %libc
zero:
  %zn = icmp eq ptr %end, null
  br i1 %zn, label %zret, label %zst
zst:
  store ptr %q1, ptr %end
  br label %zret
zret:
  %z = select i1 %ismi, double -0.0, double 0.0
  ret double %z
none:
  %nn = icmp eq ptr %end, null
  br i1 %nn, label %nret, label %nst
nst:
  store ptr %s, ptr %end
  br label %nret
nret:
  ret double 0.0
libc:
  %r = call double @strtod(ptr %s, ptr %end)
  ret double %r
}';
    }

    /**
     * UTF-8 primitives shared by the json encoder and decoder.
     *
     * `@__mir_json_bat(s, i, n)` is a bounded byte read: s[i] for i < n, else 0
     * — never touches memory past the allocation.
     *
     * `@__mir_json_u8(s, i, n)` decodes the sequence at s[i] (a byte >= 0x80)
     * by php_next_utf8_char's rules: `(cp << 3) | length` when it is valid
     * (shortest form, no surrogate, <= U+10FFFF), else `-advance`, where the
     * advance is how many bytes php skips as ONE invalid character — it stops
     * before a byte that could start a sequence. Reading past the end yields
     * 0, which is a lead byte, so the end of input and a NUL agree with php's
     * own `avail` checks.
     */
    public function jsonUtf8(): string
    {
        return '
define i64 @__mir_json_bat(ptr %s, i64 %i, i64 %n) {
entry:
  %in = icmp slt i64 %i, %n
  br i1 %in, label %ld, label %z
ld:
  %p = getelementptr inbounds i8, ptr %s, i64 %i
  %b = load i8, ptr %p
  %bz = zext i8 %b to i64
  ret i64 %bz
z:
  ret i64 0
}

define i1 @__mir_json_lead(i64 %b) {
entry:
  %a = icmp ult i64 %b, 128
  %c0 = icmp uge i64 %b, 194
  %c1 = icmp ule i64 %b, 244
  %c = and i1 %c0, %c1
  %r = or i1 %a, %c
  ret i1 %r
}

define i64 @__mir_json_u8(ptr %s, i64 %i, i64 %n) {
entry:
  %c = call i64 @__mir_json_bat(ptr %s, i64 %i, i64 %n)
  %i1 = add i64 %i, 1
  %i2 = add i64 %i, 2
  %i3 = add i64 %i, 3
  %b1 = call i64 @__mir_json_bat(ptr %s, i64 %i1, i64 %n)
  %b2 = call i64 @__mir_json_bat(ptr %s, i64 %i2, i64 %n)
  %b3 = call i64 @__mir_json_bat(ptr %s, i64 %i3, i64 %n)
  %m1 = and i64 %b1, 192
  %t1 = icmp eq i64 %m1, 128
  %m2 = and i64 %b2, 192
  %t2 = icmp eq i64 %m2, 128
  %m3 = and i64 %b3, 192
  %t3 = icmp eq i64 %m3, 128
  %l1 = call i1 @__mir_json_lead(i64 %b1)
  %l2 = call i1 @__mir_json_lead(i64 %b2)
  %l3 = call i1 @__mir_json_lead(i64 %b3)
  %v1 = and i64 %b1, 63
  %v2 = and i64 %b2, 63
  %v3 = and i64 %b3, 63
  %lt2 = icmp ult i64 %c, 194
  br i1 %lt2, label %bad1, label %k2
k2:
  %lt3 = icmp ult i64 %c, 224
  br i1 %lt3, label %two, label %k3
k3:
  %lt4 = icmp ult i64 %c, 240
  br i1 %lt4, label %three, label %k4
k4:
  %lt5 = icmp ult i64 %c, 245
  br i1 %lt5, label %four, label %bad1
two:
  br i1 %l1, label %bad1, label %two1
two1:
  br i1 %t1, label %two2, label %bad2
two2:
  %c2 = and i64 %c, 31
  %c2s = shl i64 %c2, 6
  %cp2 = or i64 %c2s, %v1
  %r2s = shl i64 %cp2, 3
  %r2 = or i64 %r2s, 2
  ret i64 %r2
three:
  %t12 = and i1 %t1, %t2
  br i1 %t12, label %three2, label %three1
three1:
  br i1 %l1, label %bad1, label %three1b
three1b:
  br i1 %l2, label %bad2, label %bad3
three2:
  %c3 = and i64 %c, 15
  %c3s = shl i64 %c3, 12
  %v1s = shl i64 %v1, 6
  %cp3a = or i64 %c3s, %v1s
  %cp3 = or i64 %cp3a, %v2
  %ov3 = icmp ult i64 %cp3, 2048
  %sg0 = icmp uge i64 %cp3, 55296
  %sg1 = icmp ule i64 %cp3, 57343
  %sg = and i1 %sg0, %sg1
  %bad3c = or i1 %ov3, %sg
  br i1 %bad3c, label %bad3, label %three3
three3:
  %r3s = shl i64 %cp3, 3
  %r3 = or i64 %r3s, 3
  ret i64 %r3
four:
  %t123a = and i1 %t1, %t2
  %t123 = and i1 %t123a, %t3
  br i1 %t123, label %four2, label %four1
four1:
  br i1 %l1, label %bad1, label %four1b
four1b:
  br i1 %l2, label %bad2, label %four1c
four1c:
  br i1 %l3, label %bad3, label %bad4
four2:
  %c4 = and i64 %c, 7
  %c4s = shl i64 %c4, 18
  %w1 = shl i64 %v1, 12
  %w2 = shl i64 %v2, 6
  %cp4a = or i64 %c4s, %w1
  %cp4b = or i64 %cp4a, %w2
  %cp4 = or i64 %cp4b, %v3
  %lo4 = icmp ult i64 %cp4, 65536
  %hi4 = icmp ugt i64 %cp4, 1114111
  %bad4c = or i1 %lo4, %hi4
  br i1 %bad4c, label %bad4, label %four3
four3:
  %r4s = shl i64 %cp4, 3
  %r4 = or i64 %r4s, 4
  ret i64 %r4
bad1:
  ret i64 -1
bad2:
  ret i64 -2
bad3:
  ret i64 -3
bad4:
  ret i64 -4
}
';
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

    /**
     * Native json_decode runtime — the mirror of {@see jsonEnc}.
     *
     * `@__mir_json_dec(ptr s, i64 n, ptr posSlot) -> i64` is a recursive
     * descent that returns a NaN-boxed cell and advances `*posSlot`. Containers
     * are built straight as CELL arrays (`__mir_array_alloc` + append for a
     * list, `__mir_array_alloc_hashed` + `set_str` for an object) with the
     * repr nibble stamped, so nothing downstream has to rebuild them.
     *
     * It replaces {@see \Runtime\Json\Parser}, which stayed at php's own speed
     * because it was allocator-bound: `substr` minted a heap string per key and
     * per value, every value crossed five PHP calls, and each container grew
     * from zero. Here a string literal is ONE `__mir_str_new` over the scanned
     * run (the escape path is the only one that copies twice), containers start
     * at capacity 8, and an integer accumulates out of the digit scan with
     * unsigned overflow detection so only a genuine overflow pays `strtod`.
     *
     * It is a validator with php's error codes: the strict number and keyword
     * grammar, the eight escapes, unpaired surrogates (UTF16), raw controls
     * (CTRL_CHAR), UTF-8 validity (UTF8, or dropped / U+FFFD under the
     * INVALID_UTF8 flags), the wrong closer (STATE_MISMATCH), `$depth`, and a
     * NUL-led property name (INVALID_PROPERTY_NAME). The first error wins; the
     * rejected document is freed and answers null.
     */
    /**
     * @param int    $stdSize   `stdClass` instance size, or 0 when the module has
     *                          no stdClass — then `$assoc = 0` degrades to the
     *                          assoc array rather than emitting a null descriptor.
     * @param int    $stdBagOff offset of stdClass's dynamic-property bag
     * @param string $stdDesc   i64 operand for its class descriptor
     */
    public function jsonDec(int $stdSize = 0, int $stdBagOff = 16, string $stdDesc = '0'): string
    {
        // The document's options and the recursion depth, as state rather than
        // threaded arguments: the decoder's symbols are linkonce_odr and
        // coalesce BY NAME, so widening one in place is a mismatch the linker
        // resolves silently. MUTABLE state, so `linkonce_odr` too — an
        // `internal` global would split into one copy per object file while the
        // coalesced functions read just one. Single-threaded by construction —
        // the decoder never yields or calls user code — and installed at every
        // document entry ({@see __mir_json_decf}).
        $out = "\n@__mir_jd_depth = linkonce_odr global i64 0\n";
        $out .= "@__mir_jd_max = linkonce_odr global i64 512\n";
        $out .= "@__mir_jd_flags = linkonce_odr global i64 0\n";
        // 10^0..10^22, every one exact in a double: `m / 10^k` with `m` < 2^53
        // is then ONE correctly rounded division — strtod's own answer
        // (Clinger's fast path), without strtod.
        $out .= "@__mir_jd_p10 = private unnamed_addr constant [23 x double] [double 1.0e0, double 1.0e1, double 1.0e2, double 1.0e3, double 1.0e4, double 1.0e5, double 1.0e6, double 1.0e7, double 1.0e8, double 1.0e9, double 1.0e10, double 1.0e11, double 1.0e12, double 1.0e13, double 1.0e14, double 1.0e15, double 1.0e16, double 1.0e17, double 1.0e18, double 1.0e19, double 1.0e20, double 1.0e21, double 1.0e22]\n";
        $out .= "@.jdkw.true = private unnamed_addr constant [5 x i8] c\"true\\00\", align 1\n";
        $out .= "@.jdkw.false = private unnamed_addr constant [6 x i8] c\"false\\00\", align 1\n";
        $out .= "@.jdkw.null = private unnamed_addr constant [5 x i8] c\"null\\00\", align 1\n";
        $out .= "@.jdkw.repl = private unnamed_addr constant [4 x i8] c\"\EF\BF\BD\\00\", align 1\n";

        // A literal keyword at p: true when all `len` bytes match.
        // A malformed document's error code at p: JSON_ERROR_CTRL_CHAR for a
        // NUL byte outside a string (php's scanner), else `code`.
        // A canonical int key ("-?[1-9][0-9]*|0" in range) → *out, true.
        $out .= '
define i1 @__mir_jd_kw(ptr %s, i64 %n, i64 %p, ptr %kw, i64 %len) {
entry:
  %end = add i64 %p, %len
  %fits = icmp sle i64 %end, %n
  br i1 %fits, label %cmp, label %no
cmp:
  %sp = getelementptr inbounds i8, ptr %s, i64 %p
  %r = call i32 @memcmp(ptr %sp, ptr %kw, i64 %len)
  %eq = icmp eq i32 %r, 0
  ret i1 %eq
no:
  ret i1 false
}

define void @__mir_jd_bad(ptr %s, i64 %n, ptr %pp, i64 %code) {
entry:
  %p = load i64, ptr %pp
  %b = call i64 @__mir_json_bat(ptr %s, i64 %p, i64 %n)
  %in = icmp slt i64 %p, %n
  %nul = icmp eq i64 %b, 0
  %ctl = and i1 %in, %nul
  %c = select i1 %ctl, i64 3, i64 %code
  %e = call i64 @manticore___mc_json_err(i64 %c)
  ret void
}

define i1 @__mir_jd_ikey(ptr %k, ptr %out) {
entry:
  %lp = getelementptr inbounds i8, ptr %k, i64 -16
  %len = load i64, ptr %lp
  %c0 = call i64 @__mir_json_bat(ptr %k, i64 0, i64 %len)
  %neg = icmp eq i64 %c0, 45
  %i0 = select i1 %neg, i64 1, i64 0
  %d0 = call i64 @__mir_json_bat(ptr %k, i64 %i0, i64 %len)
  %isz = icmp eq i64 %d0, 48
  br i1 %isz, label %zero, label %nz
zero:
  %z1 = add i64 %i0, 1
  %only = icmp eq i64 %z1, %len
  %nneg = xor i1 %neg, true
  %zok = and i1 %only, %nneg
  br i1 %zok, label %zret, label %no
zret:
  store i64 0, ptr %out
  ret i1 true
nz:
  %d0m = sub i64 %d0, 49
  %d0ok = icmp ult i64 %d0m, 9
  %short = icmp sle i64 %len, 20
  %go = and i1 %d0ok, %short
  br i1 %go, label %lp0, label %no
lp0:
  %i = phi i64 [%i0, %nz], [%i1, %step]
  %a = phi i64 [0, %nz], [%a2, %step]
  %done = icmp sge i64 %i, %len
  br i1 %done, label %fin, label %body
body:
  %b = call i64 @__mir_json_bat(ptr %k, i64 %i, i64 %len)
  %dg = sub i64 %b, 48
  %isd = icmp ult i64 %dg, 10
  br i1 %isd, label %chk, label %no
chk:
  %bound = select i1 %neg, i64 -9223372036854775808, i64 9223372036854775807
  %q = udiv i64 %bound, 10
  %rr = urem i64 %bound, 10
  %tooBig = icmp ugt i64 %a, %q
  %atQ = icmp eq i64 %a, %q
  %dgHi = icmp ugt i64 %dg, %rr
  %edge = and i1 %atQ, %dgHi
  %over = or i1 %tooBig, %edge
  br i1 %over, label %no, label %step
step:
  %a10 = mul i64 %a, 10
  %a2 = add i64 %a10, %dg
  %i1 = add i64 %i, 1
  br label %lp0
fin:
  %na = sub i64 0, %a
  %v = select i1 %neg, i64 %na, i64 %a
  store i64 %v, ptr %out
  ret i1 true
no:
  ret i1 false
}
';

        // ── skip space / tab / LF / CR ──
        // Inlined at every token boundary: the common answer is "no blank".
        $out .= "\ndefine void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp) alwaysinline {\n";
        $out .= "entry:\n  br label %lp\n";
        $out .= "lp:\n";
        $out .= "  %i = load i64, ptr %pp\n";
        $out .= "  %ge = icmp sge i64 %i, %n\n";
        $out .= "  br i1 %ge, label %done, label %chk\n";
        $out .= "chk:\n";
        $out .= "  %p = getelementptr inbounds i8, ptr %s, i64 %i\n";
        $out .= "  %b = load i8, ptr %p\n";
        $out .= "  %bz = zext i8 %b to i64\n";
        $out .= "  %c1 = icmp eq i64 %bz, 32\n";
        $out .= "  %c2 = icmp eq i64 %bz, 9\n";
        $out .= "  %c3 = icmp eq i64 %bz, 10\n";
        $out .= "  %c4 = icmp eq i64 %bz, 13\n";
        $out .= "  %o1 = or i1 %c1, %c2\n";
        $out .= "  %o2 = or i1 %c3, %c4\n";
        $out .= "  %o3 = or i1 %o1, %o2\n";
        $out .= "  br i1 %o3, label %adv, label %done\n";
        $out .= "adv:\n";
        $out .= "  %i1 = add i64 %i, 1\n";
        $out .= "  store i64 %i1, ptr %pp\n";
        $out .= "  br label %lp\n";
        $out .= "done:\n  ret void\n}\n";

        // ── 4 hex digits at s[i..i+3] → codepoint, or -1 ──
        $out .= "\ndefine i64 @__mir_jd_hex4(ptr %s, i64 %i, i64 %n) {\n";
        $out .= "entry:\n";
        $out .= "  %end = add i64 %i, 4\n";
        $out .= "  %fits = icmp sle i64 %end, %n\n";
        $out .= "  br i1 %fits, label %go, label %bad\n";
        $out .= "bad:\n  ret i64 -1\n";
        $out .= "go:\n";
        $out .= "  %acc = alloca i64\n  store i64 0, ptr %acc\n";
        $out .= "  %kk = alloca i64\n  store i64 0, ptr %kk\n";
        $out .= "  br label %lp\n";
        $out .= "lp:\n";
        $out .= "  %k = load i64, ptr %kk\n";
        $out .= "  %k4 = icmp sge i64 %k, 4\n";
        $out .= "  br i1 %k4, label %fin, label %body\n";
        $out .= "body:\n";
        $out .= "  %ix = add i64 %i, %k\n";
        $out .= "  %p = getelementptr inbounds i8, ptr %s, i64 %ix\n";
        $out .= "  %b = load i8, ptr %p\n";
        $out .= "  %bz = zext i8 %b to i64\n";
        $out .= "  %d0 = sub i64 %bz, 48\n";
        $out .= "  %isd = icmp ult i64 %d0, 10\n";
        $out .= "  %la = or i64 %bz, 32\n";                 // fold case
        $out .= "  %d1 = sub i64 %la, 97\n";                // 'a'..'f' → 0..5
        $out .= "  %ish = icmp ult i64 %d1, 6\n";
        $out .= "  %hexok = or i1 %isd, %ish\n";
        $out .= "  br i1 %hexok, label %acc1, label %bad2\n";
        $out .= "bad2:\n  ret i64 -1\n";
        $out .= "acc1:\n";
        $out .= "  %dv0 = add i64 %d1, 10\n";
        $out .= "  %dv = select i1 %isd, i64 %d0, i64 %dv0\n";
        $out .= "  %a = load i64, ptr %acc\n";
        $out .= "  %a16 = shl i64 %a, 4\n";
        $out .= "  %a2 = or i64 %a16, %dv\n";
        $out .= "  store i64 %a2, ptr %acc\n";
        $out .= "  %k1 = add i64 %k, 1\n";
        $out .= "  store i64 %k1, ptr %kk\n";
        $out .= "  br label %lp\n";
        $out .= "fin:\n";
        $out .= "  %r = load i64, ptr %acc\n";
        $out .= "  ret i64 %r\n}\n";

        // ── UTF-8 encode %cp at %b+%j; returns the new j ──
        $out .= "\ndefine i64 @__mir_jd_utf8(ptr %b, i64 %j, i64 %cp) {\n";
        $out .= "entry:\n";
        $out .= "  %l1 = icmp ult i64 %cp, 128\n";
        $out .= "  br i1 %l1, label %one, label %t2\n";
        $out .= "one:\n";
        $out .= "  %d = getelementptr inbounds i8, ptr %b, i64 %j\n";
        $out .= "  %c8 = trunc i64 %cp to i8\n";
        $out .= "  store i8 %c8, ptr %d\n";
        $out .= "  %j1 = add i64 %j, 1\n";
        $out .= "  ret i64 %j1\n";
        $out .= "t2:\n";
        $out .= "  %l2 = icmp ult i64 %cp, 2048\n";
        $out .= "  br i1 %l2, label %two, label %t3\n";
        $out .= "two:\n";
        $out .= "  %a0 = lshr i64 %cp, 6\n";
        $out .= "  %a1 = or i64 %a0, 192\n";
        $out .= "  %a2 = and i64 %cp, 63\n";
        $out .= "  %a3 = or i64 %a2, 128\n";
        $out .= "  %p0 = getelementptr inbounds i8, ptr %b, i64 %j\n";
        $out .= "  %t0 = trunc i64 %a1 to i8\n";
        $out .= "  store i8 %t0, ptr %p0\n";
        $out .= "  %jj = add i64 %j, 1\n";
        $out .= "  %p1 = getelementptr inbounds i8, ptr %b, i64 %jj\n";
        $out .= "  %t1 = trunc i64 %a3 to i8\n";
        $out .= "  store i8 %t1, ptr %p1\n";
        $out .= "  %j2 = add i64 %j, 2\n";
        $out .= "  ret i64 %j2\n";
        $out .= "t3:\n";
        $out .= "  %l3 = icmp ult i64 %cp, 65536\n";
        $out .= "  br i1 %l3, label %three, label %four\n";
        $out .= "three:\n";
        $out .= "  %b0 = lshr i64 %cp, 12\n";
        $out .= "  %b1 = or i64 %b0, 224\n";
        $out .= "  %b2 = lshr i64 %cp, 6\n";
        $out .= "  %b3 = and i64 %b2, 63\n";
        $out .= "  %b4 = or i64 %b3, 128\n";
        $out .= "  %b5 = and i64 %cp, 63\n";
        $out .= "  %b6 = or i64 %b5, 128\n";
        $out .= "  %q0 = getelementptr inbounds i8, ptr %b, i64 %j\n";
        $out .= "  %u0 = trunc i64 %b1 to i8\n";
        $out .= "  store i8 %u0, ptr %q0\n";
        $out .= "  %jq1 = add i64 %j, 1\n";
        $out .= "  %q1 = getelementptr inbounds i8, ptr %b, i64 %jq1\n";
        $out .= "  %u1 = trunc i64 %b4 to i8\n";
        $out .= "  store i8 %u1, ptr %q1\n";
        $out .= "  %jq2 = add i64 %j, 2\n";
        $out .= "  %q2 = getelementptr inbounds i8, ptr %b, i64 %jq2\n";
        $out .= "  %u2 = trunc i64 %b6 to i8\n";
        $out .= "  store i8 %u2, ptr %q2\n";
        $out .= "  %j3 = add i64 %j, 3\n";
        $out .= "  ret i64 %j3\n";
        $out .= "four:\n";
        $out .= "  %e0 = lshr i64 %cp, 18\n";
        $out .= "  %e1 = or i64 %e0, 240\n";
        $out .= "  %e2 = lshr i64 %cp, 12\n";
        $out .= "  %e3 = and i64 %e2, 63\n";
        $out .= "  %e4 = or i64 %e3, 128\n";
        $out .= "  %e5 = lshr i64 %cp, 6\n";
        $out .= "  %e6 = and i64 %e5, 63\n";
        $out .= "  %e7 = or i64 %e6, 128\n";
        $out .= "  %e8 = and i64 %cp, 63\n";
        $out .= "  %e9 = or i64 %e8, 128\n";
        $out .= "  %r0 = getelementptr inbounds i8, ptr %b, i64 %j\n";
        $out .= "  %v0 = trunc i64 %e1 to i8\n";
        $out .= "  store i8 %v0, ptr %r0\n";
        $out .= "  %jr1 = add i64 %j, 1\n";
        $out .= "  %r1 = getelementptr inbounds i8, ptr %b, i64 %jr1\n";
        $out .= "  %v1 = trunc i64 %e4 to i8\n";
        $out .= "  store i8 %v1, ptr %r1\n";
        $out .= "  %jr2 = add i64 %j, 2\n";
        $out .= "  %r2 = getelementptr inbounds i8, ptr %b, i64 %jr2\n";
        $out .= "  %v2 = trunc i64 %e7 to i8\n";
        $out .= "  store i8 %v2, ptr %r2\n";
        $out .= "  %jr3 = add i64 %j, 3\n";
        $out .= "  %r3 = getelementptr inbounds i8, ptr %b, i64 %jr3\n";
        $out .= "  %v3 = trunc i64 %e9 to i8\n";
        $out .= "  store i8 %v3, ptr %r3\n";
        $out .= "  %j4 = add i64 %j, 4\n";
        $out .= "  ret i64 %j4\n}\n";

        $out .= $this->jsonDecString();
        $out .= $this->jsonDecKey();
        $out .= $this->jsonDecNumber();
        $out .= $this->jsonDecValue($stdSize, $stdBagOff, $stdDesc);
        return $out;
    }

    /**
     * `@__mir_jd_key(ptr s, i64 n, ptr pp) -> ptr` — an object KEY, interned.
     *
     * Record-shaped payloads repeat their key set once per row: the
     * json_decode benchmark mints 40 000 key strings for FIVE distinct keys.
     * This scans the key run WITHOUT allocating, hashes it (FNV-1a), and on a
     * hit in a fixed 1024-slot direct-mapped table hands back the existing
     * string — no malloc, no memcpy. A miss builds the string and takes the
     * slot, releasing whatever it evicts.
     *
     * The returned reference is always +1, exactly like {@see jsonDecString},
     * so the caller's release protocol is unchanged. The table's own reference
     * keeps an interned key alive after every array holding it is freed, which
     * is what makes the next document's lookup a hit. Interning is deliberately
     * KEYS ONLY — values do not repeat, and would just thrash the table.
     *
     * Only applied to a clean run: an EMPTY key or one carrying a `\` escape
     * falls through to the general string path.
     */
    private function jsonDecKey(): string
    {
        $out  = "\n@__mir_jd_itab = internal global [1024 x ptr] zeroinitializer\n";
        $out .= "\ndefine ptr @__mir_jd_key(ptr %s, i64 %n, ptr %pp) {\n";
        $out .= "entry:\n";
        $out .= "  %ii = alloca i64\n";
        $out .= "  %hh = alloca i64\n";
        $out .= "  %p0 = load i64, ptr %pp\n";
        $out .= "  %inb = icmp slt i64 %p0, %n\n";
        $out .= "  br i1 %inb, label %chk, label %fall\n";
        $out .= "chk:\n";
        $out .= "  %qp = getelementptr inbounds i8, ptr %s, i64 %p0\n";
        $out .= "  %qb = load i8, ptr %qp\n";
        $out .= "  %isq = icmp eq i8 %qb, 34\n";
        $out .= "  br i1 %isq, label %scan0, label %fall\n";
        $out .= "fall:\n";
        $out .= "  %fs = call ptr @__mir_jd_str(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  ret ptr %fs\n";
        $out .= "scan0:\n";
        $out .= "  %st = add i64 %p0, 1\n";
        $out .= "  store i64 %st, ptr %ii\n";
        $out .= "  store i64 -3750763034362895579, ptr %hh\n";   // FNV-1a offset basis
        $out .= "  br label %scan\n";
        $out .= "scan:\n";
        $out .= "  %i = load i64, ptr %ii\n";
        $out .= "  %atend = icmp sge i64 %i, %n\n";
        $out .= "  br i1 %atend, label %fall, label %sb\n";
        $out .= "sb:\n";
        $out .= "  %cp = getelementptr inbounds i8, ptr %s, i64 %i\n";
        $out .= "  %cb = load i8, ptr %cp\n";
        $out .= "  %isq2 = icmp eq i8 %cb, 34\n";
        $out .= "  br i1 %isq2, label %found, label %sb2\n";
        $out .= "sb2:\n";
        $out .= "  %isbs = icmp eq i8 %cb, 92\n";
        // A control (CTRL_CHAR) or non-ASCII byte (UTF-8 validation) is the
        // general string path's to judge.
        $out .= "  %isct = icmp ult i8 %cb, 32\n";
        $out .= "  %ishi = icmp uge i8 %cb, 128\n";
        $out .= "  %kodd0 = or i1 %isbs, %isct\n";
        $out .= "  %kodd = or i1 %kodd0, %ishi\n";
        $out .= "  br i1 %kodd, label %fall, label %hstep\n";
        $out .= "hstep:\n";
        $out .= "  %h = load i64, ptr %hh\n";
        $out .= "  %bz = zext i8 %cb to i64\n";
        $out .= "  %hx = xor i64 %h, %bz\n";
        $out .= "  %hm = mul i64 %hx, 1099511628211\n";
        $out .= "  store i64 %hm, ptr %hh\n";
        $out .= "  %i1 = add i64 %i, 1\n";
        $out .= "  store i64 %i1, ptr %ii\n";
        $out .= "  br label %scan\n";
        $out .= "found:\n";
        $out .= "  %len = sub i64 %i, %st\n";
        $out .= "  %isemp = icmp eq i64 %len, 0\n";
        $out .= "  br i1 %isemp, label %fall, label %look\n";
        $out .= "look:\n";
        $out .= "  %after = add i64 %i, 1\n";
        $out .= "  store i64 %after, ptr %pp\n";
        $out .= "  %hv = load i64, ptr %hh\n";
        $out .= "  %slot = and i64 %hv, 1023\n";
        $out .= "  %sp = getelementptr [1024 x ptr], ptr @__mir_jd_itab, i64 0, i64 %slot\n";
        $out .= "  %occ = load ptr, ptr %sp\n";
        $out .= "  %isn = icmp eq ptr %occ, null\n";
        $out .= "  br i1 %isn, label %miss, label %maybe\n";
        $out .= "maybe:\n";
        $out .= "  %olen = call i64 @__mir_strlen(ptr %occ)\n";
        $out .= "  %same = icmp eq i64 %olen, %len\n";
        $out .= "  br i1 %same, label %cmpb, label %miss\n";
        $out .= "cmpb:\n";
        $out .= "  %rp = getelementptr inbounds i8, ptr %s, i64 %st\n";
        // Keys are short: a byte loop beats libc's memcmp through the PLT.
        $out .= "  br label %cmpl\n";
        $out .= "cmpl:\n";
        $out .= "  %ci = phi i64 [0, %cmpb], [%ci1, %cmpn]\n";
        $out .= "  %cdone = icmp sge i64 %ci, %len\n";
        $out .= "  br i1 %cdone, label %hit, label %cmpc\n";
        $out .= "cmpc:\n";
        $out .= "  %cap = getelementptr inbounds i8, ptr %occ, i64 %ci\n";
        $out .= "  %cav = load i8, ptr %cap\n";
        $out .= "  %cbp = getelementptr inbounds i8, ptr %rp, i64 %ci\n";
        $out .= "  %cbv = load i8, ptr %cbp\n";
        $out .= "  %ceq = icmp eq i8 %cav, %cbv\n";
        $out .= "  br i1 %ceq, label %cmpn, label %miss\n";
        $out .= "cmpn:\n";
        $out .= "  %ci1 = add i64 %ci, 1\n";
        $out .= "  br label %cmpl\n";
        $out .= "hit:\n";
        $out .= "  call void @__mir_rc_retain_str(ptr %occ)\n";
        $out .= "  ret ptr %occ\n";
        $out .= "miss:\n";
        $out .= "  %rp2 = getelementptr inbounds i8, ptr %s, i64 %st\n";
        $out .= "  %ns = call ptr @__mir_str_new(ptr %rp2, i64 %len)\n";
        $out .= "  %old = load ptr, ptr %sp\n";
        $out .= "  %hasold = icmp ne ptr %old, null\n";
        $out .= "  br i1 %hasold, label %evict, label %take\n";
        $out .= "evict:\n";
        $out .= "  call void @__mir_rc_release_str(ptr %old)\n";
        $out .= "  br label %take\n";
        $out .= "take:\n";
        $out .= "  call void @__mir_rc_retain_str(ptr %ns)\n";
        $out .= "  store ptr %ns, ptr %sp\n";
        $out .= "  ret ptr %ns\n}\n";
        return $out;
    }

    /**
     * `@__mir_jd_str(ptr s, i64 n, ptr pp) -> ptr` — one JSON string literal.
     *
     * Scans to the first `"` or `\`. The overwhelmingly common case is a run
     * with no escape at all, and it returns `__mir_str_new` over that run — one
     * allocation, one memcpy, no intermediate. Only a literal that really
     * carries a `\` takes the second path, which sizes a buffer at the
     * remaining input (an escape never expands: 6 input bytes for `\uXXXX`
     * produce at most 4 output bytes) and decodes in place. A high surrogate
     * followed by its low pair combines into one 4-byte sequence, as php's own
     * decoder does; an unpaired one passes through as its own codepoint.
     */
    private function jsonDecString(): string
    {
        $out  = "\ndefine ptr @__mir_jd_str(ptr %s, i64 %n, ptr %pp) {\n";
        $out .= "entry:\n";
        $out .= "  %jj = alloca i64\n";
        $out .= "  %kk = alloca i64\n";
        $out .= "  %p0 = load i64, ptr %pp\n";
        $out .= "  %oob = icmp sge i64 %p0, %n\n";
        $out .= "  br i1 %oob, label %empty, label %chkq\n";
        $out .= "chkq:\n";
        $out .= "  %qp = getelementptr inbounds i8, ptr %s, i64 %p0\n";
        $out .= "  %qb = load i8, ptr %qp\n";
        $out .= "  %isq = icmp eq i8 %qb, 34\n";
        $out .= "  br i1 %isq, label %scan0, label %empty\n";
        $out .= "empty:\n";
        $out .= "  %es = call ptr @__mir_str_new(ptr %s, i64 0)\n";
        $out .= "  ret ptr %es\n";
        // ── scan for the terminator or the first escape ──
        $out .= "scan0:\n";
        $out .= "  %st = add i64 %p0, 1\n";
        $out .= "  store i64 %st, ptr %kk\n";
        $out .= "  br label %scan\n";
        $out .= "scan:\n";
        $out .= "  %i = load i64, ptr %kk\n";
        $out .= "  %iend = icmp sge i64 %i, %n\n";
        // Input ending before the closing quote. php answers CTRL_CHAR here, not
        // SYNTAX — its scanner reports the byte class it ran out on.
        $out .= "  br i1 %iend, label %unterm, label %scanb\n";
        $out .= "unterm:\n";
        $out .= "  %unte = call i64 @manticore___mc_json_err(i64 3)\n";
        $out .= "  br label %plain\n";
        $out .= "scanb:\n";
        $out .= "  %sp = getelementptr inbounds i8, ptr %s, i64 %i\n";
        $out .= "  %sb = load i8, ptr %sp\n";
        $out .= "  %isq2 = icmp eq i8 %sb, 34\n";
        $out .= "  br i1 %isq2, label %plain, label %scanbs\n";
        $out .= "scanbs:\n";
        $out .= "  %isbs = icmp eq i8 %sb, 92\n";
        $out .= "  br i1 %isbs, label %slow, label %scanctl\n";
        // A raw C0 byte inside a string literal is invalid JSON; php rejects it
        // with CTRL_CHAR. `ult` on the i8 is right for the whole byte range —
        // 0x80.. read as large unsigned, not as negative. The run still carries
        // the byte, so this reports rather than repairs.
        $out .= "scanctl:\n";
        $out .= "  %isctl = icmp ult i8 %sb, 32\n";
        $out .= "  br i1 %isctl, label %ctlerr, label %scanhi\n";
        // Non-ASCII: a valid sequence is skipped whole; an invalid byte is
        // JSON_ERROR_UTF8, unless an INVALID_UTF8 flag asks for it to be
        // dropped or replaced — which only the copying path can do.
        $out .= "scanhi:\n";
        $out .= "  %sbhi = icmp uge i8 %sb, 128\n";
        $out .= "  br i1 %sbhi, label %scanu8, label %scannext\n";
        $out .= "scanu8:\n";
        $out .= "  %su = call i64 @__mir_json_u8(ptr %s, i64 %i, i64 %n)\n";
        $out .= "  %subad = icmp slt i64 %su, 0\n";
        $out .= "  br i1 %subad, label %scanbad, label %scanok\n";
        $out .= "scanok:\n";
        $out .= "  %sul = and i64 %su, 7\n";
        $out .= "  %siu = add i64 %i, %sul\n";
        $out .= "  store i64 %siu, ptr %kk\n";
        $out .= "  br label %scan\n";
        $out .= "scanbad:\n";
        $out .= "  %sbfl = load i64, ptr @__mir_jd_flags\n";
        $out .= "  %sbrp = and i64 %sbfl, 3145728\n";
        $out .= "  %sbfix = icmp ne i64 %sbrp, 0\n";
        $out .= "  br i1 %sbfix, label %slow, label %u8err\n";
        $out .= "u8err:\n";
        $out .= "  %u8e = call i64 @manticore___mc_json_err(i64 5)\n";
        $out .= "  br label %scannext\n";
        $out .= "ctlerr:\n";
        $out .= "  %ctle = call i64 @manticore___mc_json_err(i64 3)\n";
        $out .= "  br label %scannext\n";
        $out .= "scannext:\n";
        $out .= "  %i1 = add i64 %i, 1\n";
        $out .= "  store i64 %i1, ptr %kk\n";
        $out .= "  br label %scan\n";
        // ── no escape: the run IS the string ──
        $out .= "plain:\n";
        $out .= "  %pi = load i64, ptr %kk\n";
        $out .= "  %rl = sub i64 %pi, %st\n";
        $out .= "  %rp = getelementptr inbounds i8, ptr %s, i64 %st\n";
        $out .= "  %ns = call ptr @__mir_str_new(ptr %rp, i64 %rl)\n";
        $out .= "  %past = icmp sge i64 %pi, %n\n";
        $out .= "  %pi1 = add i64 %pi, 1\n";
        $out .= "  %npos = select i1 %past, i64 %pi, i64 %pi1\n";
        $out .= "  store i64 %npos, ptr %pp\n";
        $out .= "  ret ptr %ns\n";
        // ── escaped: decode into a fresh buffer ──
        // Size the buffer at THIS literal, not at the rest of the document: one
        // bounded pre-scan to the closing quote (an escape consumes 2 input
        // bytes, so it is skipped as a unit). Sizing it at `n - st` cost
        // json_decode_records 1.1 GB — 5000 escaped strings per decode, each
        // reserving the whole remaining 750 KB payload.
        $out .= "slow:\n";
        $out .= "  %si = load i64, ptr %kk\n";
        $out .= "  store i64 %si, ptr %jj\n";
        $out .= "  br label %qscan\n";
        $out .= "qscan:\n";
        $out .= "  %qi = load i64, ptr %jj\n";
        $out .= "  %qend = icmp sge i64 %qi, %n\n";
        $out .= "  br i1 %qend, label %qdone, label %qbody\n";
        $out .= "qbody:\n";
        $out .= "  %qcp = getelementptr inbounds i8, ptr %s, i64 %qi\n";
        $out .= "  %qcb = load i8, ptr %qcp\n";
        $out .= "  %qisq = icmp eq i8 %qcb, 34\n";
        $out .= "  br i1 %qisq, label %qdone, label %qnext\n";
        $out .= "qnext:\n";
        $out .= "  %qisb = icmp eq i8 %qcb, 92\n";
        $out .= "  %qstep = select i1 %qisb, i64 2, i64 1\n";
        $out .= "  %qi2 = add i64 %qi, %qstep\n";
        $out .= "  store i64 %qi2, ptr %jj\n";
        $out .= "  br label %qscan\n";
        $out .= "qdone:\n";
        $out .= "  %qi3 = load i64, ptr %jj\n";
        $out .= "  %qcl = icmp sgt i64 %qi3, %n\n";
        $out .= "  %qlim = select i1 %qcl, i64 %n, i64 %qi3\n";
        $out .= "  %cap = sub i64 %qlim, %st\n";
        // INVALID_UTF8_SUBSTITUTE writes U+FFFD — 3 bytes — for one input byte.
        $out .= "  %cfl = load i64, ptr @__mir_jd_flags\n";
        $out .= "  %csub0 = and i64 %cfl, 2097152\n";
        $out .= "  %csub = icmp ne i64 %csub0, 0\n";
        $out .= "  %cap3 = mul i64 %cap, 3\n";
        $out .= "  %capx = select i1 %csub, i64 %cap3, i64 %cap\n";
        $out .= "  %cap1 = add i64 %capx, 1\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %cap1)\n";
        $out .= "  %pl = sub i64 %si, %st\n";
        $out .= "  %pfx = getelementptr inbounds i8, ptr %s, i64 %st\n";
        $out .= "  call ptr @memcpy(ptr %buf, ptr %pfx, i64 %pl)\n";
        $out .= "  store i64 %pl, ptr %jj\n";
        $out .= "  br label %eloop\n";
        $out .= "eloop:\n";
        $out .= "  %k = load i64, ptr %kk\n";
        $out .= "  %kend = icmp sge i64 %k, %n\n";
        $out .= "  br i1 %kend, label %efin, label %ebody\n";
        $out .= "ebody:\n";
        $out .= "  %cq = getelementptr inbounds i8, ptr %s, i64 %k\n";
        $out .= "  %cb = load i8, ptr %cq\n";
        $out .= "  %isq3 = icmp eq i8 %cb, 34\n";
        $out .= "  br i1 %isq3, label %eclose, label %echk\n";
        $out .= "eclose:\n";
        $out .= "  %kq = add i64 %k, 1\n";
        $out .= "  store i64 %kq, ptr %kk\n";
        $out .= "  br label %efin\n";
        $out .= "echk:\n";
        $out .= "  %isbs2 = icmp eq i8 %cb, 92\n";
        $out .= "  br i1 %isbs2, label %eesc, label %ecopy\n";
        $out .= "ecopy:\n";
        $out .= "  %ectl = icmp ult i8 %cb, 32\n";
        $out .= "  br i1 %ectl, label %ectlerr, label %ecopyh\n";
        $out .= "ectlerr:\n";
        $out .= "  %ectle = call i64 @manticore___mc_json_err(i64 3)\n";
        $out .= "  br label %ecopy1\n";
        $out .= "ecopyh:\n";
        $out .= "  %ehi = icmp uge i8 %cb, 128\n";
        $out .= "  br i1 %ehi, label %eu8, label %ecopy1\n";
        $out .= "eu8:\n";
        $out .= "  %eu8r = call i64 @__mir_json_u8(ptr %s, i64 %k, i64 %n)\n";
        $out .= "  %eubd = icmp slt i64 %eu8r, 0\n";
        $out .= "  br i1 %eubd, label %eu8bad, label %eu8ok\n";
        $out .= "eu8ok:\n";
        $out .= "  %eul = and i64 %eu8r, 7\n";
        $out .= "  %euj = load i64, ptr %jj\n";
        $out .= "  %eud = getelementptr inbounds i8, ptr %buf, i64 %euj\n";
        $out .= "  call ptr @memcpy(ptr %eud, ptr %cq, i64 %eul)\n";
        $out .= "  %euj2 = add i64 %euj, %eul\n";
        $out .= "  store i64 %euj2, ptr %jj\n";
        $out .= "  %euk = add i64 %k, %eul\n";
        $out .= "  store i64 %euk, ptr %kk\n";
        $out .= "  br label %eloop\n";
        // One invalid BYTE at a time, as php's scanner reads it.
        $out .= "eu8bad:\n";
        $out .= "  %ebfl = load i64, ptr @__mir_jd_flags\n";
        $out .= "  %ebk = add i64 %k, 1\n";
        $out .= "  store i64 %ebk, ptr %kk\n";
        $out .= "  %ebig0 = and i64 %ebfl, 1048576\n";
        $out .= "  %ebig = icmp ne i64 %ebig0, 0\n";
        $out .= "  br i1 %ebig, label %eloop, label %eu8b2\n";
        $out .= "eu8b2:\n";
        $out .= "  %ebsb0 = and i64 %ebfl, 2097152\n";
        $out .= "  %ebsb = icmp ne i64 %ebsb0, 0\n";
        $out .= "  br i1 %ebsb, label %eu8sub, label %eu8err\n";
        $out .= "eu8sub:\n";
        $out .= "  %esj = load i64, ptr %jj\n";
        $out .= "  %esd = getelementptr inbounds i8, ptr %buf, i64 %esj\n";
        $out .= "  call ptr @memcpy(ptr %esd, ptr @.jdkw.repl, i64 3)\n";
        $out .= "  %esj3 = add i64 %esj, 3\n";
        $out .= "  store i64 %esj3, ptr %jj\n";
        $out .= "  br label %eloop\n";
        $out .= "eu8err:\n";
        $out .= "  %eu8e = call i64 @manticore___mc_json_err(i64 5)\n";
        $out .= "  br label %eloop\n";
        $out .= "ecopy1:\n";
        $out .= "  %j = load i64, ptr %jj\n";
        $out .= "  %dp = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 %cb, ptr %dp\n";
        $out .= "  %j1 = add i64 %j, 1\n";
        $out .= "  store i64 %j1, ptr %jj\n";
        $out .= "  %kc = add i64 %k, 1\n";
        $out .= "  store i64 %kc, ptr %kk\n";
        $out .= "  br label %eloop\n";
        $out .= "eesc:\n";
        $out .= "  %ke = add i64 %k, 1\n";
        $out .= "  %keend = icmp sge i64 %ke, %n\n";
        $out .= "  br i1 %keend, label %efin, label %eesc2\n";
        $out .= "eesc2:\n";
        $out .= "  %ep = getelementptr inbounds i8, ptr %s, i64 %ke\n";
        $out .= "  %eb = load i8, ptr %ep\n";
        $out .= "  %isu = icmp eq i8 %eb, 117\n";
        $out .= "  br i1 %isu, label %eu, label %esimple\n";
        $out .= "esimple:\n";
        $out .= "  %ez = zext i8 %eb to i64\n";
        $out .= "  %mn = icmp eq i64 %ez, 110\n";
        $out .= "  %mt = icmp eq i64 %ez, 116\n";
        $out .= "  %mr = icmp eq i64 %ez, 114\n";
        $out .= "  %mb = icmp eq i64 %ez, 98\n";
        $out .= "  %mf = icmp eq i64 %ez, 102\n";
        $out .= "  %s0 = select i1 %mn, i64 10, i64 %ez\n";
        $out .= "  %s1 = select i1 %mt, i64 9, i64 %s0\n";
        $out .= "  %s2 = select i1 %mr, i64 13, i64 %s1\n";
        $out .= "  %s3 = select i1 %mb, i64 8, i64 %s2\n";
        $out .= "  %s4 = select i1 %mf, i64 12, i64 %s3\n";
        // Only `\" \\ \/ \b \f \n \r \t` (and `\u`): anything else is SYNTAX.
        $out .= "  %mq = icmp eq i64 %ez, 34\n";
        $out .= "  %mbs = icmp eq i64 %ez, 92\n";
        $out .= "  %msl = icmp eq i64 %ez, 47\n";
        $out .= "  %mv1 = or i1 %mn, %mt\n";
        $out .= "  %mv2 = or i1 %mr, %mb\n";
        $out .= "  %mv3 = or i1 %mf, %mq\n";
        $out .= "  %mv4 = or i1 %mbs, %msl\n";
        $out .= "  %mv5 = or i1 %mv1, %mv2\n";
        $out .= "  %mv6 = or i1 %mv3, %mv4\n";
        $out .= "  %mvalid = or i1 %mv5, %mv6\n";
        $out .= "  br i1 %mvalid, label %esimple2, label %ebadesc\n";
        $out .= "ebadesc:\n";
        $out .= "  %ebe = call i64 @manticore___mc_json_err(i64 4)\n";
        $out .= "  br label %esimple2\n";
        $out .= "esimple2:\n";
        $out .= "  %sj = load i64, ptr %jj\n";
        $out .= "  %sd = getelementptr inbounds i8, ptr %buf, i64 %sj\n";
        $out .= "  %s8 = trunc i64 %s4 to i8\n";
        $out .= "  store i8 %s8, ptr %sd\n";
        $out .= "  %sj1 = add i64 %sj, 1\n";
        $out .= "  store i64 %sj1, ptr %jj\n";
        $out .= "  %ks = add i64 %ke, 1\n";
        $out .= "  store i64 %ks, ptr %kk\n";
        $out .= "  br label %eloop\n";
        $out .= "eu:\n";
        $out .= "  %hs = add i64 %ke, 1\n";
        $out .= "  %cp0 = call i64 @__mir_jd_hex4(ptr %s, i64 %hs, i64 %n)\n";
        $out .= "  %hbad = icmp slt i64 %cp0, 0\n";
        $out .= "  br i1 %hbad, label %eubad, label %eok\n";
        $out .= "eubad:\n";                                   // not 4 hex digits: SYNTAX
        $out .= "  %eube = call i64 @manticore___mc_json_err(i64 4)\n";
        $out .= "  %kb = add i64 %ke, 1\n";
        $out .= "  store i64 %kb, ptr %kk\n";
        $out .= "  br label %eloop\n";
        $out .= "eok:\n";
        $out .= "  %kh = add i64 %hs, 4\n";
        $out .= "  %hi1 = icmp uge i64 %cp0, 55296\n";        // 0xD800
        $out .= "  %hi2 = icmp ule i64 %cp0, 56319\n";        // 0xDBFF
        $out .= "  %ishi = and i1 %hi1, %hi2\n";
        $out .= "  br i1 %ishi, label %esur, label %elochk\n";
        // A lone LOW surrogate, or a high one not followed by its low pair, is
        // php's JSON_ERROR_UTF16.
        $out .= "elochk:\n";
        $out .= "  %lw1 = icmp uge i64 %cp0, 56320\n";
        $out .= "  %lw2 = icmp ule i64 %cp0, 57343\n";
        $out .= "  %islw = and i1 %lw1, %lw2\n";
        $out .= "  br i1 %islw, label %eu16, label %ewrite\n";
        $out .= "eu16:\n";
        $out .= "  %eu16e = call i64 @manticore___mc_json_err(i64 10)\n";
        $out .= "  br label %ewrite\n";
        $out .= "esur:\n";
        $out .= "  %need = add i64 %kh, 6\n";
        $out .= "  %sfits = icmp sle i64 %need, %n\n";
        $out .= "  br i1 %sfits, label %esur2, label %eu16\n";
        $out .= "esur2:\n";
        $out .= "  %bsp = getelementptr inbounds i8, ptr %s, i64 %kh\n";
        $out .= "  %bsb = load i8, ptr %bsp\n";
        $out .= "  %isbs3 = icmp eq i8 %bsb, 92\n";
        $out .= "  %kh1 = add i64 %kh, 1\n";
        $out .= "  %usp = getelementptr inbounds i8, ptr %s, i64 %kh1\n";
        $out .= "  %usb = load i8, ptr %usp\n";
        $out .= "  %isu2 = icmp eq i8 %usb, 117\n";
        $out .= "  %pair = and i1 %isbs3, %isu2\n";
        $out .= "  br i1 %pair, label %esur3, label %eu16\n";
        $out .= "esur3:\n";
        $out .= "  %kh2 = add i64 %kh, 2\n";
        $out .= "  %lo = call i64 @__mir_jd_hex4(ptr %s, i64 %kh2, i64 %n)\n";
        $out .= "  %lo1 = icmp uge i64 %lo, 56320\n";         // 0xDC00
        $out .= "  %lo2 = icmp ule i64 %lo, 57343\n";         // 0xDFFF
        $out .= "  %islo = and i1 %lo1, %lo2\n";
        $out .= "  %hb = sub i64 %cp0, 55296\n";
        $out .= "  %hb10 = shl i64 %hb, 10\n";
        $out .= "  %lb = sub i64 %lo, 56320\n";
        $out .= "  %comb0 = add i64 %hb10, %lb\n";
        $out .= "  %comb = add i64 %comb0, 65536\n";
        $out .= "  %cpp = select i1 %islo, i64 %comb, i64 %cp0\n";
        $out .= "  %khp = select i1 %islo, i64 %need, i64 %kh\n";
        $out .= "  br i1 %islo, label %ewrite, label %eu16\n";
        $out .= "ewrite:\n";
        $out .= "  %cpf = phi i64 [%cp0, %elochk], [%cp0, %eu16], [%cpp, %esur3]\n";
        $out .= "  %kf = phi i64 [%kh, %elochk], [%kh, %eu16], [%khp, %esur3]\n";
        $out .= "  %wj = load i64, ptr %jj\n";
        $out .= "  %wj2 = call i64 @__mir_jd_utf8(ptr %buf, i64 %wj, i64 %cpf)\n";
        $out .= "  store i64 %wj2, ptr %jj\n";
        $out .= "  store i64 %kf, ptr %kk\n";
        $out .= "  br label %eloop\n";
        $out .= "efin:\n";
        $out .= "  %fk = load i64, ptr %kk\n";
        $out .= "  %fkc = icmp sgt i64 %fk, %n\n";
        $out .= "  %fkp = select i1 %fkc, i64 %n, i64 %fk\n";
        $out .= "  store i64 %fkp, ptr %pp\n";
        $out .= "  %fj = load i64, ptr %jj\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %fj)\n";
        $out .= "  %fnp = getelementptr inbounds i8, ptr %buf, i64 %fj\n";
        $out .= "  store i8 0, ptr %fnp\n";
        $out .= "  ret ptr %buf\n}\n";
        return $out;
    }

    /**
     * `@__mir_jd_num(ptr s, i64 n, ptr pp) -> i64` — one JSON number, boxed.
     *
     * The grammar is JSON's, strictly: `-? (0 | [1-9][0-9]*) (. [0-9]+)?
     * ([eE] [+-]? [0-9]+)?` — `01`, `1.`, `.5`, `1e`, `+1` and a bare `-` are
     * JSON_ERROR_SYNTAX (the position is left where the token began). The
     * integer accumulates straight out of the digit scan; only a token with a
     * fraction or exponent, or one that OVERFLOWS, materializes its bytes for
     * `strtod` (a stack buffer — no heap). Overflow is checked unsigned against
     * the exact bound, so `-9223372036854775808` stays an int and one more digit
     * is a float — or, under JSON_BIGINT_AS_STRING, the token's own text.
     */
    private function jsonDecNumber(): string
    {
        return '
define i64 @__mir_jd_num(ptr %s, i64 %n, ptr %pp) {
entry:
  %tok = alloca [80 x i8]
  %ii = alloca i64
  %acc = alloca i64
  %ovf = alloca i64
  %flt = alloca i64
  %fm = alloca i64
  %fk = alloca i64
  %fslow = alloca i64
  %st = load i64, ptr %pp
  store i64 0, ptr %acc
  store i64 0, ptr %ovf
  store i64 0, ptr %flt
  store i64 0, ptr %fk
  store i64 0, ptr %fslow
  %c0 = call i64 @__mir_json_bat(ptr %s, i64 %st, i64 %n)
  %neg = icmp eq i64 %c0, 45
  %st1 = add i64 %st, 1
  %i0 = select i1 %neg, i64 %st1, i64 %st
  %d0 = call i64 @__mir_json_bat(ptr %s, i64 %i0, i64 %n)
  %isz = icmp eq i64 %d0, 48
  br i1 %isz, label %zero, label %nz
zero:
  %iz = add i64 %i0, 1
  store i64 %iz, ptr %ii
  br label %intdone
nz:
  %d0m = sub i64 %d0, 49
  %d0ok = icmp ult i64 %d0m, 9
  store i64 %i0, ptr %ii
  br i1 %d0ok, label %dl, label %bad
dl:
  %i = load i64, ptr %ii
  %b = call i64 @__mir_json_bat(ptr %s, i64 %i, i64 %n)
  %dg = sub i64 %b, 48
  %isd = icmp ult i64 %dg, 10
  br i1 %isd, label %dstep, label %intdone
dstep:
  %a = load i64, ptr %acc
  %bound = select i1 %neg, i64 -9223372036854775808, i64 9223372036854775807
  %q = udiv i64 %bound, 10
  %rr = urem i64 %bound, 10
  %tooBig = icmp ugt i64 %a, %q
  %atQ = icmp eq i64 %a, %q
  %dgHi = icmp ugt i64 %dg, %rr
  %edge = and i1 %atQ, %dgHi
  %over = or i1 %tooBig, %edge
  %ovOld = load i64, ptr %ovf
  %ovNew = select i1 %over, i64 1, i64 %ovOld
  store i64 %ovNew, ptr %ovf
  %a10 = mul i64 %a, 10
  %a2 = add i64 %a10, %dg
  store i64 %a2, ptr %acc
  %i1 = add i64 %i, 1
  store i64 %i1, ptr %ii
  br label %dl
intdone:
  %ie = load i64, ptr %ii
  %be = call i64 @__mir_json_bat(ptr %s, i64 %ie, i64 %n)
  %isdot = icmp eq i64 %be, 46
  br i1 %isdot, label %frac, label %expchk
frac:
  store i64 1, ptr %flt
  %fa0 = load i64, ptr %acc
  store i64 %fa0, ptr %fm
  %fov0 = load i64, ptr %ovf
  %fbig0 = icmp ugt i64 %fa0, 9007199254740992
  %fov1 = icmp ne i64 %fov0, 0
  %fs0 = or i1 %fbig0, %fov1
  %fs0z = zext i1 %fs0 to i64
  store i64 %fs0z, ptr %fslow
  %if1 = add i64 %ie, 1
  store i64 %if1, ptr %ii
  %bf = call i64 @__mir_json_bat(ptr %s, i64 %if1, i64 %n)
  %bfd = sub i64 %bf, 48
  %bfok = icmp ult i64 %bfd, 10
  br i1 %bfok, label %fl, label %bad
fl:
  %fi = load i64, ptr %ii
  %fb = call i64 @__mir_json_bat(ptr %s, i64 %fi, i64 %n)
  %fbd = sub i64 %fb, 48
  %fbok = icmp ult i64 %fbd, 10
  br i1 %fbok, label %flstep, label %expchk
flstep:
  %fi1 = add i64 %fi, 1
  store i64 %fi1, ptr %ii
  %fmv = load i64, ptr %fm
  %fm10 = mul i64 %fmv, 10
  %fm2 = add i64 %fm10, %fbd
  store i64 %fm2, ptr %fm
  %fkv = load i64, ptr %fk
  %fk1 = add i64 %fkv, 1
  store i64 %fk1, ptr %fk
  %fmbig = icmp ugt i64 %fm2, 9007199254740992
  %fkbig = icmp ugt i64 %fk1, 22
  %fsl = or i1 %fmbig, %fkbig
  %fslo = load i64, ptr %fslow
  %fslz = zext i1 %fsl to i64
  %fsln = or i64 %fslo, %fslz
  store i64 %fsln, ptr %fslow
  br label %fl
expchk:
  %xe = load i64, ptr %ii
  %xb = call i64 @__mir_json_bat(ptr %s, i64 %xe, i64 %n)
  %xl = or i64 %xb, 32
  %isx = icmp eq i64 %xl, 101
  br i1 %isx, label %exp, label %fin
exp:
  store i64 1, ptr %flt
  store i64 1, ptr %fslow
  %xe1 = add i64 %xe, 1
  %xs = call i64 @__mir_json_bat(ptr %s, i64 %xe1, i64 %n)
  %xsp = icmp eq i64 %xs, 43
  %xsm = icmp eq i64 %xs, 45
  %xsg = or i1 %xsp, %xsm
  %xe2 = add i64 %xe1, 1
  %xd0 = select i1 %xsg, i64 %xe2, i64 %xe1
  store i64 %xd0, ptr %ii
  %xdb = call i64 @__mir_json_bat(ptr %s, i64 %xd0, i64 %n)
  %xdd = sub i64 %xdb, 48
  %xdok = icmp ult i64 %xdd, 10
  br i1 %xdok, label %xl0, label %bad
xl0:
  %xi = load i64, ptr %ii
  %xb2 = call i64 @__mir_json_bat(ptr %s, i64 %xi, i64 %n)
  %xd2 = sub i64 %xb2, 48
  %xok2 = icmp ult i64 %xd2, 10
  br i1 %xok2, label %xstep, label %fin
xstep:
  %xi1 = add i64 %xi, 1
  store i64 %xi1, ptr %ii
  br label %xl0
bad:
  %be4 = call i64 @manticore___mc_json_err(i64 4)
  store i64 %st, ptr %pp
  %bn = call i64 @__manticore_box_null()
  ret i64 %bn
fin:
  %e = load i64, ptr %ii
  store i64 %e, ptr %pp
  %fl0 = load i64, ptr %flt
  %isf = icmp ne i64 %fl0, 0
  br i1 %isf, label %asdouble, label %intk
intk:
  %ov = load i64, ptr %ovf
  %isov = icmp ne i64 %ov, 0
  br i1 %isov, label %big, label %asint
big:
  %bfl = load i64, ptr @__mir_jd_flags
  %bas0 = and i64 %bfl, 2
  %bas = icmp ne i64 %bas0, 0
  br i1 %bas, label %bigstr, label %asdouble
bigstr:
  %btl = sub i64 %e, %st
  %bsp = getelementptr inbounds i8, ptr %s, i64 %st
  %bstr = call ptr @__mir_str_new(ptr %bsp, i64 %btl)
  %bsc = call i64 @__manticore_box_ptr(ptr %bstr)
  ret i64 %bsc
asdouble:
  %qk = load i64, ptr %fk
  %qsl = load i64, ptr %fslow
  %qfr = icmp ne i64 %qk, 0
  %qok0 = icmp eq i64 %qsl, 0
  %qok = and i1 %qfr, %qok0
  br i1 %qok, label %clinger, label %slowd
clinger:
  %qm = load i64, ptr %fm
  %qmf = uitofp i64 %qm to double
  %qpp = getelementptr [23 x double], ptr @__mir_jd_p10, i64 0, i64 %qk
  %qp = load double, ptr %qpp
  %qd = fdiv double %qmf, %qp
  %qn = fneg double %qd
  %qv = select i1 %neg, double %qn, double %qd
  %qbx = call i64 @__manticore_box_float(double %qv)
  ret i64 %qbx
slowd:
  %tl0 = sub i64 %e, %st
  %tbig = icmp sgt i64 %tl0, 79
  %tl = select i1 %tbig, i64 79, i64 %tl0
  %src = getelementptr inbounds i8, ptr %s, i64 %st
  call ptr @memcpy(ptr %tok, ptr %src, i64 %tl)
  %tz = getelementptr inbounds i8, ptr %tok, i64 %tl
  store i8 0, ptr %tz
  %dv = call double @strtod(ptr %tok, ptr null)
  %bx = call i64 @__manticore_box_float(double %dv)
  ret i64 %bx
asint:
  %av = load i64, ptr %acc
  %nv = sub i64 0, %av
  %iv = select i1 %neg, i64 %nv, i64 %av
  %bi = call i64 @__manticore_box_int(i64 %iv)
  ret i64 %bi
}
';
    }

    /**
     * `@__mir_json_dec(ptr s, i64 n, ptr pp) -> i64` — one value, boxed, with
     * `*pp` advanced past it. Containers are built as CELL arrays directly
     * (BOTH the repr nibble — what a release drops — and the element-hint
     * nibble — what a reader decodes by — stamped CELL at
     * {@see \Compile\MemoryAbi::ARRAY_FLAGS_OFFSET}; a repr-only stamp left the
     * buffer hint 0, so the by-hint readers took its NaN-boxed words for raw
     * ones) so no consumer has to rebuild them, and both start at capacity 8
     * instead of growing from zero. An object's key is handed to `__mir_array_set_str`,
     * which takes its own reference, so the parser drops the one it minted.
     */
    private function jsonDecValue(int $stdSize, int $stdBagOff, string $stdDesc): string
    {
        $cellRepr = (string)(\Compile\MemoryAbi::ARRAY_REPR_CELL | \Compile\MemoryAbi::ARRAY_ELEM_HINT_CELL);
        $notRepr  = (string)(~(\Compile\MemoryAbi::ARRAY_REPR_MASK | \Compile\MemoryAbi::ARRAY_ELEM_HINT_MASK));
        $flagsOff = (string)\Compile\MemoryAbi::ARRAY_FLAGS_OFFSET;

        $stamp = static function (string $tag, string $arr) use ($cellRepr, $notRepr, $flagsOff): string {
            $o  = "  %fp$tag = getelementptr inbounds i8, ptr $arr, i64 $flagsOff\n";
            $o .= "  %fv$tag = load i64, ptr %fp$tag\n";
            $o .= "  %fc$tag = and i64 %fv$tag, $notRepr\n";
            $o .= "  %fs$tag = or i64 %fc$tag, $cellRepr\n";
            $o .= "  store i64 %fs$tag, ptr %fp$tag\n";
            return $o;
        };

        $out  = "\ndefine i64 @__mir_json_deca(ptr %s, i64 %n, ptr %pp, i64 %assoc) {\n";
        $out .= "entry:\n";
        $out .= "  %ap = alloca ptr\n";
        $out .= "  %ikv = alloca i64\n";
        $out .= "  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %p = load i64, ptr %pp\n";
        $out .= "  %oob = icmp sge i64 %p, %n\n";
        $out .= "  br i1 %oob, label %retnull, label %disp\n";
        $out .= "retnull:\n";
        $out .= "  %nz = call i64 @__manticore_box_null()\n";
        $out .= "  ret i64 %nz\n";
        $out .= "disp:\n";
        $out .= "  %cp = getelementptr inbounds i8, ptr %s, i64 %p\n";
        $out .= "  %cb = load i8, ptr %cp\n";
        $out .= "  %isob = icmp eq i8 %cb, 123\n";            // {
        $out .= "  br i1 %isob, label %obj, label %d2\n";
        $out .= "d2:\n";
        $out .= "  %isar = icmp eq i8 %cb, 91\n";             // [
        $out .= "  br i1 %isar, label %arr, label %d3\n";
        $out .= "d3:\n";
        $out .= "  %isst = icmp eq i8 %cb, 34\n";             // "
        $out .= "  br i1 %isst, label %str, label %d4\n";
        $out .= "d4:\n";
        $out .= "  %ist = icmp eq i8 %cb, 116\n";             // t
        $out .= "  br i1 %ist, label %kwt, label %d5\n";
        $out .= "d5:\n";
        $out .= "  %isf = icmp eq i8 %cb, 102\n";             // f
        $out .= "  br i1 %isf, label %kwf, label %d6\n";
        $out .= "d6:\n";
        $out .= "  %isn = icmp eq i8 %cb, 110\n";             // n
        $out .= "  br i1 %isn, label %kwn, label %num\n";
        $out .= "str:\n";
        $out .= "  %sv = call ptr @__mir_jd_str(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %sc = call i64 @__manticore_box_ptr(ptr %sv)\n";
        $out .= "  ret i64 %sc\n";
        $out .= "kwt:\n";
        $out .= "  %ptok = call i1 @__mir_jd_kw(ptr %s, i64 %n, i64 %p, ptr @.jdkw.true, i64 4)\n";
        $out .= "  br i1 %ptok, label %kwtok, label %kwbad\n";
        $out .= "kwtok:\n";
        $out .= "  %pt = add i64 %p, 4\n";
        $out .= "  store i64 %pt, ptr %pp\n";
        $out .= "  %tv = call i64 @__manticore_box_bool(i64 1)\n";
        $out .= "  ret i64 %tv\n";
        $out .= "kwf:\n";
        $out .= "  %pfok = call i1 @__mir_jd_kw(ptr %s, i64 %n, i64 %p, ptr @.jdkw.false, i64 5)\n";
        $out .= "  br i1 %pfok, label %kwfok, label %kwbad\n";
        $out .= "kwfok:\n";
        $out .= "  %pf = add i64 %p, 5\n";
        $out .= "  store i64 %pf, ptr %pp\n";
        $out .= "  %fv = call i64 @__manticore_box_bool(i64 0)\n";
        $out .= "  ret i64 %fv\n";
        $out .= "kwn:\n";
        $out .= "  %pnok = call i1 @__mir_jd_kw(ptr %s, i64 %n, i64 %p, ptr @.jdkw.null, i64 4)\n";
        $out .= "  br i1 %pnok, label %kwnok, label %kwbad\n";
        $out .= "kwnok:\n";
        $out .= "  %pn = add i64 %p, 4\n";
        $out .= "  store i64 %pn, ptr %pp\n";
        $out .= "  %nv = call i64 @__manticore_box_null()\n";
        $out .= "  ret i64 %nv\n";
        // The number scanner is the dispatcher's catch-all: every byte that is
        // not `{ [ " t f n` lands here. On a byte that cannot start a value it
        // consumes NOTHING and answers 0, which is how `[1,]`, `[,]` and
        // `[1,,2]` came to invent elements. Consuming nothing IS the syntax
        // error, and rooting it here covers the whole family at once instead of
        // enumerating bad bytes one at a time.
        $out .= "num:\n";
        $out .= "  %numb4 = load i64, ptr %pp\n";
        $out .= "  %numv = call i64 @__mir_jd_num(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %numaf = load i64, ptr %pp\n";
        $out .= "  %numnil = icmp eq i64 %numaf, %numb4\n";
        $out .= "  br i1 %numnil, label %numerr, label %numok\n";
        $out .= "numerr:\n";
        $out .= "  call void @__mir_jd_bad(ptr %s, i64 %n, ptr %pp, i64 4)\n";
        $out .= "  br label %numok\n";
        // `tru`, `nul`, `trueX`: a keyword that does not spell out. Nothing is
        // consumed, so the enclosing container stops at the same byte.
        $out .= "kwbad:\n";
        $out .= "  %kwe = call i64 @manticore___mc_json_err(i64 4)\n";
        $out .= "  %kwnul = call i64 @__manticore_box_null()\n";
        $out .= "  ret i64 %kwnul\n";
        $out .= "numok:\n";
        $out .= "  ret i64 %numv\n";
        // ── [ … ] ──
        $out .= "arr:\n";
        // Refuse BEFORE recursing — that is the whole point: past this the
        // native decoder used to run to a stack overflow on untrusted input.
        $out .= "  %adep = load i64, ptr @__mir_jd_depth\n";
        // php counts the scalar level too: a container at nesting d needs
        // $depth > d, so `json_decode('[]', true, 1)` is already too deep.
        $out .= "  %amax = load i64, ptr @__mir_jd_max\n";
        $out .= "  %adnx = add i64 %adep, 1\n";
        $out .= "  %adeep = icmp sge i64 %adnx, %amax\n";
        $out .= "  br i1 %adeep, label %adeperr, label %arrgo\n";
        $out .= "adeperr:\n";
        $out .= "  %adepe = call i64 @manticore___mc_json_err(i64 1)\n";
        $out .= "  %adepn = call i64 @__manticore_box_null()\n";
        $out .= "  ret i64 %adepn\n";
        $out .= "arrgo:\n";
        $out .= "  %adep1 = add i64 %adep, 1\n";
        $out .= "  store i64 %adep1, ptr @__mir_jd_depth\n";
        $out .= "  %a0 = call ptr @__mir_array_alloc(i64 8)\n";
        $out .= "  store ptr %a0, ptr %ap\n";
        $out .= "  %ap1 = add i64 %p, 1\n";
        $out .= "  store i64 %ap1, ptr %pp\n";
        $out .= "  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %aq = load i64, ptr %pp\n";
        $out .= "  %aoob = icmp sge i64 %aq, %n\n";
        // Input ending right after `[` — the container never closes.
        $out .= "  br i1 %aoob, label %aerr, label %achk\n";
        $out .= "achk:\n";
        $out .= "  %acp = getelementptr inbounds i8, ptr %s, i64 %aq\n";
        $out .= "  %acb = load i8, ptr %acp\n";
        $out .= "  %aend = icmp eq i8 %acb, 93\n";            // ]
        $out .= "  br i1 %aend, label %aeat, label %aloop\n";
        $out .= "aeat:\n";
        $out .= "  %aq1 = add i64 %aq, 1\n";
        $out .= "  store i64 %aq1, ptr %pp\n";
        $out .= "  br label %adone\n";
        $out .= "aloop:\n";
        $out .= "  %al = load i64, ptr %pp\n";
        $out .= "  %alend = icmp sge i64 %al, %n\n";
        $out .= "  br i1 %alend, label %atail, label %abody\n";
        $out .= "abody:\n";
        $out .= "  %av = call i64 @__mir_json_deca(ptr %s, i64 %n, ptr %pp, i64 %assoc)\n";
        $out .= "  %acur = load ptr, ptr %ap\n";
        $out .= "  %anx = call ptr @__mir_array_append(ptr %acur, i64 %av)\n";
        $out .= "  store ptr %anx, ptr %ap\n";
        $out .= "  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %ac = load i64, ptr %pp\n";
        $out .= "  %acoob = icmp sge i64 %ac, %n\n";
        $out .= "  br i1 %acoob, label %atail, label %acomma\n";
        $out .= "acomma:\n";
        $out .= "  %accp = getelementptr inbounds i8, ptr %s, i64 %ac\n";
        $out .= "  %accb = load i8, ptr %accp\n";
        $out .= "  %isc = icmp eq i8 %accb, 44\n";            // ,
        $out .= "  br i1 %isc, label %anext, label %atail\n";
        $out .= "anext:\n";
        $out .= "  %ac1 = add i64 %ac, 1\n";
        $out .= "  store i64 %ac1, ptr %pp\n";
        // A trailing comma used to send the value dispatcher at `]`, where the
        // number scanner consumed nothing and returned 0 — so `[1,]` decoded as
        // [1,0], INVENTING an element. php: SYNTAX.
        $out .= "  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %atc = load i64, ptr %pp\n";
        $out .= "  %atcoob = icmp sge i64 %atc, %n\n";
        $out .= "  br i1 %atcoob, label %aerr, label %atcchk\n";
        $out .= "atcchk:\n";
        $out .= "  %atcp = getelementptr inbounds i8, ptr %s, i64 %atc\n";
        $out .= "  %atcb = load i8, ptr %atcp\n";
        $out .= "  %atcend = icmp eq i8 %atcb, 93\n";
        $out .= "  br i1 %atcend, label %aerr, label %aloop\n";
        $out .= "atail:\n";
        $out .= "  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %at = load i64, ptr %pp\n";
        $out .= "  %atoob = icmp sge i64 %at, %n\n";
        // Running out of input, or finding a byte that is not `]`, both used to
        // fall into %adone and hand back a container php would have rejected —
        // `[1,2` decoded as [1,2]. Both are php's SYNTAX error.
        $out .= "  br i1 %atoob, label %aerr, label %atchk\n";
        $out .= "atchk:\n";
        $out .= "  %atp = getelementptr inbounds i8, ptr %s, i64 %at\n";
        $out .= "  %atb = load i8, ptr %atp\n";
        $out .= "  %atend = icmp eq i8 %atb, 93\n";
        $out .= "  br i1 %atend, label %ateat, label %amis\n";
        // The wrong closer is php's STATE_MISMATCH, not SYNTAX.
        $out .= "amis:\n";
        $out .= "  %amis1 = icmp eq i8 %atb, 125\n";
        $out .= "  br i1 %amis1, label %amis2, label %aerr\n";
        $out .= "amis2:\n";
        $out .= "  %amise = call i64 @manticore___mc_json_err(i64 2)\n";
        $out .= "  br label %adone\n";
        $out .= "aerr:\n";
        $out .= "  call void @__mir_jd_bad(ptr %s, i64 %n, ptr %pp, i64 4)\n";
        $out .= "  br label %adone\n";
        $out .= "ateat:\n";
        $out .= "  %at1 = add i64 %at, 1\n";
        $out .= "  store i64 %at1, ptr %pp\n";
        $out .= "  br label %adone\n";
        $out .= "adone:\n";
        $out .= "  %adepd = load i64, ptr @__mir_jd_depth\n";
        $out .= "  %adepd1 = sub i64 %adepd, 1\n";
        $out .= "  store i64 %adepd1, ptr @__mir_jd_depth\n";
        $out .= "  %afin = load ptr, ptr %ap\n";
        $out .= $stamp('a', '%afin');
        $out .= "  %abx = call i64 @__manticore_box_array(ptr %afin)\n";
        $out .= "  ret i64 %abx\n";
        // ── { … } ──
        $out .= "obj:\n";
        $out .= "  %odep = load i64, ptr @__mir_jd_depth\n";
        $out .= "  %omax = load i64, ptr @__mir_jd_max\n";
        $out .= "  %odnx = add i64 %odep, 1\n";
        $out .= "  %odeep = icmp sge i64 %odnx, %omax\n";
        $out .= "  br i1 %odeep, label %odeperr, label %objgo\n";
        $out .= "odeperr:\n";
        $out .= "  %odepe = call i64 @manticore___mc_json_err(i64 1)\n";
        $out .= "  %odepn = call i64 @__manticore_box_null()\n";
        $out .= "  ret i64 %odepn\n";
        $out .= "objgo:\n";
        $out .= "  %odep1 = add i64 %odep, 1\n";
        $out .= "  store i64 %odep1, ptr @__mir_jd_depth\n";
        $out .= "  %o0 = call ptr @__mir_array_alloc_hashed(i64 8)\n";
        $out .= "  store ptr %o0, ptr %ap\n";
        $out .= "  %op1 = add i64 %p, 1\n";
        $out .= "  store i64 %op1, ptr %pp\n";
        $out .= "  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %oq = load i64, ptr %pp\n";
        $out .= "  %ooob = icmp sge i64 %oq, %n\n";
        // Input ending right after `{` — the container never closes.
        $out .= "  br i1 %ooob, label %oerr, label %ochk\n";
        $out .= "ochk:\n";
        $out .= "  %ocp = getelementptr inbounds i8, ptr %s, i64 %oq\n";
        $out .= "  %ocb = load i8, ptr %ocp\n";
        $out .= "  %oend = icmp eq i8 %ocb, 125\n";           // }
        $out .= "  br i1 %oend, label %oeat, label %oloop\n";
        $out .= "oeat:\n";
        $out .= "  %oq1 = add i64 %oq, 1\n";
        $out .= "  store i64 %oq1, ptr %pp\n";
        $out .= "  br label %odone\n";
        $out .= "oloop:\n";
        $out .= "  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %ol = load i64, ptr %pp\n";
        $out .= "  %olend = icmp sge i64 %ol, %n\n";
        $out .= "  br i1 %olend, label %otail, label %obody\n";
        $out .= "obody:\n";
        $out .= "  %okey = call ptr @__mir_jd_key(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %oc0 = load i64, ptr %pp\n";
        $out .= "  %oc0ok = icmp slt i64 %oc0, %n\n";
        // A missing `:` — or input that ends before one — was tolerated, so
        // `{"a" 1}` and `{"a"` both decoded as if well formed. php: SYNTAX.
        $out .= "  br i1 %oc0ok, label %ocolon, label %ocbad\n";
        $out .= "ocbad:\n";
        $out .= "  %ocbe = call i64 @manticore___mc_json_err(i64 4)\n";
        $out .= "  br label %oval\n";
        $out .= "ocolon:\n";
        $out .= "  %ocp2 = getelementptr inbounds i8, ptr %s, i64 %oc0\n";
        $out .= "  %ocb2 = load i8, ptr %ocp2\n";
        $out .= "  %iscol = icmp eq i8 %ocb2, 58\n";          // :
        $out .= "  br i1 %iscol, label %ocok, label %ocmiss\n";
        $out .= "ocmiss:\n";
        $out .= "  %ocme = call i64 @manticore___mc_json_err(i64 4)\n";
        $out .= "  br label %ocok\n";
        $out .= "ocok:\n";
        $out .= "  %oc1 = add i64 %oc0, 1\n";
        $out .= "  %ocn = select i1 %iscol, i64 %oc1, i64 %oc0\n";
        $out .= "  store i64 %ocn, ptr %pp\n";
        $out .= "  br label %oval\n";
        $out .= "oval:\n";
        $out .= "  %ov = call i64 @__mir_json_deca(ptr %s, i64 %n, ptr %pp, i64 %assoc)\n";
        $out .= "  %ocur = load ptr, ptr %ap\n";
        // An assoc array normalises a canonical int key ("0", "-5") to the
        // int, as every php array write does; a stdClass keeps property names
        // as strings, and one starting with NUL is
        // JSON_ERROR_INVALID_PROPERTY_NAME.
        $out .= "  %oasc = icmp ne i64 %assoc, 0\n";
        $out .= "  br i1 %oasc, label %oikq, label %onulq\n";
        $out .= "oikq:\n";
        $out .= "  %oik = call i1 @__mir_jd_ikey(ptr %okey, ptr %ikv)\n";
        $out .= "  br i1 %oik, label %oseti, label %osets\n";
        $out .= "oseti:\n";
        $out .= "  %oiv = load i64, ptr %ikv\n";
        $out .= "  %onxi = call ptr @__mir_array_set_int(ptr %ocur, i64 %oiv, i64 %ov)\n";
        $out .= "  br label %osetd\n";
        $out .= "onulq:\n";
        $out .= "  %oklp = getelementptr inbounds i8, ptr %okey, i64 -16\n";
        $out .= "  %okl = load i64, ptr %oklp\n";
        $out .= "  %okne = icmp sgt i64 %okl, 0\n";
        $out .= "  %okb0 = load i8, ptr %okey\n";
        $out .= "  %okz = icmp eq i8 %okb0, 0\n";
        $out .= "  %oknul = and i1 %okne, %okz\n";
        $out .= "  br i1 %oknul, label %okbad, label %osets\n";
        $out .= "okbad:\n";
        $out .= "  %okbe = call i64 @manticore___mc_json_err(i64 9)\n";
        $out .= "  br label %osets\n";
        $out .= "osets:\n";
        $out .= "  %onxs = call ptr @__mir_array_set_str(ptr %ocur, ptr %okey, i64 %ov, i64 0, i64 0)\n";
        $out .= "  br label %osetd\n";
        $out .= "osetd:\n";
        $out .= "  %onx = phi ptr [%onxi, %oseti], [%onxs, %osets]\n";
        $out .= "  store ptr %onx, ptr %ap\n";
        // set_str took its own reference on the key; drop the one jd_str minted.
        $out .= "  call void @__mir_rc_release_str(ptr %okey)\n";
        $out .= "  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %occ = load i64, ptr %pp\n";
        $out .= "  %occoob = icmp sge i64 %occ, %n\n";
        $out .= "  br i1 %occoob, label %otail, label %ocomma\n";
        $out .= "ocomma:\n";
        $out .= "  %occp = getelementptr inbounds i8, ptr %s, i64 %occ\n";
        $out .= "  %occb = load i8, ptr %occp\n";
        $out .= "  %oisc = icmp eq i8 %occb, 44\n";
        $out .= "  br i1 %oisc, label %onext, label %otail\n";
        $out .= "onext:\n";
        $out .= "  %occ1 = add i64 %occ, 1\n";
        $out .= "  store i64 %occ1, ptr %pp\n";
        // `{"a":1,}` — a trailing comma, php's SYNTAX. Mirror of the array arm.
        $out .= "  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %otc = load i64, ptr %pp\n";
        $out .= "  %otcoob = icmp sge i64 %otc, %n\n";
        $out .= "  br i1 %otcoob, label %oerr, label %otcchk\n";
        $out .= "otcchk:\n";
        $out .= "  %otcp = getelementptr inbounds i8, ptr %s, i64 %otc\n";
        $out .= "  %otcb = load i8, ptr %otcp\n";
        $out .= "  %otcend = icmp eq i8 %otcb, 125\n";
        $out .= "  br i1 %otcend, label %oerr, label %oloop\n";
        $out .= "otail:\n";
        $out .= "  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)\n";
        $out .= "  %ot = load i64, ptr %pp\n";
        $out .= "  %otoob = icmp sge i64 %ot, %n\n";
        $out .= "  br i1 %otoob, label %oerr, label %otchk\n";
        $out .= "otchk:\n";
        $out .= "  %otp = getelementptr inbounds i8, ptr %s, i64 %ot\n";
        $out .= "  %otb = load i8, ptr %otp\n";
        $out .= "  %otend = icmp eq i8 %otb, 125\n";
        $out .= "  br i1 %otend, label %oteat, label %omis\n";
        $out .= "omis:\n";
        $out .= "  %omis1 = icmp eq i8 %otb, 93\n";
        $out .= "  br i1 %omis1, label %omis2, label %oerr\n";
        $out .= "omis2:\n";
        $out .= "  %omise = call i64 @manticore___mc_json_err(i64 2)\n";
        $out .= "  br label %odone\n";
        $out .= "oerr:\n";
        $out .= "  call void @__mir_jd_bad(ptr %s, i64 %n, ptr %pp, i64 4)\n";
        $out .= "  br label %odone\n";
        $out .= "oteat:\n";
        $out .= "  %ot1 = add i64 %ot, 1\n";
        $out .= "  store i64 %ot1, ptr %pp\n";
        $out .= "  br label %odone\n";
        $out .= "odone:\n";
        $out .= "  %odepd = load i64, ptr @__mir_jd_depth\n";
        $out .= "  %odepd1 = sub i64 %odepd, 1\n";
        $out .= "  store i64 %odepd1, ptr @__mir_jd_depth\n";
        $out .= "  %ofin = load ptr, ptr %ap\n";
        $out .= $stamp('o', '%ofin');
        if ($stdSize === 0) {
            // No stdClass in this module (a library `.o` carries no classes), so
            // `$assoc = false` degrades to the array rather than minting an
            // object around a null descriptor.
            $out .= "  %obx = call i64 @__manticore_box_array(ptr %ofin)\n";
            $out .= "  ret i64 %obx\n}\n";
        } else {
            // php's DEFAULT: a JSON object becomes a stdClass whose dynamic bag
            // IS the assoc just built — the same five instructions `(object)$a`
            // emits, and no copy, so `$assoc = false` costs almost nothing.
            $out .= "  %jsoQ = icmp ne i64 %assoc, 0\n";
            $out .= "  br i1 %jsoQ, label %jsoarr, label %jsoobj\n";
            $out .= "jsoarr:\n";
            $out .= "  %obx = call i64 @__manticore_box_array(ptr %ofin)\n";
            $out .= "  ret i64 %obx\n";
            $out .= "jsoobj:\n";
            $out .= "  %jsoO = call ptr @__mir_alloc_tagged(i64 " . (string)$stdSize . ")\n";
            $out .= "  store i64 " . $stdDesc . ", ptr %jsoO\n";
            $out .= "  %jsoRc = getelementptr inbounds i64, ptr %jsoO, i64 1\n";
            $out .= "  store i64 1, ptr %jsoRc\n";
            $out .= "  %jsoBag = getelementptr inbounds i8, ptr %jsoO, i64 "
                  . (string)$stdBagOff . "\n";
            $out .= "  %jsoFin = ptrtoint ptr %ofin to i64\n";
            $out .= "  store i64 %jsoFin, ptr %jsoBag\n";
            $out .= "  %jsoBox = call i64 @__manticore_box_object(ptr %jsoO)\n";
            $out .= "  ret i64 %jsoBox\n}\n";
        }

        // ── entry point: whole document ──
        // Installs the call's options, resets the error slot (php clears
        // json_last_error() on entry), parses, and rejects trailing bytes. A
        // rejected document answers null — the decoder frees what it built.
        $out .= '
define i64 @__mir_json_decf(ptr %s, i64 %assoc, i64 %depth, i64 %flags) {
entry:
  %pp = alloca i64
  store i64 0, ptr %pp
  store i64 0, ptr @__mir_jd_depth
  store i64 %depth, ptr @__mir_jd_max
  store i64 %flags, ptr @__mir_jd_flags
  %e0 = call i64 @manticore___mc_json_err(i64 0)
  %n = call i64 @__mir_strlen(ptr %s)
  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)
  %p0 = load i64, ptr %pp
  %mt = icmp sge i64 %p0, %n
  br i1 %mt, label %empty, label %go
empty:
  %em = call i64 @manticore___mc_json_err(i64 4)
  %enul = call i64 @__manticore_box_null()
  ret i64 %enul
go:
  %r = call i64 @__mir_json_deca(ptr %s, i64 %n, ptr %pp, i64 %assoc)
  call void @__mir_jd_ws(ptr %s, i64 %n, ptr %pp)
  %pe = load i64, ptr %pp
  %fin = icmp sge i64 %pe, %n
  br i1 %fin, label %chk, label %trail
trail:
  call void @__mir_jd_bad(ptr %s, i64 %n, ptr %pp, i64 4)
  br label %chk
chk:
  %err = call i64 @manticore___mc_json_err(i64 -1)
  %bad = icmp ne i64 %err, 0
  br i1 %bad, label %reject, label %ok
reject:
  call void @__mir_cell_drop(i64 %r)
  %rnul = call i64 @__manticore_box_null()
  ret i64 %rnul
ok:
  ret i64 %r
}
';
        return $out;
    }

    /**
     * The native fixed-width buffer runtime (`__mc_nbuf_*` builtins;
     * SplFixedArray, Manticore\Ds\*). Layout: {@see \Compile\MemoryAbi}
     * `BUF_*`. A handle is the block address as an i64; an op that may
     * reallocate returns the new handle. Every slot past `len` and inside
     * `cap` stays zero (a null cell for CELL), so growing is a length store.
     * A CELL slot owns its word: stores retain, removals drop, a read
     * (`get_c`) hands out a +1.
     */
    public function nbuf(): string
    {
        $ir = '
define i64 @__mir_nbuf_w(i64 %k) {
entry:
  switch i64 %k, label %w8 [
    i64 {I8}, label %w1
    i64 {U8}, label %w1
    i64 {I16}, label %w2
    i64 {U16}, label %w2
    i64 {I32}, label %w4
    i64 {U32}, label %w4
    i64 {F32}, label %w4
    i64 {BIT}, label %w0
  ]
w0:
  ret i64 0
w1:
  ret i64 1
w2:
  ret i64 2
w4:
  ret i64 4
w8:
  ret i64 8
}

define i64 @__mir_nbuf_bytes(i64 %k, i64 %n) {
entry:
  %bit = icmp eq i64 %k, {BIT}
  br i1 %bit, label %b, label %r
b:
  %a = add i64 %n, 63
  %s = lshr i64 %a, 6
  %m = shl i64 %s, 3
  ret i64 %m
r:
  %w = call i64 @__mir_nbuf_w(i64 %k)
  %x = mul i64 %n, %w
  ret i64 %x
}

define i64 @__mir_nbuf_kindof(i64 %h) {
entry:
  %p = inttoptr i64 %h to ptr
  %kp = getelementptr inbounds i8, ptr %p, i64 {KIND}
  %k32 = load i32, ptr %kp
  %k = zext i32 %k32 to i64
  ret i64 %k
}

define ptr @__mir_nbuf_data(i64 %h) {
entry:
  %p = inttoptr i64 %h to ptr
  %d = getelementptr inbounds i8, ptr %p, i64 {DATA}
  ret ptr %d
}

define void @__mir_nbuf_nulls(i64 %h, i64 %from, i64 %to) {
entry:
  %d = call ptr @__mir_nbuf_data(i64 %h)
  br label %loop
loop:
  %i = phi i64 [ %from, %entry ], [ %i2, %body ]
  %c = icmp slt i64 %i, %to
  br i1 %c, label %body, label %done
body:
  %sp = getelementptr inbounds i64, ptr %d, i64 %i
  store i64 {NULL}, ptr %sp
  %i2 = add i64 %i, 1
  br label %loop
done:
  ret void
}

define void @__mir_nbuf_drops(i64 %h, i64 %from, i64 %to) {
entry:
  %d = call ptr @__mir_nbuf_data(i64 %h)
  br label %loop
loop:
  %i = phi i64 [ %from, %entry ], [ %i2, %body ]
  %c = icmp slt i64 %i, %to
  br i1 %c, label %body, label %done
body:
  %sp = getelementptr inbounds i64, ptr %d, i64 %i
  %old = load i64, ptr %sp
  store i64 {NULL}, ptr %sp
  call void @__mir_cell_drop(i64 %old)
  %i2 = add i64 %i, 1
  br label %loop
done:
  ret void
}

define void @__mir_nbuf_clear(i64 %h, i64 %from, i64 %to) {
entry:
  %ge = icmp sge i64 %from, %to
  br i1 %ge, label %done, label %go
go:
  %k = call i64 @__mir_nbuf_kindof(i64 %h)
  switch i64 %k, label %raw [
    i64 {CELL}, label %cell
    i64 {BIT}, label %bloop
  ]
cell:
  call void @__mir_nbuf_drops(i64 %h, i64 %from, i64 %to)
  br label %done
bloop:
  %i = phi i64 [ %from, %go ], [ %i2, %bloop ]
  call void @__mir_nbuf_set_i(i64 %h, i64 %i, i64 0)
  %i2 = add i64 %i, 1
  %c = icmp slt i64 %i2, %to
  br i1 %c, label %bloop, label %done
raw:
  %w = call i64 @__mir_nbuf_w(i64 %k)
  %d = call ptr @__mir_nbuf_data(i64 %h)
  %off = mul i64 %from, %w
  %p = getelementptr inbounds i8, ptr %d, i64 %off
  %n = sub i64 %to, %from
  %nb = mul i64 %n, %w
  call ptr @memset(ptr %p, i32 0, i64 %nb)
  br label %done
done:
  ret void
}

define i64 @__mir_nbuf_alloc(i64 %k, i64 %len) {
entry:
  %small = icmp slt i64 %len, 4
  %cap = select i1 %small, i64 4, i64 %len
  %db = call i64 @__mir_nbuf_bytes(i64 %k, i64 %cap)
  %tot = add i64 %db, {DATA}
  %p = call ptr @calloc(i64 1, i64 %tot)
  store i64 %len, ptr %p
  %cp = getelementptr inbounds i8, ptr %p, i64 {CAP}
  store i64 %cap, ptr %cp
  %kp = getelementptr inbounds i8, ptr %p, i64 {KIND}
  %k32 = trunc i64 %k to i32
  store i32 %k32, ptr %kp
  %h = ptrtoint ptr %p to i64
  %isc = icmp eq i64 %k, {CELL}
  br i1 %isc, label %cell, label %done
cell:
  call void @__mir_nbuf_nulls(i64 %h, i64 0, i64 %cap)
  br label %done
done:
  ret i64 %h
}

define void @__mir_nbuf_free(i64 %h) {
entry:
  %z = icmp eq i64 %h, 0
  br i1 %z, label %done, label %go
go:
  %p = inttoptr i64 %h to ptr
  %k = call i64 @__mir_nbuf_kindof(i64 %h)
  %isc = icmp eq i64 %k, {CELL}
  br i1 %isc, label %cell, label %fr
cell:
  %len = load i64, ptr %p
  %d = call ptr @__mir_nbuf_data(i64 %h)
  br label %rloop
rloop:
  %ri = phi i64 [ %len, %cell ], [ %ri2, %rbody ]
  %rc = icmp sgt i64 %ri, 0
  br i1 %rc, label %rbody, label %fr
rbody:
  %ri2 = sub i64 %ri, 1
  %rsp = getelementptr inbounds i64, ptr %d, i64 %ri2
  %rold = load i64, ptr %rsp
  store i64 {NULL}, ptr %rsp
  call void @__mir_cell_drop(i64 %rold)
  br label %rloop
fr:
  call void @free(ptr %p)
  br label %done
done:
  ret void
}

define i64 @__mir_nbuf_len(i64 %h) {
entry:
  %z = icmp eq i64 %h, 0
  br i1 %z, label %zero, label %go
zero:
  ret i64 0
go:
  %p = inttoptr i64 %h to ptr
  %len = load i64, ptr %p
  ret i64 %len
}

define i64 @__mir_nbuf_grow(i64 %h, i64 %need) {
entry:
  %p = inttoptr i64 %h to ptr
  %cp = getelementptr inbounds i8, ptr %p, i64 {CAP}
  %cap = load i64, ptr %cp
  %ok = icmp sge i64 %cap, %need
  br i1 %ok, label %keep, label %re
keep:
  ret i64 %h
re:
  %dbl = shl i64 %cap, 1
  %big = icmp sgt i64 %need, %dbl
  %ncap = select i1 %big, i64 %need, i64 %dbl
  %k = call i64 @__mir_nbuf_kindof(i64 %h)
  %ob = call i64 @__mir_nbuf_bytes(i64 %k, i64 %cap)
  %nb = call i64 @__mir_nbuf_bytes(i64 %k, i64 %ncap)
  %tot = add i64 %nb, {DATA}
  %np = call ptr @realloc(ptr %p, i64 %tot)
  %ncp = getelementptr inbounds i8, ptr %np, i64 {CAP}
  store i64 %ncap, ptr %ncp
  %nd = getelementptr inbounds i8, ptr %np, i64 {DATA}
  %tail = getelementptr inbounds i8, ptr %nd, i64 %ob
  %tn = sub i64 %nb, %ob
  call ptr @memset(ptr %tail, i32 0, i64 %tn)
  %nh = ptrtoint ptr %np to i64
  %isc = icmp eq i64 %k, {CELL}
  br i1 %isc, label %cell, label %out
cell:
  call void @__mir_nbuf_nulls(i64 %nh, i64 %cap, i64 %ncap)
  br label %out
out:
  ret i64 %nh
}

define i64 @__mir_nbuf_resize(i64 %h, i64 %n) {
entry:
  %p = inttoptr i64 %h to ptr
  %len = load i64, ptr %p
  %gr = icmp sgt i64 %n, %len
  br i1 %gr, label %grow, label %shrink
grow:
  %nh = call i64 @__mir_nbuf_grow(i64 %h, i64 %n)
  %np = inttoptr i64 %nh to ptr
  store i64 %n, ptr %np
  ret i64 %nh
shrink:
  call void @__mir_nbuf_clear(i64 %h, i64 %n, i64 %len)
  store i64 %n, ptr %p
  ret i64 %h
}

define i64 @__mir_nbuf_get_i(i64 %h, i64 %i) {
entry:
  %k = call i64 @__mir_nbuf_kindof(i64 %h)
  %d = call ptr @__mir_nbuf_data(i64 %h)
  switch i64 %k, label %q [
    i64 {I8}, label %s8
    i64 {I16}, label %s16
    i64 {I32}, label %s32
    i64 {U8}, label %u8
    i64 {U16}, label %u16
    i64 {U32}, label %u32
    i64 {BIT}, label %bit
  ]
s8:
  %p1 = getelementptr inbounds i8, ptr %d, i64 %i
  %v1 = load i8, ptr %p1
  %r1 = sext i8 %v1 to i64
  ret i64 %r1
s16:
  %p2 = getelementptr inbounds i16, ptr %d, i64 %i
  %v2 = load i16, ptr %p2
  %r2 = sext i16 %v2 to i64
  ret i64 %r2
s32:
  %p3 = getelementptr inbounds i32, ptr %d, i64 %i
  %v3 = load i32, ptr %p3
  %r3 = sext i32 %v3 to i64
  ret i64 %r3
u8:
  %p5 = getelementptr inbounds i8, ptr %d, i64 %i
  %v5 = load i8, ptr %p5
  %r5 = zext i8 %v5 to i64
  ret i64 %r5
u16:
  %p6 = getelementptr inbounds i16, ptr %d, i64 %i
  %v6 = load i16, ptr %p6
  %r6 = zext i16 %v6 to i64
  ret i64 %r6
u32:
  %p7 = getelementptr inbounds i32, ptr %d, i64 %i
  %v7 = load i32, ptr %p7
  %r7 = zext i32 %v7 to i64
  ret i64 %r7
bit:
  %wi = lshr i64 %i, 6
  %bp = getelementptr inbounds i64, ptr %d, i64 %wi
  %wv = load i64, ptr %bp
  %bi = and i64 %i, 63
  %sh = lshr i64 %wv, %bi
  %b = and i64 %sh, 1
  ret i64 %b
q:
  %p4 = getelementptr inbounds i64, ptr %d, i64 %i
  %v4 = load i64, ptr %p4
  ret i64 %v4
}

define void @__mir_nbuf_set_i(i64 %h, i64 %i, i64 %v) {
entry:
  %k = call i64 @__mir_nbuf_kindof(i64 %h)
  %d = call ptr @__mir_nbuf_data(i64 %h)
  switch i64 %k, label %q [
    i64 {I8}, label %b8
    i64 {U8}, label %b8
    i64 {I16}, label %b16
    i64 {U16}, label %b16
    i64 {I32}, label %b32
    i64 {U32}, label %b32
    i64 {BIT}, label %bit
  ]
b8:
  %p1 = getelementptr inbounds i8, ptr %d, i64 %i
  %t1 = trunc i64 %v to i8
  store i8 %t1, ptr %p1
  ret void
b16:
  %p2 = getelementptr inbounds i16, ptr %d, i64 %i
  %t2 = trunc i64 %v to i16
  store i16 %t2, ptr %p2
  ret void
b32:
  %p3 = getelementptr inbounds i32, ptr %d, i64 %i
  %t3 = trunc i64 %v to i32
  store i32 %t3, ptr %p3
  ret void
bit:
  %wi = lshr i64 %i, 6
  %bp = getelementptr inbounds i64, ptr %d, i64 %wi
  %wv = load i64, ptr %bp
  %bi = and i64 %i, 63
  %mask = shl i64 1, %bi
  %inv = xor i64 %mask, -1
  %on = or i64 %wv, %mask
  %off = and i64 %wv, %inv
  %nz = icmp ne i64 %v, 0
  %nw = select i1 %nz, i64 %on, i64 %off
  store i64 %nw, ptr %bp
  ret void
q:
  %p4 = getelementptr inbounds i64, ptr %d, i64 %i
  store i64 %v, ptr %p4
  ret void
}

define double @__mir_nbuf_get_f(i64 %h, i64 %i) {
entry:
  %k = call i64 @__mir_nbuf_kindof(i64 %h)
  %d = call ptr @__mir_nbuf_data(i64 %h)
  %is32 = icmp eq i64 %k, {F32}
  br i1 %is32, label %f32, label %f64
f32:
  %p1 = getelementptr inbounds float, ptr %d, i64 %i
  %v1 = load float, ptr %p1
  %r1 = fpext float %v1 to double
  ret double %r1
f64:
  %p2 = getelementptr inbounds double, ptr %d, i64 %i
  %v2 = load double, ptr %p2
  ret double %v2
}

define void @__mir_nbuf_set_f(i64 %h, i64 %i, double %v) {
entry:
  %k = call i64 @__mir_nbuf_kindof(i64 %h)
  %d = call ptr @__mir_nbuf_data(i64 %h)
  %is32 = icmp eq i64 %k, {F32}
  br i1 %is32, label %f32, label %f64
f32:
  %p1 = getelementptr inbounds float, ptr %d, i64 %i
  %t1 = fptrunc double %v to float
  store float %t1, ptr %p1
  ret void
f64:
  %p2 = getelementptr inbounds double, ptr %d, i64 %i
  store double %v, ptr %p2
  ret void
}

define i64 @__mir_nbuf_get_c(i64 %h, i64 %i) {
entry:
  %d = call ptr @__mir_nbuf_data(i64 %h)
  %sp = getelementptr inbounds i64, ptr %d, i64 %i
  %v = load i64, ptr %sp
  call void @__mir_cell_retain(i64 %v)
  ret i64 %v
}

define void @__mir_nbuf_set_c(i64 %h, i64 %i, i64 %v) {
entry:
  %d = call ptr @__mir_nbuf_data(i64 %h)
  %sp = getelementptr inbounds i64, ptr %d, i64 %i
  call void @__mir_cell_retain(i64 %v)
  %old = load i64, ptr %sp
  store i64 %v, ptr %sp
  call void @__mir_cell_drop(i64 %old)
  ret void
}

define i64 @__mir_nbuf_insert(i64 %h0, i64 %at, i64 %cnt) {
entry:
  %pos = icmp sgt i64 %cnt, 0
  br i1 %pos, label %go, label %nop
nop:
  ret i64 %h0
go:
  %p0 = inttoptr i64 %h0 to ptr
  %len = load i64, ptr %p0
  %nl = add i64 %len, %cnt
  %h = call i64 @__mir_nbuf_grow(i64 %h0, i64 %nl)
  %p = inttoptr i64 %h to ptr
  store i64 %nl, ptr %p
  %k = call i64 @__mir_nbuf_kindof(i64 %h)
  %end = add i64 %at, %cnt
  %isbit = icmp eq i64 %k, {BIT}
  %jstart = sub i64 %len, 1
  br i1 %isbit, label %bl, label %raw
raw:
  %w = call i64 @__mir_nbuf_w(i64 %k)
  %d = call ptr @__mir_nbuf_data(i64 %h)
  %so = mul i64 %at, %w
  %src = getelementptr inbounds i8, ptr %d, i64 %so
  %do = mul i64 %end, %w
  %dst = getelementptr inbounds i8, ptr %d, i64 %do
  %tn = sub i64 %len, %at
  %tb = mul i64 %tn, %w
  call ptr @memmove(ptr %dst, ptr %src, i64 %tb)
  %isc = icmp eq i64 %k, {CELL}
  br i1 %isc, label %cgap, label %rgap
cgap:
  call void @__mir_nbuf_nulls(i64 %h, i64 %at, i64 %end)
  br label %done
rgap:
  %gb = mul i64 %cnt, %w
  call ptr @memset(ptr %src, i32 0, i64 %gb)
  br label %done
bl:
  %j = phi i64 [ %jstart, %go ], [ %j2, %bb ]
  %c = icmp sge i64 %j, %at
  br i1 %c, label %bb, label %bz
bb:
  %bv = call i64 @__mir_nbuf_get_i(i64 %h, i64 %j)
  %t = add i64 %j, %cnt
  call void @__mir_nbuf_set_i(i64 %h, i64 %t, i64 %bv)
  %j2 = sub i64 %j, 1
  br label %bl
bz:
  call void @__mir_nbuf_clear(i64 %h, i64 %at, i64 %end)
  br label %done
done:
  ret i64 %h
}

define void @__mir_nbuf_remove(i64 %h, i64 %at, i64 %cnt) {
entry:
  %pos = icmp sgt i64 %cnt, 0
  br i1 %pos, label %go, label %nop
nop:
  ret void
go:
  %p = inttoptr i64 %h to ptr
  %len = load i64, ptr %p
  %k = call i64 @__mir_nbuf_kindof(i64 %h)
  %end = add i64 %at, %cnt
  %nl = sub i64 %len, %cnt
  %isc = icmp eq i64 %k, {CELL}
  switch i64 %k, label %mv [
    i64 {CELL}, label %cdrop
    i64 {BIT}, label %bl
  ]
cdrop:
  call void @__mir_nbuf_drops(i64 %h, i64 %at, i64 %end)
  br label %mv
mv:
  %w = call i64 @__mir_nbuf_w(i64 %k)
  %d = call ptr @__mir_nbuf_data(i64 %h)
  %do = mul i64 %at, %w
  %dst = getelementptr inbounds i8, ptr %d, i64 %do
  %so = mul i64 %end, %w
  %src = getelementptr inbounds i8, ptr %d, i64 %so
  %tn = sub i64 %len, %end
  %tb = mul i64 %tn, %w
  call ptr @memmove(ptr %dst, ptr %src, i64 %tb)
  br i1 %isc, label %ctail, label %rtail
ctail:
  call void @__mir_nbuf_nulls(i64 %h, i64 %nl, i64 %len)
  br label %fin
rtail:
  %to = mul i64 %nl, %w
  %tp = getelementptr inbounds i8, ptr %d, i64 %to
  %gb = mul i64 %cnt, %w
  call ptr @memset(ptr %tp, i32 0, i64 %gb)
  br label %fin
bl:
  %j = phi i64 [ %at, %go ], [ %j2, %bb ]
  %c = icmp slt i64 %j, %nl
  br i1 %c, label %bb, label %bz
bb:
  %f = add i64 %j, %cnt
  %bv = call i64 @__mir_nbuf_get_i(i64 %h, i64 %f)
  call void @__mir_nbuf_set_i(i64 %h, i64 %j, i64 %bv)
  %j2 = add i64 %j, 1
  br label %bl
bz:
  call void @__mir_nbuf_clear(i64 %h, i64 %nl, i64 %len)
  br label %fin
fin:
  store i64 %nl, ptr %p
  ret void
}

define void @__mir_nbuf_copy(i64 %dst, i64 %da, i64 %src, i64 %sa, i64 %cnt) {
entry:
  %pos = icmp sgt i64 %cnt, 0
  br i1 %pos, label %go, label %fin
go:
  %k = call i64 @__mir_nbuf_kindof(i64 %dst)
  %dd = call ptr @__mir_nbuf_data(i64 %dst)
  %isc = icmp eq i64 %k, {CELL}
  switch i64 %k, label %raw [
    i64 {CELL}, label %el
    i64 {BIT}, label %el
  ]
raw:
  %w = call i64 @__mir_nbuf_w(i64 %k)
  %sd = call ptr @__mir_nbuf_data(i64 %src)
  %do = mul i64 %da, %w
  %dp = getelementptr inbounds i8, ptr %dd, i64 %do
  %so = mul i64 %sa, %w
  %sp = getelementptr inbounds i8, ptr %sd, i64 %so
  %nb = mul i64 %cnt, %w
  call ptr @memmove(ptr %dp, ptr %sp, i64 %nb)
  br label %fin
el:
  %same = icmp eq i64 %dst, %src
  %after = icmp sgt i64 %da, %sa
  %desc = and i1 %same, %after
  %last = sub i64 %cnt, 1
  %start = select i1 %desc, i64 %last, i64 0
  %step = select i1 %desc, i64 -1, i64 1
  br label %loop
loop:
  %j = phi i64 [ %start, %el ], [ %j2, %next ]
  %n = phi i64 [ 0, %el ], [ %n2, %next ]
  %more = icmp slt i64 %n, %cnt
  br i1 %more, label %body, label %fin
body:
  %si = add i64 %sa, %j
  %di = add i64 %da, %j
  br i1 %isc, label %cel, label %bel
cel:
  %cv = call i64 @__mir_nbuf_get_c(i64 %src, i64 %si)
  %slot = getelementptr inbounds i64, ptr %dd, i64 %di
  %old = load i64, ptr %slot
  store i64 %cv, ptr %slot
  call void @__mir_cell_drop(i64 %old)
  br label %next
bel:
  %bv = call i64 @__mir_nbuf_get_i(i64 %src, i64 %si)
  call void @__mir_nbuf_set_i(i64 %dst, i64 %di, i64 %bv)
  br label %next
next:
  %j2 = add i64 %j, %step
  %n2 = add i64 %n, 1
  br label %loop
fin:
  ret void
}

define void @__mir_nbuf_move(i64 %h, i64 %from, i64 %to, i64 %cnt) {
entry:
  call void @__mir_nbuf_copy(i64 %h, i64 %to, i64 %h, i64 %from, i64 %cnt)
  ret void
}

define void @__mir_nbuf_fill_i(i64 %h, i64 %v, i64 %from, i64 %to) {
entry:
  br label %loop
loop:
  %i = phi i64 [ %from, %entry ], [ %i2, %body ]
  %c = icmp slt i64 %i, %to
  br i1 %c, label %body, label %done
body:
  call void @__mir_nbuf_set_i(i64 %h, i64 %i, i64 %v)
  %i2 = add i64 %i, 1
  br label %loop
done:
  ret void
}

define void @__mir_nbuf_fill_f(i64 %h, double %v, i64 %from, i64 %to) {
entry:
  br label %loop
loop:
  %i = phi i64 [ %from, %entry ], [ %i2, %body ]
  %c = icmp slt i64 %i, %to
  br i1 %c, label %body, label %done
body:
  call void @__mir_nbuf_set_f(i64 %h, i64 %i, double %v)
  %i2 = add i64 %i, 1
  br label %loop
done:
  ret void
}

define i64 @__mir_nbuf_find_i(i64 %h, i64 %v, i64 %from) {
entry:
  %len = call i64 @__mir_nbuf_len(i64 %h)
  br label %loop
loop:
  %i = phi i64 [ %from, %entry ], [ %i2, %next ]
  %c = icmp slt i64 %i, %len
  br i1 %c, label %body, label %miss
body:
  %e = call i64 @__mir_nbuf_get_i(i64 %h, i64 %i)
  %eq = icmp eq i64 %e, %v
  br i1 %eq, label %hit, label %next
next:
  %i2 = add i64 %i, 1
  br label %loop
hit:
  ret i64 %i
miss:
  ret i64 -1
}

define i64 @__mir_nbuf_find_f(i64 %h, double %v, i64 %from) {
entry:
  %len = call i64 @__mir_nbuf_len(i64 %h)
  br label %loop
loop:
  %i = phi i64 [ %from, %entry ], [ %i2, %next ]
  %c = icmp slt i64 %i, %len
  br i1 %c, label %body, label %miss
body:
  %e = call double @__mir_nbuf_get_f(i64 %h, i64 %i)
  %eq = fcmp oeq double %e, %v
  br i1 %eq, label %hit, label %next
next:
  %i2 = add i64 %i, 1
  br label %loop
hit:
  ret i64 %i
miss:
  ret i64 -1
}

define i64 @__mir_nbuf_clone(i64 %h) {
entry:
  %z = icmp eq i64 %h, 0
  br i1 %z, label %zero, label %go
zero:
  ret i64 0
go:
  %p = inttoptr i64 %h to ptr
  %len = load i64, ptr %p
  %k = call i64 @__mir_nbuf_kindof(i64 %h)
  %n = call i64 @__mir_nbuf_alloc(i64 %k, i64 %len)
  %nb = call i64 @__mir_nbuf_bytes(i64 %k, i64 %len)
  %sd = call ptr @__mir_nbuf_data(i64 %h)
  %nd = call ptr @__mir_nbuf_data(i64 %n)
  call ptr @memmove(ptr %nd, ptr %sd, i64 %nb)
  %isc = icmp eq i64 %k, {CELL}
  br i1 %isc, label %loop, label %done
loop:
  %i = phi i64 [ 0, %go ], [ %i2, %body ]
  %c = icmp slt i64 %i, %len
  br i1 %c, label %body, label %done
body:
  %sp = getelementptr inbounds i64, ptr %nd, i64 %i
  %v = load i64, ptr %sp
  call void @__mir_cell_retain(i64 %v)
  %i2 = add i64 %i, 1
  br label %loop
done:
  ret i64 %n
}
';
        /** @var array<string, string> $sub */
        $sub = [
            '{CAP}' => (string)\Compile\MemoryAbi::BUF_CAP_OFFSET,
            '{KIND}' => (string)\Compile\MemoryAbi::BUF_KIND_OFFSET,
            '{DATA}' => (string)\Compile\MemoryAbi::BUF_DATA_OFFSET,
            '{NULL}' => (string)\Compile\MemoryAbi::CELL_NULL,
            '{I8}' => (string)\Compile\MemoryAbi::BUF_KIND_I8,
            '{I16}' => (string)\Compile\MemoryAbi::BUF_KIND_I16,
            '{I32}' => (string)\Compile\MemoryAbi::BUF_KIND_I32,
            '{U8}' => (string)\Compile\MemoryAbi::BUF_KIND_U8,
            '{U16}' => (string)\Compile\MemoryAbi::BUF_KIND_U16,
            '{U32}' => (string)\Compile\MemoryAbi::BUF_KIND_U32,
            '{F32}' => (string)\Compile\MemoryAbi::BUF_KIND_F32,
            '{BIT}' => (string)\Compile\MemoryAbi::BUF_KIND_BIT,
            '{CELL}' => (string)\Compile\MemoryAbi::BUF_KIND_CELL,
        ];
        foreach ($sub as $from => $to) { $ir = \str_replace($from, $to, $ir); }
        return $ir . $this->nbufReduce();
    }

    /**
     * Whole-buffer reductions: `reduce_i` / `reduce_f` (`$op` 0 sum, 1 min,
     * 2 max) and `same` (element-wise equality of two buffers of one kind and
     * length). One loop per element kind and operation, each over a typed
     * load, so the optimiser sees a plain counted loop instead of a kind test
     * per element. An empty buffer answers the identity (0, or the far end of
     * the range for min / max).
     */
    private function nbufReduce(): string
    {
        /** @var array<int, array<int, string>> kind, element type, widening cast[, 'u' = compare unsigned] */
        $ints = [
            [(string)\Compile\MemoryAbi::BUF_KIND_I8, 'i8', 'sext'],
            [(string)\Compile\MemoryAbi::BUF_KIND_I16, 'i16', 'sext'],
            [(string)\Compile\MemoryAbi::BUF_KIND_I32, 'i32', 'sext'],
            [(string)\Compile\MemoryAbi::BUF_KIND_I64, 'i64', ''],
            [(string)\Compile\MemoryAbi::BUF_KIND_U8, 'i8', 'zext'],
            [(string)\Compile\MemoryAbi::BUF_KIND_U16, 'i16', 'zext'],
            [(string)\Compile\MemoryAbi::BUF_KIND_U32, 'i32', 'zext'],
            [(string)\Compile\MemoryAbi::BUF_KIND_U64, 'i64', '', 'u'],
        ];
        /** @var array<int, array<int, string>> */
        $floats = [
            [(string)\Compile\MemoryAbi::BUF_KIND_F32, 'float', 'fpext'],
            [(string)\Compile\MemoryAbi::BUF_KIND_F64, 'double', ''],
        ];
        /** @var array<int, string> */
        $initI = ['0', '9223372036854775807', '-9223372036854775808'];
        /** @var array<int, string> */
        $initF = ['0.0', '0x7FF0000000000000', '0xFFF0000000000000'];
        $ir = '';
        foreach ([$ints, $floats] as $fam => $kinds) {
            $acc = $fam === 0 ? 'i64' : 'double';
            $sfx = $fam === 0 ? 'i' : 'f';
            foreach ($kinds as $kd) {
                $uns = ($kd[3] ?? '') === 'u';
                for ($op = 0; $op < 3; $op++) {
                    $init = $fam === 0 ? $initI[$op] : $initF[$op];
                    // Unsigned: min starts at all ones, max at zero.
                    if ($uns && $op > 0) { $init = $op === 1 ? '-1' : '0'; }
                    $ir .= 'define ' . $acc . ' @__mir_nbuf_red_' . $kd[0] . '_' . (string)$op . "(ptr %d, i64 %n) {\n"
                        . "entry:\n  %z = icmp sgt i64 %n, 0\n  br i1 %z, label %loop, label %done\n"
                        . "loop:\n  %i = phi i64 [ 0, %entry ], [ %i2, %loop ]\n"
                        . '  %a = phi ' . $acc . ' [ ' . $init . ", %entry ], [ %a2, %loop ]\n"
                        . '  %p = getelementptr inbounds ' . $kd[1] . ", ptr %d, i64 %i\n";
                    if ($kd[2] === '') {
                        $ir .= '  %v = load ' . $kd[1] . ", ptr %p\n";
                    } else {
                        $ir .= '  %r = load ' . $kd[1] . ", ptr %p\n"
                            . '  %v = ' . $kd[2] . ' ' . $kd[1] . ' %r to ' . $acc . "\n";
                    }
                    if ($op === 0) {
                        $ir .= '  %a2 = ' . ($fam === 0 ? 'add i64' : 'fadd double') . " %a, %v\n";
                    } else {
                        $cmp = $fam === 0 ? ($op === 1 ? ($uns ? 'icmp ult i64' : 'icmp slt i64') : ($uns ? 'icmp ugt i64' : 'icmp sgt i64')) : ($op === 1 ? 'fcmp olt double' : 'fcmp ogt double');
                        $ir .= '  %c = ' . $cmp . " %v, %a\n"
                            . '  %a2 = select i1 %c, ' . $acc . ' %v, ' . $acc . " %a\n";
                    }
                    $ir .= "  %i2 = add nuw nsw i64 %i, 1\n  %e = icmp eq i64 %i2, %n\n  br i1 %e, label %done, label %loop\n"
                        . "done:\n  %res = phi " . $acc . ' [ ' . $init . ", %entry ], [ %a2, %loop ]\n"
                        . '  ret ' . $acc . " %res\n}\n\n";
                }
            }
            // The dispatcher: kind, then operation. A kind outside the family
            // (a BitArray under reduce_i) is summed element by element.
            $ir .= 'define ' . $acc . ' @__mir_nbuf_reduce_' . $sfx . "(i64 %h, i64 %op) {\n"
                . "entry:\n  %n = call i64 @__mir_nbuf_len(i64 %h)\n  %k = call i64 @__mir_nbuf_kindof(i64 %h)\n"
                . "  %d = call ptr @__mir_nbuf_data(i64 %h)\n  switch i64 %k, label %other [\n";
            foreach ($kinds as $kd) { $ir .= '    i64 ' . $kd[0] . ', label %k' . $kd[0] . "\n"; }
            $ir .= "  ]\n";
            foreach ($kinds as $kd) {
                $ir .= 'k' . $kd[0] . ":\n  switch i64 %op, label %k" . $kd[0] . "o0 [\n    i64 1, label %k" . $kd[0]
                    . "o1\n    i64 2, label %k" . $kd[0] . "o2\n  ]\n";
                for ($op = 0; $op < 3; $op++) {
                    $ir .= 'k' . $kd[0] . 'o' . (string)$op . ":\n  %r" . $kd[0] . '_' . (string)$op . ' = call ' . $acc
                        . ' @__mir_nbuf_red_' . $kd[0] . '_' . (string)$op . "(ptr %d, i64 %n)\n"
                        . '  ret ' . $acc . ' %r' . $kd[0] . '_' . (string)$op . "\n";
                }
            }
            $get = $fam === 0 ? 'call i64 @__mir_nbuf_get_i' : 'call double @__mir_nbuf_get_f';
            $zero = $fam === 0 ? '0' : '0.0';
            $ir .= "other:\n  br label %oloop\noloop:\n  %oi = phi i64 [ 0, %other ], [ %oi2, %obody ]\n"
                . '  %oa = phi ' . $acc . ' [ ' . $zero . ", %other ], [ %oa2, %obody ]\n"
                . "  %oc = icmp slt i64 %oi, %n\n  br i1 %oc, label %obody, label %odone\n"
                . "obody:\n  %ov = " . $get . "(i64 %h, i64 %oi)\n"
                . '  %oa2 = ' . ($fam === 0 ? 'add i64' : 'fadd double') . " %oa, %ov\n"
                . "  %oi2 = add i64 %oi, 1\n  br label %oloop\nodone:\n  ret " . $acc . " %oa\n}\n\n";
        }
        $f32 = (string)\Compile\MemoryAbi::BUF_KIND_F32;
        $f64 = (string)\Compile\MemoryAbi::BUF_KIND_F64;
        // Raw kinds compare as bytes (the slack past `len` is zero, so a
        // BitArray's last word compares whole); floats compare as values.
        $ir .= "define i64 @__mir_nbuf_same(i64 %a, i64 %b) {\n"
            . "entry:\n  %n = call i64 @__mir_nbuf_len(i64 %a)\n  %k = call i64 @__mir_nbuf_kindof(i64 %a)\n"
            . "  switch i64 %k, label %raw [\n    i64 " . $f32 . ", label %fl\n    i64 " . $f64 . ", label %fl\n  ]\n"
            . "raw:\n  %by = call i64 @__mir_nbuf_bytes(i64 %k, i64 %n)\n"
            . "  %da = call ptr @__mir_nbuf_data(i64 %a)\n  %db = call ptr @__mir_nbuf_data(i64 %b)\n"
            . "  %m = call i32 @memcmp(ptr %da, ptr %db, i64 %by)\n  %eq = icmp eq i32 %m, 0\n"
            . "  %r = zext i1 %eq to i64\n  ret i64 %r\n"
            . "fl:\n  br label %loop\nloop:\n  %i = phi i64 [ 0, %fl ], [ %i2, %next ]\n"
            . "  %c = icmp slt i64 %i, %n\n  br i1 %c, label %body, label %yes\n"
            . "body:\n  %x = call double @__mir_nbuf_get_f(i64 %a, i64 %i)\n  %y = call double @__mir_nbuf_get_f(i64 %b, i64 %i)\n"
            . "  %ne = fcmp une double %x, %y\n  br i1 %ne, label %no, label %next\n"
            . "next:\n  %i2 = add i64 %i, 1\n  br label %loop\nyes:\n  ret i64 1\nno:\n  ret i64 0\n}\n\n";
        // Byte-offset access for a byte buffer (`Manticore\Ds\ByteBuffer`):
        // `peek` / `poke` move `%w` bytes, least significant first unless
        // `%be`; the caller has checked the span. `bits_f` / `f_bits` are the
        // IEEE-754 image of a float (`%w` 4: through binary32).
        $ir .= "define i64 @__mir_nbuf_peek(i64 %h, i64 %off, i64 %w, i64 %be) {\n"
            . "entry:\n  %d = call ptr @__mir_nbuf_data(i64 %h)\n  %p = getelementptr inbounds i8, ptr %d, i64 %off\n"
            . "  %big = icmp ne i64 %be, 0\n  %last = sub i64 %w, 1\n  br label %loop\n"
            . "loop:\n  %i = phi i64 [ 0, %entry ], [ %i2, %body ]\n  %v = phi i64 [ 0, %entry ], [ %v2, %body ]\n"
            . "  %c = icmp slt i64 %i, %w\n  br i1 %c, label %body, label %done\n"
            . "body:\n  %ri = sub i64 %last, %i\n  %ix = select i1 %big, i64 %ri, i64 %i\n"
            . "  %bp = getelementptr inbounds i8, ptr %p, i64 %ix\n  %b = load i8, ptr %bp\n  %z = zext i8 %b to i64\n"
            . "  %sh = shl i64 %i, 3\n  %t = shl i64 %z, %sh\n  %v2 = or i64 %v, %t\n  %i2 = add i64 %i, 1\n  br label %loop\n"
            . "done:\n  ret i64 %v\n}\n\n"
            . "define void @__mir_nbuf_poke(i64 %h, i64 %off, i64 %w, i64 %be, i64 %v) {\n"
            . "entry:\n  %d = call ptr @__mir_nbuf_data(i64 %h)\n  %p = getelementptr inbounds i8, ptr %d, i64 %off\n"
            . "  %big = icmp ne i64 %be, 0\n  %last = sub i64 %w, 1\n  br label %loop\n"
            . "loop:\n  %i = phi i64 [ 0, %entry ], [ %i2, %body ]\n  %c = icmp slt i64 %i, %w\n  br i1 %c, label %body, label %done\n"
            . "body:\n  %ri = sub i64 %last, %i\n  %ix = select i1 %big, i64 %ri, i64 %i\n"
            . "  %bp = getelementptr inbounds i8, ptr %p, i64 %ix\n  %sh = shl i64 %i, 3\n  %t = lshr i64 %v, %sh\n"
            . "  %b = trunc i64 %t to i8\n  store i8 %b, ptr %bp\n  %i2 = add i64 %i, 1\n  br label %loop\n"
            . "done:\n  ret void\n}\n\n"
            . "define double @__mir_nbuf_bits_f(i64 %bits, i64 %w) {\n"
            . "entry:\n  %wide = icmp eq i64 %w, 8\n  br i1 %wide, label %d64, label %d32\n"
            . "d64:\n  %x = bitcast i64 %bits to double\n  ret double %x\n"
            . "d32:\n  %n = trunc i64 %bits to i32\n  %f = bitcast i32 %n to float\n  %y = fpext float %f to double\n  ret double %y\n}\n\n"
            . "define i64 @__mir_nbuf_f_bits(double %v, i64 %w) {\n"
            . "entry:\n  %wide = icmp eq i64 %w, 8\n  br i1 %wide, label %d64, label %d32\n"
            . "d64:\n  %x = bitcast double %v to i64\n  ret i64 %x\n"
            . "d32:\n  %f = fptrunc double %v to float\n  %n = bitcast float %f to i32\n  %y = zext i32 %n to i64\n  ret i64 %y\n}\n\n";
        return $ir;
    }

    /**
     * The native insertion-ordered hash table (`__mc_hmap_*` builtins;
     * Manticore\Ds\Map / Set). Layout: {@see \Compile\MemoryAbi} `HMAP_*`. A
     * handle is the header block address as an i64. Entries are appended in
     * insertion order; a delete tombstones its entry (hash -1) and removes its
     * index slot by backward shift, so the u32 index never holds a tombstone.
     * An entry owns its key and value cells: put retains, delete / clear / free
     * drop, key / val hand out a +1. The PHP twins are src/Runtime/Stdlib/HMap.php.
     */
    public function hmap(): string
    {
        $ir = '
define i64 @__mir_hmap_hld(i64 %h, i64 %off) {
entry:
  %p = inttoptr i64 %h to ptr
  %q = getelementptr inbounds i8, ptr %p, i64 %off
  %v = load i64, ptr %q
  ret i64 %v
}

define void @__mir_hmap_hst(i64 %h, i64 %off, i64 %v) {
entry:
  %p = inttoptr i64 %h to ptr
  %q = getelementptr inbounds i8, ptr %p, i64 %off
  store i64 %v, ptr %q
  ret void
}

define i64 @__mir_hmap_stride(i64 %h) {
entry:
  %f = call i64 @__mir_hmap_hld(i64 %h, i64 {FLAGS})
  %s = and i64 %f, {FSET}
  %b = mul i64 %s, {ESD}
  %r = sub i64 {ESM}, %b
  ret i64 %r
}

define ptr @__mir_hmap_ent(i64 %h, i64 %e) {
entry:
  %s = call i64 @__mir_hmap_stride(i64 %h)
  %o = mul i64 %e, %s
  %b = call i64 @__mir_hmap_hld(i64 %h, i64 {ENTRIES})
  %bp = inttoptr i64 %b to ptr
  %p = getelementptr inbounds i8, ptr %bp, i64 %o
  ret ptr %p
}

define i64 @__mir_hmap_mix(i64 %x) {
entry:
  %a = lshr i64 %x, 30
  %b = xor i64 %x, %a
  %c = mul i64 %b, -4658895280553007687
  %d = lshr i64 %c, 27
  %e = xor i64 %c, %d
  %f = mul i64 %e, -7723592293110705685
  %g = lshr i64 %f, 31
  %i = xor i64 %f, %g
  %j = and i64 %i, 9223372036854775807
  ret i64 %j
}

define i64 @__mir_hmap_hash(i64 %k0) {
entry:
  %k = call i64 @__manticore_deref(i64 %k0)
  %t = call i64 @__manticore_tag(i64 %k)
  switch i64 %t, label %obj [
    i64 1, label %int
    i64 4, label %str
  ]
int:
  %x = call i64 @__manticore_unbox_int(i64 %k)
  %hi = call i64 @__mir_hmap_mix(i64 %x)
  ret i64 %hi
str:
  %sm = and i64 %k, {PMASK}
  %sp = inttoptr i64 %sm to ptr
  %sx = call i64 @__mir_array_hash_str(ptr %sp)
  %hs = call i64 @__mir_hmap_mix(i64 %sx)
  ret i64 %hs
obj:
  %om = and i64 %k, {PMASK}
  %ox = lshr i64 %om, 4
  %ho = call i64 @__mir_hmap_mix(i64 %ox)
  ret i64 %ho
}

define i1 @__mir_hmap_eq(i64 %a0, i64 %b0) {
entry:
  %w = icmp eq i64 %a0, %b0
  br i1 %w, label %yes, label %cl
yes:
  ret i1 1
cl:
  %a = call i64 @__manticore_deref(i64 %a0)
  %b = call i64 @__manticore_deref(i64 %b0)
  %ta = call i64 @__manticore_tag(i64 %a)
  %tb = call i64 @__manticore_tag(i64 %b)
  %same = icmp eq i64 %ta, %tb
  br i1 %same, label %k, label %no
k:
  switch i64 %ta, label %obj [
    i64 1, label %int
    i64 4, label %str
  ]
int:
  %ua = call i64 @__manticore_unbox_int(i64 %a)
  %ub = call i64 @__manticore_unbox_int(i64 %b)
  %ie = icmp eq i64 %ua, %ub
  ret i1 %ie
str:
  %sa = and i64 %a, {PMASK}
  %sb = and i64 %b, {PMASK}
  %pa = inttoptr i64 %sa to ptr
  %pb = inttoptr i64 %sb to ptr
  %se = call i1 @__mir_str_eq(ptr %pa, ptr %pb)
  ret i1 %se
obj:
  %oa = and i64 %a, {PMASK}
  %ob = and i64 %b, {PMASK}
  %oe = icmp eq i64 %oa, %ob
  ret i1 %oe
no:
  ret i1 0
}

define i64 @__mir_hmap_findh(i64 %h, i64 %key, i64 %hash) {
entry:
  %mask = call i64 @__mir_hmap_hld(i64 %h, i64 {MASK})
  %ixw = call i64 @__mir_hmap_hld(i64 %h, i64 {INDEX})
  %ixp = inttoptr i64 %ixw to ptr
  %s0 = and i64 %hash, %mask
  br label %loop
loop:
  %s = phi i64 [ %s0, %entry ], [ %s2, %next ]
  %sp = getelementptr inbounds i32, ptr %ixp, i64 %s
  %sl = load i32, ptr %sp
  %emp = icmp eq i32 %sl, -1
  br i1 %emp, label %miss, label %chk
chk:
  %e = zext i32 %sl to i64
  %ep = call ptr @__mir_hmap_ent(i64 %h, i64 %e)
  %eh = load i64, ptr %ep
  %heq = icmp eq i64 %eh, %hash
  br i1 %heq, label %cmp, label %next
cmp:
  %kp = getelementptr inbounds i8, ptr %ep, i64 {EKEY}
  %kk = load i64, ptr %kp
  %q = call i1 @__mir_hmap_eq(i64 %kk, i64 %key)
  br i1 %q, label %hit, label %next
next:
  %s1 = add i64 %s, 1
  %s2 = and i64 %s1, %mask
  br label %loop
hit:
  ret i64 %e
miss:
  ret i64 -1
}

define i64 @__mir_hmap_find(i64 %h, i64 %key) {
entry:
  %hash = call i64 @__mir_hmap_hash(i64 %key)
  %r = call i64 @__mir_hmap_findh(i64 %h, i64 %key, i64 %hash)
  ret i64 %r
}

define void @__mir_hmap_ixins(i64 %h, i64 %hash, i64 %e) {
entry:
  %mask = call i64 @__mir_hmap_hld(i64 %h, i64 {MASK})
  %ixw = call i64 @__mir_hmap_hld(i64 %h, i64 {INDEX})
  %ixp = inttoptr i64 %ixw to ptr
  %s0 = and i64 %hash, %mask
  %e32 = trunc i64 %e to i32
  br label %loop
loop:
  %s = phi i64 [ %s0, %entry ], [ %s2, %next ]
  %sp = getelementptr inbounds i32, ptr %ixp, i64 %s
  %sl = load i32, ptr %sp
  %emp = icmp eq i32 %sl, -1
  br i1 %emp, label %put, label %next
next:
  %s1 = add i64 %s, 1
  %s2 = and i64 %s1, %mask
  br label %loop
put:
  store i32 %e32, ptr %sp
  ret void
}

define void @__mir_hmap_reindex(i64 %h, i64 %slots) {
entry:
  %old = call i64 @__mir_hmap_hld(i64 %h, i64 {INDEX})
  %op = inttoptr i64 %old to ptr
  call void @free(ptr %op)
  %bytes = shl i64 %slots, 2
  %np = call ptr @malloc(i64 %bytes)
  call ptr @memset(ptr %np, i32 255, i64 %bytes)
  %nw = ptrtoint ptr %np to i64
  call void @__mir_hmap_hst(i64 %h, i64 {INDEX}, i64 %nw)
  %mask = sub i64 %slots, 1
  call void @__mir_hmap_hst(i64 %h, i64 {MASK}, i64 %mask)
  %used = call i64 @__mir_hmap_hld(i64 %h, i64 {USED})
  br label %loop
loop:
  %e = phi i64 [ 0, %entry ], [ %e2, %next ]
  %c = icmp slt i64 %e, %used
  br i1 %c, label %body, label %done
body:
  %ep = call ptr @__mir_hmap_ent(i64 %h, i64 %e)
  %eh = load i64, ptr %ep
  %t = icmp eq i64 %eh, {TOMB}
  br i1 %t, label %next, label %ins
ins:
  call void @__mir_hmap_ixins(i64 %h, i64 %eh, i64 %e)
  br label %next
next:
  %e2 = add i64 %e, 1
  br label %loop
done:
  ret void
}

define void @__mir_hmap_compact(i64 %h) {
entry:
  %used = call i64 @__mir_hmap_hld(i64 %h, i64 {USED})
  %st = call i64 @__mir_hmap_stride(i64 %h)
  br label %loop
loop:
  %e = phi i64 [ 0, %entry ], [ %e2, %next ]
  %j = phi i64 [ 0, %entry ], [ %j2, %next ]
  %c = icmp slt i64 %e, %used
  br i1 %c, label %body, label %done
body:
  %ep = call ptr @__mir_hmap_ent(i64 %h, i64 %e)
  %eh = load i64, ptr %ep
  %t = icmp eq i64 %eh, {TOMB}
  br i1 %t, label %next, label %keep
keep:
  %dp = call ptr @__mir_hmap_ent(i64 %h, i64 %j)
  call ptr @memmove(ptr %dp, ptr %ep, i64 %st)
  %jk = add i64 %j, 1
  br label %next
next:
  %j2 = phi i64 [ %j, %body ], [ %jk, %keep ]
  %e2 = add i64 %e, 1
  br label %loop
done:
  call void @__mir_hmap_hst(i64 %h, i64 {USED}, i64 %j)
  %ep0 = call i64 @__mir_hmap_hld(i64 %h, i64 {EPOCH})
  %ep1 = add i64 %ep0, 1
  call void @__mir_hmap_hst(i64 %h, i64 {EPOCH}, i64 %ep1)
  %mask = call i64 @__mir_hmap_hld(i64 %h, i64 {MASK})
  %slots = add i64 %mask, 1
  call void @__mir_hmap_reindex(i64 %h, i64 %slots)
  ret void
}

define i64 @__mir_hmap_alloc(i64 %isSet) {
entry:
  %p = call ptr @calloc(i64 1, i64 {HDR})
  %h = ptrtoint ptr %p to i64
  %f = and i64 %isSet, 1
  call void @__mir_hmap_hst(i64 %h, i64 {FLAGS}, i64 %f)
  call void @__mir_hmap_hst(i64 %h, i64 {CAP}, i64 {MINCAP})
  call void @__mir_hmap_hst(i64 %h, i64 {MASK}, i64 {MINMASK})
  %st = call i64 @__mir_hmap_stride(i64 %h)
  %eb = mul i64 %st, {MINCAP}
  %ep = call ptr @malloc(i64 %eb)
  %ew = ptrtoint ptr %ep to i64
  call void @__mir_hmap_hst(i64 %h, i64 {ENTRIES}, i64 %ew)
  %ip = call ptr @malloc(i64 {MINSLOTS4})
  call ptr @memset(ptr %ip, i32 255, i64 {MINSLOTS4})
  %iw = ptrtoint ptr %ip to i64
  call void @__mir_hmap_hst(i64 %h, i64 {INDEX}, i64 %iw)
  ret i64 %h
}

define void @__mir_hmap_dropent(i64 %h, ptr %ep) {
entry:
  %eh = load i64, ptr %ep
  %t = icmp eq i64 %eh, {TOMB}
  br i1 %t, label %done, label %go
go:
  %kp = getelementptr inbounds i8, ptr %ep, i64 {EKEY}
  %k = load i64, ptr %kp
  call void @__mir_cell_drop(i64 %k)
  %fl = call i64 @__mir_hmap_hld(i64 %h, i64 {FLAGS})
  %iss = and i64 %fl, {FSET}
  %isn = icmp ne i64 %iss, 0
  br i1 %isn, label %done, label %val
val:
  %vp = getelementptr inbounds i8, ptr %ep, i64 {EVAL}
  %v = load i64, ptr %vp
  call void @__mir_cell_drop(i64 %v)
  br label %done
done:
  ret void
}

define void @__mir_hmap_retainent(i64 %h, ptr %ep) {
entry:
  %eh = load i64, ptr %ep
  %t = icmp eq i64 %eh, {TOMB}
  br i1 %t, label %done, label %go
go:
  %kp = getelementptr inbounds i8, ptr %ep, i64 {EKEY}
  %k = load i64, ptr %kp
  call void @__mir_cell_retain(i64 %k)
  %fl = call i64 @__mir_hmap_hld(i64 %h, i64 {FLAGS})
  %iss = and i64 %fl, {FSET}
  %isn = icmp ne i64 %iss, 0
  br i1 %isn, label %done, label %val
val:
  %vp = getelementptr inbounds i8, ptr %ep, i64 {EVAL}
  %v = load i64, ptr %vp
  call void @__mir_cell_retain(i64 %v)
  br label %done
done:
  ret void
}

define i64 @__mir_hmap_detach(i64 %h) {
entry:
  %old = call i64 @__mir_hmap_hld(i64 %h, i64 {ENTRIES})
  %cap = call i64 @__mir_hmap_hld(i64 %h, i64 {CAP})
  %st = call i64 @__mir_hmap_stride(i64 %h)
  %eb = mul i64 %cap, %st
  %np = call ptr @malloc(i64 %eb)
  %nw = ptrtoint ptr %np to i64
  call void @__mir_hmap_hst(i64 %h, i64 {ENTRIES}, i64 %nw)
  call void @__mir_hmap_hst(i64 %h, i64 {USED}, i64 0)
  call void @__mir_hmap_hst(i64 %h, i64 {LEN}, i64 0)
  %ep0 = call i64 @__mir_hmap_hld(i64 %h, i64 {EPOCH})
  %ep1 = add i64 %ep0, 1
  call void @__mir_hmap_hst(i64 %h, i64 {EPOCH}, i64 %ep1)
  %mask = call i64 @__mir_hmap_hld(i64 %h, i64 {MASK})
  %slots = add i64 %mask, 1
  %bytes = shl i64 %slots, 2
  %iw = call i64 @__mir_hmap_hld(i64 %h, i64 {INDEX})
  %ip = inttoptr i64 %iw to ptr
  call ptr @memset(ptr %ip, i32 255, i64 %bytes)
  ret i64 %old
}

define void @__mir_hmap_dropall(i64 %h, i64 %old, i64 %used, i64 %rev) {
entry:
  %base = inttoptr i64 %old to ptr
  %st = call i64 @__mir_hmap_stride(i64 %h)
  %isr = icmp ne i64 %rev, 0
  br label %loop
loop:
  %i = phi i64 [ 0, %entry ], [ %i2, %body ]
  %c = icmp slt i64 %i, %used
  br i1 %c, label %body, label %done
body:
  %r = sub i64 %used, 1
  %ri = sub i64 %r, %i
  %e = select i1 %isr, i64 %ri, i64 %i
  %o = mul i64 %e, %st
  %ep = getelementptr inbounds i8, ptr %base, i64 %o
  call void @__mir_hmap_dropent(i64 %h, ptr %ep)
  %i2 = add i64 %i, 1
  br label %loop
done:
  call void @free(ptr %base)
  ret void
}

define void @__mir_hmap_free(i64 %h) {
entry:
  %z = icmp eq i64 %h, 0
  br i1 %z, label %done, label %go
go:
  %used = call i64 @__mir_hmap_hld(i64 %h, i64 {USED})
  %old = call i64 @__mir_hmap_detach(i64 %h)
  call void @__mir_hmap_dropall(i64 %h, i64 %old, i64 %used, i64 1)
  br label %fr
fr:
  %ew = call i64 @__mir_hmap_hld(i64 %h, i64 {ENTRIES})
  %iw = call i64 @__mir_hmap_hld(i64 %h, i64 {INDEX})
  %ep2 = inttoptr i64 %ew to ptr
  %ip2 = inttoptr i64 %iw to ptr
  %hp = inttoptr i64 %h to ptr
  call void @free(ptr %ep2)
  call void @free(ptr %ip2)
  call void @free(ptr %hp)
  br label %done
done:
  ret void
}

define i64 @__mir_hmap_len(i64 %h) {
entry:
  %v = call i64 @__mir_hmap_hld(i64 %h, i64 {LEN})
  ret i64 %v
}

define i64 @__mir_hmap_epoch(i64 %h) {
entry:
  %v = call i64 @__mir_hmap_hld(i64 %h, i64 {EPOCH})
  ret i64 %v
}

define i64 @__mir_hmap_key(i64 %h, i64 %e) {
entry:
  %ep = call ptr @__mir_hmap_ent(i64 %h, i64 %e)
  %kp = getelementptr inbounds i8, ptr %ep, i64 {EKEY}
  %k = load i64, ptr %kp
  call void @__mir_cell_retain(i64 %k)
  ret i64 %k
}

define i64 @__mir_hmap_val(i64 %h, i64 %e) {
entry:
  %fl = call i64 @__mir_hmap_hld(i64 %h, i64 {FLAGS})
  %iss = and i64 %fl, {FSET}
  %isn = icmp ne i64 %iss, 0
  br i1 %isn, label %set, label %map
set:
  ret i64 {NULL}
map:
  %ep = call ptr @__mir_hmap_ent(i64 %h, i64 %e)
  %vp = getelementptr inbounds i8, ptr %ep, i64 {EVAL}
  %v = load i64, ptr %vp
  call void @__mir_cell_retain(i64 %v)
  ret i64 %v
}

define i64 @__mir_hmap_put(i64 %h, i64 %key, i64 %val) {
entry:
  %hash = call i64 @__mir_hmap_hash(i64 %key)
  %e = call i64 @__mir_hmap_findh(i64 %h, i64 %key, i64 %hash)
  %hit = icmp sge i64 %e, 0
  br i1 %hit, label %upd, label %miss
upd:
  %fl = call i64 @__mir_hmap_hld(i64 %h, i64 {FLAGS})
  %iss = and i64 %fl, {FSET}
  %isn = icmp ne i64 %iss, 0
  br i1 %isn, label %retz, label %upd2
upd2:
  %uep = call ptr @__mir_hmap_ent(i64 %h, i64 %e)
  %uvp = getelementptr inbounds i8, ptr %uep, i64 {EVAL}
  call void @__mir_cell_retain(i64 %val)
  %old = load i64, ptr %uvp
  store i64 %val, ptr %uvp
  call void @__mir_cell_drop(i64 %old)
  br label %retz
retz:
  ret i64 0
miss:
  %used = call i64 @__mir_hmap_hld(i64 %h, i64 {USED})
  %len = call i64 @__mir_hmap_hld(i64 %h, i64 {LEN})
  %ge = icmp sge i64 %used, {MINCAP}
  %tb = sub i64 %used, %len
  %t2 = shl i64 %tb, 1
  %gt = icmp sgt i64 %t2, %len
  %cmpc = and i1 %ge, %gt
  br i1 %cmpc, label %comp, label %grow
comp:
  call void @__mir_hmap_compact(i64 %h)
  br label %grow
grow:
  %cap = call i64 @__mir_hmap_hld(i64 %h, i64 {CAP})
  %used2 = call i64 @__mir_hmap_hld(i64 %h, i64 {USED})
  %full = icmp eq i64 %used2, %cap
  br i1 %full, label %regrow, label %idx
regrow:
  %nc = shl i64 %cap, 1
  %st = call i64 @__mir_hmap_stride(i64 %h)
  %nb = mul i64 %nc, %st
  %ob = call i64 @__mir_hmap_hld(i64 %h, i64 {ENTRIES})
  %obp = inttoptr i64 %ob to ptr
  %np = call ptr @realloc(ptr %obp, i64 %nb)
  %nw = ptrtoint ptr %np to i64
  call void @__mir_hmap_hst(i64 %h, i64 {ENTRIES}, i64 %nw)
  call void @__mir_hmap_hst(i64 %h, i64 {CAP}, i64 %nc)
  br label %idx
idx:
  %used3 = call i64 @__mir_hmap_hld(i64 %h, i64 {USED})
  %u1 = add i64 %used3, 1
  %q = shl i64 %u1, 2
  %m = call i64 @__mir_hmap_hld(i64 %h, i64 {MASK})
  %slots = add i64 %m, 1
  %lim = mul i64 %slots, 3
  %over = icmp sgt i64 %q, %lim
  br i1 %over, label %rein, label %app
rein:
  %ns = shl i64 %slots, 1
  call void @__mir_hmap_reindex(i64 %h, i64 %ns)
  br label %app
app:
  %used4 = call i64 @__mir_hmap_hld(i64 %h, i64 {USED})
  %ep = call ptr @__mir_hmap_ent(i64 %h, i64 %used4)
  store i64 %hash, ptr %ep
  %kp = getelementptr inbounds i8, ptr %ep, i64 {EKEY}
  call void @__mir_cell_retain(i64 %key)
  store i64 %key, ptr %kp
  %fl2 = call i64 @__mir_hmap_hld(i64 %h, i64 {FLAGS})
  %iss2 = and i64 %fl2, {FSET}
  %isn2 = icmp ne i64 %iss2, 0
  br i1 %isn2, label %fin, label %wval
wval:
  %vp = getelementptr inbounds i8, ptr %ep, i64 {EVAL}
  call void @__mir_cell_retain(i64 %val)
  store i64 %val, ptr %vp
  br label %fin
fin:
  call void @__mir_hmap_ixins(i64 %h, i64 %hash, i64 %used4)
  %nu = add i64 %used4, 1
  call void @__mir_hmap_hst(i64 %h, i64 {USED}, i64 %nu)
  %l0 = call i64 @__mir_hmap_hld(i64 %h, i64 {LEN})
  %l1 = add i64 %l0, 1
  call void @__mir_hmap_hst(i64 %h, i64 {LEN}, i64 %l1)
  ret i64 1
}

define i64 @__mir_hmap_del(i64 %h, i64 %key) {
entry:
  %hash = call i64 @__mir_hmap_hash(i64 %key)
  %e = call i64 @__mir_hmap_findh(i64 %h, i64 %key, i64 %hash)
  %hit = icmp sge i64 %e, 0
  br i1 %hit, label %go, label %miss
miss:
  ret i64 0
go:
  %ep = call ptr @__mir_hmap_ent(i64 %h, i64 %e)
  %dkp = getelementptr inbounds i8, ptr %ep, i64 {EKEY}
  %dk = load i64, ptr %dkp
  %dfl = call i64 @__mir_hmap_hld(i64 %h, i64 {FLAGS})
  %diss = and i64 %dfl, {FSET}
  %disn = icmp ne i64 %diss, 0
  br i1 %disn, label %dset, label %dmap
dmap:
  %dvp = getelementptr inbounds i8, ptr %ep, i64 {EVAL}
  %dmv = load i64, ptr %dvp
  br label %dgo
dset:
  br label %dgo
dgo:
  %dv = phi i64 [ %dmv, %dmap ], [ {NULL}, %dset ]
  store i64 {TOMB}, ptr %ep
  %l0 = call i64 @__mir_hmap_hld(i64 %h, i64 {LEN})
  %l1 = sub i64 %l0, 1
  call void @__mir_hmap_hst(i64 %h, i64 {LEN}, i64 %l1)
  %mask = call i64 @__mir_hmap_hld(i64 %h, i64 {MASK})
  %ixw = call i64 @__mir_hmap_hld(i64 %h, i64 {INDEX})
  %ixp = inttoptr i64 %ixw to ptr
  %s0 = and i64 %hash, %mask
  br label %find
find:
  %s = phi i64 [ %s0, %dgo ], [ %s2, %fnext ]
  %fp = getelementptr inbounds i32, ptr %ixp, i64 %s
  %fl = load i32, ptr %fp
  %fe = zext i32 %fl to i64
  %feq = icmp eq i64 %fe, %e
  br i1 %feq, label %sh, label %fnext
fnext:
  %s1 = add i64 %s, 1
  %s2 = and i64 %s1, %mask
  br label %find
sh:
  %i = phi i64 [ %s, %find ], [ %i, %keep ], [ %j2, %mv ]
  %j = phi i64 [ %s, %find ], [ %j2, %keep ], [ %j2, %mv ]
  %j1 = add i64 %j, 1
  %j2 = and i64 %j1, %mask
  %sp = getelementptr inbounds i32, ptr %ixp, i64 %j2
  %sl = load i32, ptr %sp
  %emp = icmp eq i32 %sl, -1
  br i1 %emp, label %done, label %ex
ex:
  %se = zext i32 %sl to i64
  %sep = call ptr @__mir_hmap_ent(i64 %h, i64 %se)
  %eh = load i64, ptr %sep
  %k = and i64 %eh, %mask
  %le = icmp ule i64 %i, %j2
  %a = icmp ult i64 %i, %k
  %b = icmp ule i64 %k, %j2
  %and = and i1 %a, %b
  %or = or i1 %a, %b
  %inr = select i1 %le, i1 %and, i1 %or
  br i1 %inr, label %keep, label %mv
keep:
  br label %sh
mv:
  %dp = getelementptr inbounds i32, ptr %ixp, i64 %i
  store i32 %sl, ptr %dp
  br label %sh
done:
  %ip = getelementptr inbounds i32, ptr %ixp, i64 %i
  store i32 -1, ptr %ip
  call void @__mir_cell_drop(i64 %dk)
  call void @__mir_cell_drop(i64 %dv)
  ret i64 1
}

define i64 @__mir_hmap_next(i64 %h, i64 %e0) {
entry:
  %used = call i64 @__mir_hmap_hld(i64 %h, i64 {USED})
  br label %loop
loop:
  %e = phi i64 [ %e0, %entry ], [ %e1, %nx ]
  %c = icmp slt i64 %e, %used
  br i1 %c, label %body, label %miss
body:
  %ep = call ptr @__mir_hmap_ent(i64 %h, i64 %e)
  %eh = load i64, ptr %ep
  %t = icmp eq i64 %eh, {TOMB}
  br i1 %t, label %nx, label %hit
nx:
  %e1 = add i64 %e, 1
  br label %loop
hit:
  ret i64 %e
miss:
  ret i64 -1
}

define void @__mir_hmap_clear(i64 %h) {
entry:
  %used = call i64 @__mir_hmap_hld(i64 %h, i64 {USED})
  %old = call i64 @__mir_hmap_detach(i64 %h)
  call void @__mir_hmap_dropall(i64 %h, i64 %old, i64 %used, i64 0)
  ret void
}

define i64 @__mir_hmap_clone(i64 %h) {
entry:
  %np = call ptr @calloc(i64 1, i64 {HDR})
  %nh = ptrtoint ptr %np to i64
  %op = inttoptr i64 %h to ptr
  call ptr @memcpy(ptr %np, ptr %op, i64 {HDR})
  %used = call i64 @__mir_hmap_hld(i64 %h, i64 {USED})
  %cap = call i64 @__mir_hmap_hld(i64 %h, i64 {CAP})
  %mask = call i64 @__mir_hmap_hld(i64 %h, i64 {MASK})
  %st = call i64 @__mir_hmap_stride(i64 %h)
  %eb = mul i64 %cap, %st
  %ub = mul i64 %used, %st
  %nep = call ptr @malloc(i64 %eb)
  %oew = call i64 @__mir_hmap_hld(i64 %h, i64 {ENTRIES})
  %oep = inttoptr i64 %oew to ptr
  call ptr @memcpy(ptr %nep, ptr %oep, i64 %ub)
  %new = ptrtoint ptr %nep to i64
  call void @__mir_hmap_hst(i64 %nh, i64 {ENTRIES}, i64 %new)
  %slots = add i64 %mask, 1
  %ib = shl i64 %slots, 2
  %nip = call ptr @malloc(i64 %ib)
  %oiw = call i64 @__mir_hmap_hld(i64 %h, i64 {INDEX})
  %oip = inttoptr i64 %oiw to ptr
  call ptr @memcpy(ptr %nip, ptr %oip, i64 %ib)
  %niw = ptrtoint ptr %nip to i64
  call void @__mir_hmap_hst(i64 %nh, i64 {INDEX}, i64 %niw)
  br label %loop
loop:
  %e = phi i64 [ 0, %entry ], [ %e2, %body ]
  %c = icmp slt i64 %e, %used
  br i1 %c, label %body, label %done
body:
  %ep = call ptr @__mir_hmap_ent(i64 %nh, i64 %e)
  call void @__mir_hmap_retainent(i64 %nh, ptr %ep)
  %e2 = add i64 %e, 1
  br label %loop
done:
  ret i64 %nh
}
';
        /** @var array<string, string> $sub */
        $sub = [
            '{LEN}' => (string)\Compile\MemoryAbi::HMAP_LEN_OFFSET,
            '{USED}' => (string)\Compile\MemoryAbi::HMAP_USED_OFFSET,
            '{CAP}' => (string)\Compile\MemoryAbi::HMAP_CAP_OFFSET,
            '{MASK}' => (string)\Compile\MemoryAbi::HMAP_MASK_OFFSET,
            '{FLAGS}' => (string)\Compile\MemoryAbi::HMAP_FLAGS_OFFSET,
            '{EPOCH}' => (string)\Compile\MemoryAbi::HMAP_EPOCH_OFFSET,
            '{ENTRIES}' => (string)\Compile\MemoryAbi::HMAP_ENTRIES_OFFSET,
            '{INDEX}' => (string)\Compile\MemoryAbi::HMAP_INDEX_OFFSET,
            '{FSET}' => (string)\Compile\MemoryAbi::HMAP_FLAG_SET,
            '{HDR}' => (string)\Compile\MemoryAbi::HMAP_HEADER_SIZE,
            '{EKEY}' => (string)\Compile\MemoryAbi::HMAP_ENTRY_KEY,
            '{EVAL}' => (string)\Compile\MemoryAbi::HMAP_ENTRY_VAL,
            '{ESM}' => (string)\Compile\MemoryAbi::HMAP_ENTRY_SIZE_MAP,
            '{ESD}' => (string)(\Compile\MemoryAbi::HMAP_ENTRY_SIZE_MAP - \Compile\MemoryAbi::HMAP_ENTRY_SIZE_SET),
            '{TOMB}' => (string)\Compile\MemoryAbi::HMAP_TOMB_HASH,
            '{MINCAP}' => (string)\Compile\MemoryAbi::HMAP_MIN_CAP,
            '{MINMASK}' => (string)(\Compile\MemoryAbi::HMAP_MIN_CAP * 2 - 1),
            '{MINSLOTS4}' => (string)(\Compile\MemoryAbi::HMAP_MIN_CAP * 2 * 4),
            '{PMASK}' => (string)\Compile\MemoryAbi::CELL_PAYLOAD_MASK,
            '{NULL}' => (string)\Compile\MemoryAbi::CELL_NULL,
        ];
        foreach ($sub as $from => $to) { $ir = \str_replace($from, $to, $ir); }
        return $ir;
    }

    /**
     * The shape of the `__mc_nbuf_<op>` builtin: one letter per operand
     * (i int, f float, c cell), then the result (v void). '' — not one.
     */
    public static function nbufSig(string $op): string
    {
        if ($op === 'get_i' || $op === 'alloc' || $op === 'resize' || $op === 'reduce_i' || $op === 'same') { return 'iii'; }
        if ($op === 'set_i' || $op === 'remove') { return 'iiiv'; }
        if ($op === 'get_f' || $op === 'reduce_f' || $op === 'bits_f') { return 'iif'; }
        if ($op === 'f_bits') { return 'fii'; }
        if ($op === 'peek') { return 'iiiii'; }
        if ($op === 'poke') { return 'iiiiiv'; }
        if ($op === 'set_f') { return 'iifv'; }
        if ($op === 'get_c') { return 'iic'; }
        if ($op === 'set_c') { return 'iicv'; }
        if ($op === 'len' || $op === 'clone') { return 'ii'; }
        if ($op === 'free') { return 'iv'; }
        if ($op === 'insert' || $op === 'find_i') { return 'iiii'; }
        if ($op === 'find_f') { return 'ifii'; }
        if ($op === 'move' || $op === 'fill_i') { return 'iiiiv'; }
        if ($op === 'fill_f') { return 'ifiiv'; }
        if ($op === 'copy') { return 'iiiiiv'; }
        return '';
    }

    /**
     * The shape of the `__mc_hmap_<op>` builtin, in {@see nbufSig}'s letters.
     */
    public static function hmapSig(string $op): string
    {
        if ($op === 'alloc' || $op === 'len' || $op === 'epoch' || $op === 'clone') { return 'ii'; }
        if ($op === 'free' || $op === 'clear') { return 'iv'; }
        if ($op === 'find' || $op === 'del') { return 'ici'; }
        if ($op === 'key' || $op === 'val') { return 'iic'; }
        if ($op === 'put') { return 'icci'; }
        if ($op === 'next') { return 'iii'; }
        return '';
    }
}
