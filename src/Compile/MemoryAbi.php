<?php

namespace Compile;

/**
 * Single source of truth for object / assoc memory layout, refcount
 * encoding, and cycle-collector state bits. Every direct GEP offset
 * in the codegen flows through one of these constants.
 *
 * Versioning: bump {@see MemoryAbi::VERSION} whenever any layout
 * here changes. The version constant is exposed via `manticore
 * version` so out-of-tree consumers can detect mismatches.
 *
 * Design notes: `docs/bootstrap/12-memory-abi-contract.md`.
 */
final class MemoryAbi
{
    /**
     * Bump on any layout / encoding change. Drives the
     * `bin/manticore version` output so vendored artefacts can
     * detect mismatches against a fresh build.
     */
    public const VERSION = 5;

    // ─── rc self-routing tag (obj/vec only) ───────────────────────

    /**
     * obj/vec allocations carry an 8-byte tag at `ptr-8` holding
     * {@see RC_TAG_MAGIC}; the data ptr is `malloc_base + 8`. The rc
     * helpers read `ptr-8`: the magic ⇒ obj/vec (rc at `ptr+8`),
     * anything else ⇒ the ptr is a string (whose own rc lives at
     * `ptr-8`, always a small count or -1, never the magic). This lets
     * `__mir_rc_retain` / `_release` SELF-ROUTE regardless of the
     * static type the call site guessed — killing the string↔obj/vec
     * misroute (the enum/arena heisenbug). Strings/assoc are untouched.
     */
    public const RC_TAG_OFFSET = -8;

    /**
     * Sentinel at obj/vec `ptr-8`. Chosen far above any real refcount
     * (`< 2^56`, {@see RC_MASK}) and distinct from the immortal -1, so a
     * string's rc word can never collide with it.
     */
    public const RC_TAG_MAGIC = 0x7E66000000000000;

    /**
     * Distinct sentinel at assoc `ptr-8`. Assoc allocations are tagged
     * via `__mir_alloc_assoc_tagged` (data ptr = `malloc_base + 8`), so
     * this word is always the assoc's own reserved tag — reading
     * `ptr-8` is never out-of-bounds. One greater than
     * {@see RC_TAG_MAGIC} so the two container kinds are positively
     * distinguishable: a vec/obj wrongly routed into an assoc rc helper
     * carries {@see RC_TAG_MAGIC} here (NOT this), so the helper bails
     * before an OOB rc access (the empty-`[]`→vec misroute, the latent
     * `assoc_retain`-on-a-vec UAF).
     */
    public const ASSOC_TAG_MAGIC = 0x7E66000000000001;

    // ─── String header (24 bytes) ─────────────────────────────────

    /**
     * Heap string layout: `[cap@-24, len@-16, rc@-8, bytes@0]`. The data ptr
     * is `malloc_base + 24`. Strings are BINARY-SAFE: `len` is the content
     * length and the single source of truth — `strlen()` / comparison read it,
     * NOT libc `strlen` (which stops at `\0`). A trailing NUL is kept only as a
     * convenience for libc interop (paths, printf `%s`). `rc@-8` and `bytes@0`
     * are unchanged so the rc/free string-vs-obj routing (it reads `ptr-8`)
     * stays valid; only the FREE base moves to `p-24`. `cap` is the byte
     * capacity of the data region (content + NUL); amortized `.=` appends in
     * place when `rc==1` and `len+addlen < cap`. Immortal strings (literals,
     * arena) carry rc=-1; literals carry a full header so `len` reads are valid.
     */
    public const STRING_HEADER_SIZE = 24;

    /** Byte capacity of the data region, at `data_ptr - 24`. */
    public const STRING_CAP_OFFSET = -24;

    /** Content length (binary-safe), at `data_ptr - 16`. */
    public const STRING_LEN_OFFSET = -16;

    /** Refcount word, at `data_ptr - 8` (small count, or -1 immortal). */
    public const STRING_RC_OFFSET = -8;

    // ─── Object header (16 bytes) ─────────────────────────────────

    /** Non-`#[Struct]` instances reserve this much before properties. */
    public const OBJECT_HEADER_SIZE = 16;

    /**
     * `ptr` — the class DESCRIPTOR (`@__mir_cd_<id> = { i64 class_id,
     * ptr drop_fn }`), NOT the raw id. instanceof / method dispatch /
     * exception catch read `class_id` at descriptor offset 0; object
     * release calls `drop_fn` (descriptor offset 8) INDIRECTLY. The
     * descriptor is `linkonce_odr`, so each class has one across every
     * separately-linked object — drops/dispatch compose without a central
     * id-switch that could lose a case (the residual cross-object drop leak).
     */
    public const OBJECT_DESCRIPTOR_OFFSET = 0;

    /** `i64` — class id, read at descriptor offset 0. Never 0. */
    public const DESCRIPTOR_CLASS_ID_OFFSET = 0;

