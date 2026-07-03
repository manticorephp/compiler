# Runtime

PHP-side runtime support compiled into every output binary: libc FFI
bindings, the built-in `stdClass`, a native JSON encoder/decoder, and
pure-PHP reimplementations of common PHP std-functions. No Rust runtime,
no external libs — everything builds on the same compile-time-FFI
mechanism the compiler uses internally.

This stdlib is **bundled**: built once into a prebuilt
`lib/manticore_stdlib.o` (+ a `.sig` module-interface sidecar) that is
linked into user programs, and the same sources are embedded in the
self-contained compiler.

## Layout

- `Libc.php` — namespaced `Runtime\Libc\*` functions, each a thin
  `#[Library('c'), Symbol(…)]` extern decl. Memory (`malloc`, `calloc`,
  `realloc`, `free`, `memcpy`, `memset`, `memcmp`, `memchr`),
  NUL-terminated strings (`strlen`, `strcmp`, `strncmp`, `strcasecmp`,
  `strncasecmp`, `strchr`, `strrchr`, `strstr`, `strdup`, `strncpy`,
  `strcpy`, `strcat`), stdio (`puts`, `write`, `read`), and
  files/filesystem (`fopen`, `fclose`, `fread`, `fwrite`, `fseek`,
  `ftell`, `access`, `sys_unlink`, `sys_getcwd`).
- `Json.php` — global-namespace `json_encode` / `json_decode`. A
  `json_encode($x)` call is lowered to the native single-buffer codegen
  builtin `@__mir_json_enc` (a recursive cell walk into one growing
  buffer — see `EmitLlvmBuiltins::jsonEncRuntime`); the PHP walker
  `__mc_json_enc` (null/bool/int/float/string/object/array) stays as the
  reference and the object-cell fallback. `json_decode` delegates to
  `Runtime\Json\Parser`.
- `Json/Parser.php` — `Runtime\Json\Parser`, a recursive-descent JSON
  parser. Position is **instance state** (`$pos` field), not a by-ref
  param, because the self-host compiler drops writes through `&$pos`
  across recursive calls. Objects → assoc arrays, arrays → vecs;
  scalars → int/float/string/bool/null.
- `stdClass.php` — the built-in empty class, `#[Struct,
  AllowDynamicProperties]`. Declared here (not synthesised in the
  lowering pass) so both backends register a real `stdClass` that
  `(object)` casts and dynamic-property stores can name.
- `Stdlib/` — global-namespace PHP std-function reimplementations
  (`Arrays.php`, `Ctype.php`, `Gc.php`, `Io.php`, `Strings.php`). See
  `Stdlib/README.md` for the per-file surface + invariants.

## Public surface (global namespace)

| Group | Functions |
|-------|-----------|
| JSON | `json_encode`, `json_decode($json, $associative = true)` |
| File / OS (`Stdlib/Io.php`) | `file_get_contents`, `file_put_contents`, `file_exists`, `is_readable`, `unlink`, `getcwd` |
| Strings (`Stdlib/Strings.php`) | `str_starts_with`, `str_ends_with`, `str_contains`, `ltrim`, `rtrim`, `trim`, `strpos`, `strrpos`, `str_replace`, `explode` |
| Arrays (`Stdlib/Arrays.php`) | `in_array`, `array_key_exists`, `array_keys`, `array_values`, `array_merge`, `array_slice`, `reset`, `end` (`array_map`/`array_filter` → `prelude/array_fns.php`) |
| Ctype (`Stdlib/Ctype.php`) | `ctype_alnum/alpha/cntrl/digit/lower/upper/graph/print/punct/space/xdigit` |
| GC (`Stdlib/Gc.php`) | `gc_enabled`, `gc_enable`, `gc_disable`, `gc_collect_cycles`, `gc_mem_caches` |
| `stdClass` | the built-in empty class |

`Runtime\Libc\*` is the only non-global namespace here; everything else
lives in the global namespace so an unqualified user call
(`str_starts_with(…)`, `json_decode(…)`) resolves straight to it.

## Invariants

- `Runtime\Libc\*` functions have empty bodies — the compiler treats the
  `#[Library, Symbol]` pair as an extern decl and emits a direct call.
- `Runtime\Libc\strstr` returns `int` (raw address), not `Ptr` — NULL
  check is `=== 0`, no wrap-object path needed for the null case.
- `Give` / `Take` markers in `Libc.php` are ownership hints (see
  `Ffi/Ownership.php`). `Give` on `malloc`/`calloc`/`realloc`/`strdup`/
  `fopen`/`sys_getcwd`; `Take` on `free`/`realloc`/`fclose`.
- A libc buffer from `calloc` has no rc header, so a raw read is copied
  into a real (rc-headered) string via `substr($buf, 0, $len)` before
  returning (the `Io.php` file/`getcwd` path).
- `Stdlib/*` and the JSON / file functions live in the **global
  namespace** (no `namespace` decl) and fire only when the compiler's
  inline `tryCompileBuiltin` table does NOT handle the call. The inline
  path always wins; these catch the fall-through.
- `json_decode`'s `$associative` flag is accepted for PHP compatibility
  but **ignored** — it always returns arrays (no `stdClass`), which is
  what the manifest reader and config consumers want. The parser is not
  a strict validator: malformed input degrades, it does not throw.
- `strpos` / `strrpos` return **`-1`** for "not found" (not PHP's
  `false`) — compiler union-typing for return values is still in flight;
  callers wanting PHP-strict semantics wrap. `strpos` also treats a
  null haystack/needle as no-match (self-host `?string`→`string`
  coercion robustness).
- `str_replace` / `explode` inline a `memcmp` scan instead of calling
  `strpos`: the MIR `strpos` builtin returns a tagged `int|false` cell,
  so `$hit < 0` arithmetic on it would misread the boxed carrier and
  treat every match as a miss. Both also use chunk-based output (one
  `substr`/concat per match, not per byte) to stay linear under a
  runtime that frees nothing.
- `in_array` today supports only string-needle / string-haystack — the
  only shape the bootstrap exercises. Both sides go through libc
  `strcmp` (byte compare, not pointer identity).
- `array_key_last` deliberately omitted: PHP returns `int|string|null`,
  the compiler doesn't track that union, and current call sites need an
  `int` key — re-adding with a fixed return type would silently
  miscompile.
- `ctype_*`: empty string → `false` for every predicate; an int arg in
  `[-128, 255]` is treated as a single byte (signed wrap via `+256`),
  ints outside that range are coerced to decimal-string then scanned.
- `gc_collect_cycles` is shadowed by a compiler builtin (the AOT path
  emits `@__manticore_cc_collect_cycles`, the real Bacon-Rajan
  collector); the PHP body here is the interpreter/fallback stub.
  `gc_enable`/`gc_disable`/`gc_mem_caches` are no-ops in AOT mode.
- Calls into `Runtime\Libc\*` from the global-namespace files use
  fully-qualified names because `use function` isn't aliased into a
  per-file table yet.

## Usage

Direct libc:

```php
$n   = \Runtime\Libc\strlen($s);
$cmp = \Runtime\Libc\memcmp($a, $b, $n);
```

JSON, file, and std functions resolve automatically via PHP's global
function lookup:

```php
$cfg  = json_decode(file_get_contents('manticore.json'));
$json = json_encode($cfg);
```
