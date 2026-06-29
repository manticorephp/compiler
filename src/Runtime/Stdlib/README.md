# Runtime\Stdlib

Pure-PHP reimplementations of PHP std-functions. Global namespace, no
dependencies on extension code. Compiled into every binary; resolves
through PHP's global function lookup so user code can call them by
their canonical PHP names.

## Files

- `Arrays.php` — `in_array`, `array_key_exists`, `array_keys`, `array_values`,
  `array_merge`, `array_slice`, `reset`, `end`. (`array_map` / `array_filter`
  moved to `prelude/array_fns.php` — a callback can't cross the stdlib.o
  boundary.)
- `Ctype.php` — `ctype_alnum`, `ctype_alpha`, `ctype_cntrl`, `ctype_digit`,
  `ctype_lower`, `ctype_upper`, `ctype_graph`, `ctype_print`, `ctype_punct`,
  `ctype_space`, `ctype_xdigit`. Internal `__manticore_ctype_to_bytes`,
  `__manticore_ctype_all`.
- `Gc.php` — `gc_enabled`, `gc_enable`, `gc_disable`, `gc_collect_cycles`,
  `gc_mem_caches`. Only `gc_collect_cycles` is non-trivial — drives the
  Bacon-Rajan cycle collector.
- `Strings.php` — `str_starts_with`, `str_ends_with`, `str_contains`,
  `ltrim`, `rtrim`, `trim`, `strpos`, `strrpos`, `str_replace`, `explode`.
  Internal `__mask_has_byte`.

## Invariants

- Functions live in the **global namespace** so a user `str_starts_with($a, $b)`
  resolves directly here.
- Compiler `tryCompileBuiltin` fires first for inlinable builtins; these
  PHP-level versions catch the fall-through path.
- `strpos` / `strrpos` return **`-1`** for "not found", not PHP's `false`.
  Compiler union-typing for return values is still in flight; callers
  wanting PHP-strict semantics wrap.
- Empty-string predicates match Zend: `ctype_digit('')` → `false`,
  `str_starts_with('', '')` → `true`.
- Integer args to `ctype_*` in `[-128, 255]` treated as a single byte
  (signed wrap via `+256`); ints outside that range coerced to
  decimal-string then scanned.
- `in_array` today is string-needle / string-haystack only.
- `array_key_last` deliberately omitted: PHP returns `int|string|null`,
  the compiler doesn't track that union.
- `gc_collect_cycles` calls `__manticore_cc_collect_cycles()` — a runtime
  helper emitted by `AssocHelpers::emitCcAlgorithmHelpers()` (see
  `docs/bootstrap/11-cycle-collector-design.md`).