    /** `ptr` — class drop function (or null), at descriptor offset 8. */
    public const DESCRIPTOR_DROP_FN_OFFSET = 8;

    /** `i64` — packed `rc | color | buffered`; see {@see RC_MASK}. */
    public const OBJECT_RC_WORD_OFFSET = 8;

    // ─── Vec-array header (8 bytes today, 16 after Step C) ───────

    /**
     * Today: length only (8 bytes). Step C bumps to 16 (length +
     * rc) once every unaccounted vec-byte-offset call site (raw IR
     * builds in BuiltinDispatch, array_keys / array_values, etc.)
     * routes through these constants. Until then the bump triggers
     * intermittent SIGSEGVs from the slots that still bake `+1`.
     */
    public const VEC_HEADER_SIZE = 16;

    /** `i64` — element count (entries, not buffer slots). */
    public const VEC_LENGTH_OFFSET = 0;

    /**
     * `i64` — refcount. Lives at offset 8 once Step C lands; today
     * the slot doesn't exist (`VEC_HEADER_SIZE` is still 8). Kept
     * here so call-sites can reference the eventual location while
     * the layout flip is in flight.
     */
    public const VEC_RC_OFFSET = 8;

    /** Each element is one i64 slot. */
    public const VEC_ELEMENT_SIZE = 8;

    // ─── Assoc-array header (48 bytes) ────────────────────────────

    /**
     * Pre-entry bytes. Stub (cap=0) instances skip the rc slot.
     *
     * Bumped 32 → 48 to carry a lazy hash index ({@see
     * ASSOC_NBUCKETS_OFFSET} / {@see ASSOC_BUCKETS_PTR_OFFSET}).
     * The first 32 bytes are unchanged, so every existing
     * fixed-offset header read (length / capacity / next_int / rc)
     * stays valid; only the entry base shifts, and that is computed
     * from this constant everywhere. `n_buckets == 0` means "index
     * not built yet" → the helpers fall back to the linear scan,
     * which keeps correctness even if some alloc path forgets to
     * populate the index.
     */
    public const ASSOC_HEADER_SIZE = 48;

    /** `i64` — populated entry count. */
    public const ASSOC_LENGTH_OFFSET = 0;

    /** `i64` — entry slots allocated. cap=0 ⇒ no rc field. */
    public const ASSOC_CAPACITY_OFFSET = 8;

    /** `i64` — next implicit integer key (for `$arr[] =`). */
    public const ASSOC_NEXT_INT_KEY_OFFSET = 16;

    /** `i64` — plain refcount. Not yet packed with color bits. */
    public const ASSOC_RC_OFFSET = 24;

    /**
     * `i64` — number of hash buckets (always a power of two), or 0
     * when the index has not been built. Lookups consult the index
     * only when this is non-zero.
     */
    public const ASSOC_NBUCKETS_OFFSET = 32;

    /**
     * `ptr` — side allocation of `n_buckets` i64 slots. Each slot
     * holds `entry_index + 1` (0 = empty) for open-addressed,
     * linear-probed lookup. Freed alongside the buffer in
     * `__manticore_assoc_release`; rebuilt on copy in
     * `__manticore_assoc_cow`.
     */
    public const ASSOC_BUCKETS_PTR_OFFSET = 40;

    /**
     * Build / grow the index once an assoc reaches this many live
     * entries. Below the threshold the linear scan is faster than
     * hashing + the bucket allocation, so small maps stay flat.
     */
    public const ASSOC_INDEX_THRESHOLD = 8;

    /**
     * Size of the 16-byte stub buffer an empty `[]` literal
     * allocates: `[length@0, capacity@8]` with capacity = 0. The
     * first `$arr[k] = v` reallocs the stub up to a real assoc
     * (see `__manticore_assoc_set`'s upgrade path). Distinct from
     * the full header — a stub has no rc / next_int / index slots.
     */
    public const ASSOC_STUB_SIZE = 16;

    /** Each entry tuple: kind (i64) | key (ptr) | value (i64). */
    public const ASSOC_ENTRY_SIZE = 24;

    public const ASSOC_ENTRY_KIND_OFFSET = 0;
    public const ASSOC_ENTRY_KEY_OFFSET = 8;
    public const ASSOC_ENTRY_VALUE_OFFSET = 16;

    /** Entry-kind tag values stored at `ASSOC_ENTRY_KIND_OFFSET`. */
    public const ASSOC_KIND_STRING = 0;
    public const ASSOC_KIND_INT = 1;
    public const ASSOC_KIND_DELETED = -1;

    // ─── Unified PhpArray (Phase 3, docs/bootstrap/16) ────────────

