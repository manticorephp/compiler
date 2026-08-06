# Runtime\Stdlib

Pure-PHP reimplementations of PHP std-functions. Global namespace, no dependency
on extension code. Compiled once into `lib/manticore_stdlib.o` and auto-linked
into every binary; the accompanying `.o.sig` exposes them to user programs with
no import and no registration.

## Files

33 files, grouped by surface:

| Group | Files |
|---|---|
| Arrays | `Arrays.php` |
| Strings | `Strings.php`, `StringsExtra.php`, `NatCompare.php`, `Format.php` (the `printf` family), `Scanf.php`, `Encoding.php` |
| Character classes | `Ctype.php` |
| Regex | `Pcre.php` (the `preg_*` family over host PCRE2) |
| Math / random | `MathExtra.php`, `Random.php` |
| Date & time | `Time.php`, `DateCivil.php`, `DateFormat.php`, `DateFuncs.php`, `DateOps.php`, `DateParse.php`, `TzIf.php`, `TzInfo.php` |
| Filesystem | `Io.php`, `Fs.php`, `FsRest.php`, `Stat.php`, `Path.php` |
| Structured formats | `Csv.php`, `Ini.php` |
| Networking | `Net.php`, `Sockets.php`, `Dns.php` |
| Hashing | `Hash.php` |
| Process | `Pcntl.php` |
| Misc | `Gc.php`, `VarExtra.php` |

`Io.php` (64 KB), `Net.php` (95 KB) and `Sockets.php` (50 KB) are the three that
carry most of the surface; start there when looking for a stream or socket
function.

## Invariants

- Functions live in the **global namespace** so a user `str_starts_with($a, $b)`
  resolves directly here.
- The compiler's `tryCompileBuiltin` fires first for inlinable builtins; these
  PHP-level versions catch the fall-through path. A function can therefore exist
  in both tiers — the codegen builtin wins.
- **Signatures are php-faithful.** `strpos` / `strrpos` return `int|false`, not a
  `-1` sentinel; `preg_match` returns `int|false`. Do not "simplify" a return
  type to dodge a union. (They once returned `-1`; code written against that
  reads `false` as 0 through a `< 0` test and silently takes the found path.)
- **Nor can a CLASS — from HERE.** A `.sig` does carry classes now (schema 2), but
  the bundled stdlib deliberately opts out: it is a `runtime: true` library,
  linked into every program rather than selected as a dependency, and its classes
  are either internal (`Runtime\Json\Parser`) or compiler-owned (`stdClass`, which
  every module registers for itself). Exporting them would hand each program a
  second definition of a class it already holds. So anything whose API is an
  object still lives in `prelude/`: ext/simplexml, ext/dom and the `libxml_*`
  registry are `prelude/xml.php` + `xml_xpath.php` + `xml_dom.php` for exactly
  that reason (a `SimpleXMLElement` declared here would be invisible to the
  program holding one — `instanceof` false, properties read as raw bits).
- **A variadic cannot cross the `stdlib.o` boundary**, and neither can a callback.
  The `.sig` carries no variadic-ness, so the callee reads its arguments from the
  wrong place and returns garbage — `pack` lives in `prelude/binary.php` for that
  reason, as `array_map` / `array_filter` / `array_reduce` live in
  `prelude/array_fns.php` for the callback one.
- An element-**consuming** `array` parameter (one whose values get cast or
  stringified) must be marked `#[\Manticore\Attr\CellArg]`, or a caller passing a
  concrete-element array will have its raw slots decoded as cells. See
  `docs/attributes.md`.
- Empty-string predicates match Zend: `ctype_digit('')` → `false`,
  `str_starts_with('', '')` → `true`.
- Integer args to `ctype_*` in `[-128, 255]` are treated as a single byte (signed
  wrap via `+256`); ints outside that range are coerced to a decimal string and
  scanned.
- `gc_collect_cycles` is shadowed by a codegen builtin that calls
  `@__manticore_cc_collect_cycles`, emitted by
  `Compile\Mir\Passes\EmitLlvmRuntime`; the PHP body here is the Zend fallback.
  Contract: `docs/design/memory-abi.md` §7.

## Adding a function

Two tiers — pick by what the function needs:

1. **Codegen builtin** — a primitive, an LLVM intrinsic, or a libc call worth
   inlining. Dispatch plus emitter in
   `src/Compile/Mir/Passes/EmitLlvmBuiltins.php`, return type in
   `InferTypes::builtinReturnType`.
2. **PHP stdlib** — anything better expressed in PHP. Add a global-namespace
   `function` to one of the files above; it is exposed automatically.

Then rebuild and test a **user** program that calls it, plus `tools/difftest.sh`
for parity with `php`.