    /**
     * Distinct sentinel at unified-array `ptr-8`. One greater than
     * {@see ASSOC_TAG_MAGIC} so all three container kinds (obj/vec,
     * assoc, unified-array) are positively distinguishable at the
     * shared rc-routing word. Behind `--array=unified` only; while both
     * runtimes coexist (Stages 1–3) the unified array is a THIRD tag,
     * replacing the vec+assoc tags at Stage 4. See
     * `docs/bootstrap/16-unified-phparray-design.md`.
     */
    public const ARRAY_TAG_MAGIC = 0x7E66000000000002;

    /**
     * Sentinel at `ptr-8` for an ARENA-allocated unified array (non-escaping,
     * bump-allocated, bulk-freed at scope exit). Distinct from
     * {@see ARRAY_TAG_MAGIC} so the rc helpers ({@see \Compile\Runtime\
     * UnifiedArrayRuntime} retain/release, which proceed ONLY on the heap
     * magic) bail immediately — an arena array is never rc-bumped or freed by
     * `free()`; the arena reclaims it. The grow / promote / index paths
     * detect this tag and route to the arena allocator instead of libc.
     * Behind `Debug::$arenaArrays` only. One greater than the heap tag.
     */
    public const ARRAY_TAG_ARENA = 0x7E66000000000003;

    /**
     * ONE header for every array, packed or hashed (56 bytes). The
     * structural heisenbug kill: rc lives at {@see ARRAY_RC_OFFSET} for
     * EVERY array regardless of mode, so a value can never have its rc
     * read at the wrong offset. A dedicated flags word holds the
     * PACKED/HASHED mode; nbuckets/buckets are reserved for the lazy
     * hash index (Stage 1 uses a linear scan in HASHED mode).
     */
    public const ARRAY_HEADER_SIZE = 56;

    /** `i64` — live element count (packed slots or hashed entries). */
    public const ARRAY_LENGTH_OFFSET = 0;

    /** `i64` — slot capacity (i64 slots if packed; entry slots if hashed). */
    public const ARRAY_CAPACITY_OFFSET = 8;

    /** `i64` — next implicit integer key (for `$a[] =`). */
    public const ARRAY_NEXT_INT_OFFSET = 16;

    /** `i64` — refcount. SINGLE FIXED OFFSET for all arrays. */
    public const ARRAY_RC_OFFSET = 24;

    /**
     * `i64` — mode/flags word. Bit 0 ({@see ARRAY_FLAG_HASHED}) = the
     * buffer is in HASHED mode (24-byte entries); cleared = PACKED mode
     * (contiguous i64 values, implicit int keys). Higher bits reserved.
     */
    public const ARRAY_FLAGS_OFFSET = 32;

    /** `i64` — number of hash buckets (0 = index not built). Reserved. */
    public const ARRAY_NBUCKETS_OFFSET = 40;

    /** `ptr` — hash bucket side-array; null until the index is built. */
    public const ARRAY_BUCKETS_PTR_OFFSET = 48;

    /** Bit 0 of the flags word: HASHED mode. Cleared ⇒ PACKED. */
    public const ARRAY_FLAG_HASHED = 1;

    /** PACKED mode: each value is one i64 slot at data+i*8. */
    public const ARRAY_PACKED_ELEMENT_SIZE = 8;

    /** HASHED mode: entry tuple kind (i64) | key (ptr/i64) | value (i64). */
    public const ARRAY_ENTRY_SIZE = 24;
    public const ARRAY_ENTRY_KIND_OFFSET = 0;
    public const ARRAY_ENTRY_KEY_OFFSET = 8;
    public const ARRAY_ENTRY_VALUE_OFFSET = 16;

    /** Entry-kind tags at {@see ARRAY_ENTRY_KIND_OFFSET}. */
    public const ARRAY_KIND_STRING = 0;
    public const ARRAY_KIND_INT = 1;
    public const ARRAY_KIND_DELETED = -1;

    // ─── rc_word packing (object header offset 8) ─────────────────

    /**
     * Low 56 bits hold the signed rc value. Trial-deletion (the
     * Bacon-Rajan cycle collector) can drive it negative; reads
     * sign-extend from bit 55.
     */
    public const RC_MASK = 0x00FFFFFFFFFFFFFF;

    /** Bits 56-62 hold the 7-bit color. */
    public const COLOR_MASK = 0x7F00000000000000;

    /** Bit 63 is the `buffered` (cc candidate list membership) flag. */
    public const BUFFERED_MASK = \PHP_INT_MIN;

    /** Shift to land color in bits 0..6 (after a mask). */
    public const COLOR_SHIFT = 56;

    /** Mask that preserves rc + buffered, clears color. */
    public const COLOR_CLEAR_MASK = ~self::COLOR_MASK;

    /** Mask that preserves rc + color, clears buffered. */
    public const BUFFERED_CLEAR_MASK = \PHP_INT_MAX;

    // ─── Color values (Bacon-Rajan) ───────────────────────────────

    public const COLOR_BLACK = 0;
    public const COLOR_PURPLE = 1;
    public const COLOR_GRAY = 2;
    public const COLOR_WHITE = 3;
}
