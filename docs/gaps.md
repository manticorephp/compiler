# Known gaps — semantic debt by area

_Moved out of `ROADMAP.md` on 2026-10-11 so the roadmap can be goal-driven. Content is the
2026-10-06 gap inventory, unchanged: correctness table, ownership leftovers, magic methods,
other semantic gaps, Unicode/mbstring/intl status. A gap that closes is deleted here; a NEW
bug is a GitHub issue plus a repro (AGENTS.md "Reporting a bug"), never a row._

## Tier 1 — correctness

| Gap | Repro | Today | Want |
|---|---|---|---|
| Integer overflow wraps | `PHP_INT_MAX + 1` | `PHP_INT_MIN` (two's complement) | promote to float, as php does. Needs value-range analysis to know which statically-int locals can overflow |
| `/` exact-int on variables | `$a/$b`, both int, divisible | `float` | `int`. Literal `6/2` already folds to `int(3)`; the variable case cascades through a numeric cell — low value |
| `echo` / concat of `INF`/`NAN` | — | renders lowercase | uppercase, as php does. `var_dump` is already correct. **No repro exists — write one first** |
| A reference to a by-REF parameter dangles | `function f(&$x) { return [&$x]; }` | the REF cell points at the caller's slot | the caller has to box the argument it passes |
| A zone written in a date string is not adopted (found 2026-09-28) | `new DateTime("2000-01-01T00:00:00Z")`, `"… UTC"`, `"… EST"`, `"… +02:30"`, `"… Europe/Paris"` | the instant is right, but `getTimezone()` is the default zone | php adopts it: type 3 for an identifier / `UTC`, type 2 for an abbreviation (`Z`, `GMT`, `EST`), type 1 for an offset — the DateTimeZone class has no type-2 form yet |
| `print_r` of an object prints no properties | `class A { public $x = 1; } print_r(new A);` | `A Object ( )` | `[x] => 1`, visibility suffixes (`:protected`, `:A:private`) and `__debugInfo`, as var_dump's per-class arms already do |
| An int local that a loop or one branch turns float reads float everywhere (found 2026-09-28) | `$s = 1; if ($b) { $s = $s + 1.5; }` with `$b` false; `$s = 0; foreach ([] as $x) { $s += 1.5; }` | `float(1)`, `float(0)` | `int(1)`, `int(0)`. The accumulator shape makes the whole slot a float (InferScans float slots, the loop merge's widenNumeric); php keeps int until the float store runs — needs a numeric cell there, a perf question |
| A fresh string handed to a `mixed` parameter leaks (found 2026-09-28) | `function g(mixed $v): mixed { return $v; } $s = g("{" . $n . "}");` in a loop; `new P("x" . $n)` with `public mixed $v` promoted | 1 string (80 B) per call; the object too for the promoted-property ctor | released. Also on main; the ownership epic's territory |
| `#[Struct]` misuse is not diagnosed (found 2026-09-28) | `#[Struct] final class R implements JsonSerializable { … } echo json_encode(new R(…));` | SIGBUS: the value reaches `mixed`, and the walker reads a class descriptor at `+0` that a headerless record does not have (tests/aot/runner's workers died this way) | a hard compile error naming the class and the site, as `CheckTypeDefs` already does for `#[TypeDef]`: a `#[Struct]` into a `mixed` slot, `json_encode` / `var_dump` / `serialize`, `instanceof`, an interface, `extends` |
| Scope-exit destructor order | two objects dying at one `}` where one sits in a reference box | box holders are released after the frame's other locals | php destroys the frame's variables in declaration order |

An ARRAY in a `$GLOBALS['x']` slot still reads back as a float: the slot is a cell channel
and arrays ride RAW in one by design (boxing would rebuild the array and change its identity),
so the two halves disagree for that carrier alone — scalars, strings, bools and null are
consistent across the `global $x` / top-level / `$GLOBALS` views since `globals_cell_repr`.

`(object)[10, 20]` keeps the vec shape where php makes a stdClass with the numeric-STRING
properties `"0"`/`"1"` (`{"0":10,"1":20}` vs `[10,20]`) — the cast would have to rebuild a vec
as a string-keyed assoc; `tests/aot/cases/object_cast_bag_repr.php` names it.

`['a'] === ['a']` compares pointers rather than contents. `extract()` writes the locals the
function names (templates it includes count): a key no code of the function names is counted
and dropped, `EXTR_REFS` throws, and a local holding NULL reads as undefined to `EXTR_SKIP` /
`EXTR_IF_EXISTS`. An `include` inside a function of a file that is no compile unit (a template)
runs it in the function's scope; the candidates are the constant path, or the files under the
constant directory prefix of the path (at most 64). `compact()` works.

### Ownership redesign — what PR #15 leaves open (2026-10-06)

**Tasks 10–11 — done (branch `ownership-gen`, ABI v17).** A generator frame owns its params, locals, key, sent value and return value, and gives them back however it ends: destroyed before the first resume, destroyed while suspended (the `finally` blocks around the yield run first, as in php), finished, or left by an exception — which now marks it finished (#43) and drops its locals through cleanup pads. `send()` no longer leaks and the slot is cleared (#22, #44); a yielded key object is released; a loop variable two iterator loops bind at different classes no longer keeps a reference per iteration (#23). Found by the close-out soak and fixed with it: a task's arena (chunks + mark stack) is freed with its fiber context (was ~5 KB per task that reached an arena scope); the generator `getIterator()` hands a `foreach` over an `IteratorAggregate` is released when the loop ends; a string a compare operand mints against a cell (`(string)(int)$key === $key`, so every `array_key_exists()` with a string key) is freed by every operator; a closure aliased into a local of a monomorphized `callable` callee (`Closure::fromCallable()`) gives its reference back; an exception out of a loop's own iterator step (a generator resume, `current()` / `next()`) drops the frame's locals and the iterator the loop holds (#103). WS soak (`docs/audit/ownership/ws_soak.php`): 5000 connect/echo/close, no per-connection block left under `leaks`. **Still open:** a fresh `new X` written inline as a `foreach` subject is not released when an exception leaves the loop (a local or a call result is); `strlen()` of an int in a cell crashes (#102); a `yield` reached in a `finally` while the generator is being destroyed suspends it once more instead of raising php's `Cannot yield from finally in a force-closed generator`; a `try` in a generator does not unwind the arena mark stack. Carried over — self-build max RSS is +12% vs main (+4% vs `cbf73c0a`, cause not investigated); `tools/libclass_smoke.sh` cross-module class constant `Acme\Point::NAME` is `Undefined constant` (also on main); `\Ffi\Ptr` in an untyped slot is boxed as an object cell; temps and `__main` have no cleanup pads; `isset`/`??` on an object or string base with a code-running offset still evaluates the base before the key; a fiber argument's throwing destructor fires at shutdown, not from `start()`.

| Gap | Repro / shape | Today | Want |
|---|---|---|---|
| Destructor timing of a spilled property-read temp | `$this->f($this->p)` where `f` overwrites `p` | temp released at statement end | released right after the call, as php does |
| Call-result iterable elements | `foreach (f() as $x) { break; }` | elements destructed at return | destructed at `break` |
| Container of erased elements never drops them | `$m = [$q]` of an `erase()` result; foreach literal; `array_map` | leak | released with the container |
| Union-of-object locals stored into containers | `$a[] = $cond ? new A : new B;` | leak | released |
| Self-box of a non-shallow array rebuilds and leaks the old buffer | array of enum/nested/closure elements into a cell | leak (also on main) | released |
| `move` mode leaks the nested source buffer; union `vec[cell]` with raw closure words; closure non-constant array default in a cell param keeps the box; `.sig`-imported interface `: array` method decls leak | | leaks (some on main) | released |
| Conditional arms of different classes typed from the THEN arm | `$o = $c ? new A : new B; $o->onlyOnB();` | mistyped, stays a borrow | joined type |
| Null in an array slot treated as empty by `count`/`foreach`/`array_merge` | | silent | Zend `TypeError` ⇒ throw (also on main) |
| By-ref-captured local joining an erased array with string/null (`scanByRefCaptureWiden`) | | wrong answers (also on main) | Zend answer |
| Erased `: mixed` return boxes a raw array as an INT (`boxUnknownIfRaw`); null ⊔ erased join `is_null` wrong | | silent wrong (re-verify) | Zend answer |
| `array<string,mixed>` param given a nested array, then `$m['k'] = 'str'` | | throws `array{k: string[]} key k must be of type string[]` (#50) | assigns |
| `[...$x]` with string keys typed `vec`; enum argument to a dynamic `mixed` closure param crosses as its ordinal | | wrong (also on main) | php answer |
| Typed property → `mixed &` param whose callee writes another kind | | coerces | php `Cannot assign … to reference held by property …`; the TypeError for typed-prop → `string &` also misses closures, nullable/union params, static props, array elements, and fires at address-take instead of at RECV |
| By-ref retype: a subclass override with a different by-ref param retype is not seen; nested base `$a[0][1]` passed by-ref not widened | | static `resolveMethodClass` | resolved per class |
| `catch (A\|B $e)` whose union joins to a non-object | | falls back to the first class | union type |
| Build cache stamp write non-atomic; split stdlib key lacks `.sig` hash; `--runtime` without `--emit-library` skips the stdlib import | | concurrent sessions can read a mismatched artifact | atomic temp+rename; hash in the key |
| Generator / async task cancelled mid-throw | | untested (possible double release) | test |

## Tier 2 — semantic depth

### Magic methods — what is knowingly not done

`serialize`/`unserialize`, `__debugInfo`, `var_export` of objects, and erased-receiver
dispatch for `__get`/`__set`/`__isset`/`__unset`/`__call` are **done**. What is left, and why:

1. **None of the hooks fire for an INACCESSIBLE DECLARED member.** All of them gate on "the
   class has no slot for this name". php also fires when the slot EXISTS but is
   private/protected out of the accessing scope. Manticore enforces no visibility at all, so
   such an access simply succeeds. Closing it needs a scope model in the emitter (compare the
   frame's class prefix against `PropertyMeta::$visibility` / `$declaringClass`) — separate
   work, not a dispatch problem.
2. **`__call` on an erased receiver is rerouted only when NO class declares the method.** The
   mixed case — some classes declare it, others answer through `__call` — needs two different
   argument lists in one switch, and the call's arg emission is built against the resolved
   callee's signature.
3. **`is_callable()` on an erased value answers true for ANY object.** Narrowing it to classes
   declaring `__invoke` needs a class_id probe, and a Closure has NO class descriptor (slot 0
   is its function pointer), so the probe would start answering false for the common case.
   Needs the closure header, not a switch.
4. **`__sleep` / `__wakeup` are ignored.** php calls them from serialize/unserialize when
   `__serialize`/`__unserialize` are absent.
5. **`unset($o->declaredProp)` is a no-op**, so the "unset it so `__get` fires again" idiom
   does not work.
6. **`&__get` (return by reference) is unsupported** — the magic call yields an i64 cell.
7. **A callable held in a `mixed` value.** `instanceof Closure` over such a cell answers
   false, passing it to a `callable` parameter hands the callee the boxed word (SIGSEGV), and
   `$c(...$args)` over it faults. A `Closure`-typed slot (`Closure::fromCallable()` at the
   boundary) is the working form today. (`Stringable`, formerly item 7, is implicit now.)
8. **Uninitialized typed properties: the edges.** The init bitmap (memory-abi §3.3) makes a
   typed read throw and every view skip the property, but a read through an ERASED receiver
   (`mixed $o; $o->p`, `$o->$name`) answers the zero instead of throwing, and a reference
   or by-ref argument to an uninitialized non-nullable property initializes it where php
   throws `Cannot access uninitialized non-nullable property … by reference`.
9. **`R:` is never emitted.** It marks a php REFERENCE, and a Manticore array carries no
   is_ref bit, so there is no runtime fact to emit it from. It is accepted on input as a
   value copy.
10. **`(array)$obj` does not mangle private/protected keys.** Declared and dynamic
    properties both come back, but a private `$h` arrives as `"h"` where php writes
    `"\0P\0h"` (protected: `"\0*\0h"`). Manticore enforces no visibility, so there is
    no scope to encode.
11. **A bare `array` property hint erases its element**, so the elements of `public array $a`
    read raw — `var_dump` and `var_export` print ints as denormal floats. A `@var int[]` on
    the same property is correct. This is the parked element-repr work.
12. **php 8.5 clone-with does NOT run the readonly guard here** — and should not: the RFC's
    purpose is to let a clone reinitialize a readonly property. Noted because it looks like a
    missing check. There is no `php` oracle for it (8.5.8 does not parse the syntax), so it is
    a superset feature.

### Other semantic gaps

- **Container leaks that remain (any value kind)**: a static property never releases what an
  overwrite replaces; a `mixed`/cell array element is not released on overwrite / `unset`; a
  fresh object or closure passed to a `mixed` parameter is never released by the caller; a
  CAPTURELESS closure literal has no lifetime header and is never freed.
- **Calling a `callable` string / `[obj, 'm']` array held in a `Closure`/`callable` slot**
  (`$handlers['x']('a')`) crashes: the slot is called as a closure env. Only a literal passed
  straight to a `callable` parameter is converted. `$f instanceof \Closure` on a cell is false.
- **`goto` into a loop body** is unsupported. Plain forward and backward `goto` work.
- **`ReflectionEnum` does not exist** — it was built and reverted. Every other Reflection
  class ships (`prelude/reflection.php`).
- **Static properties are external-linkage globals only**, so two compilation units cannot
  disagree about one.
- **A user `global $x` shared by a library and an application has its type inferred per
  module.** Each module unifies only the stores it sees, so a library storing an int
  element into an array global the application typed `array<string,string>` disagrees on
  the buffer contract. The superglobals are pinned to `array<string, mixed>` for exactly
  this reason; user globals crossing modules need the same (or a `.sig` record).
- **A library base class dispatching to an application override** does not reach the
  override (`(new AppChild)->call($x)` where `LibBase::call` invokes `$this->m($x)` runs
  the base's `m`).
- **Element representation is half done.** The array flags word carries an element-repr
  nibble that release / retain / COW read, but the erased element channel is not yet a cell,
  so a concrete `string[]` parameter fed a cell-element array still misreads.

## Unicode — mbstring and intl (decided 2026-09-28)

The byte functions (`strlen`, `substr`, `strtoupper`, …) stay byte-oriented: that is the Zend
contract, and `mbstring.func_overload` is gone from PHP 8 for that reason. Unicode arrives as
the extensions Zend ships it in. Today: `iconv*` (libiconv / glibc), `preg_*` with `/u`
(PCRE2), and `mb_strcut` — nothing else from mbstring.

**Policy: the stdlib may link an external C library when an extension's functionality rests on
it** (ICU for intl, as Zend does), through the existing demand-gated prelude + `#[Library]`
path (`ext/curl` → `-lcurl`, `pdo_sqlite` → `-lsqlite3`), so only a program that uses the
extension links it. Small hot cores stay pure PHP / codegen builtins.

Order:

1. ✅ **mbstring UTF-8 core, pure PHP** (`src/Runtime/Stdlib/Mbstring.php`, br `mbstring`) —
   `mb_strlen`, `mb_substr`, `mb_strcut`, `mb_str_split`, `mb_strpos` / `mb_strrpos` /
   `mb_strstr` / `mb_strrchr`, `mb_substr_count`, `mb_check_encoding`, `mb_scrub`,
   `mb_ord` / `mb_chr`, `mb_str_pad`, `mb_trim` / `mb_ltrim` / `mb_rtrim`,
   `mb_internal_encoding`, `mb_substitute_character`. Malformed UTF-8 is read the three
   ways Zend reads it (decoder / mblen table / fast count — see the file header);
   `tools/mbstring_diff.php` fuzzes it against Zend's mbstring, 0 mismatches over 2M cases.
   Known divergence: a search offset walking past a truncated trailing sequence — Zend reads
   past the string there. The case-insensitive four (`mb_stripos`, `mb_stristr`,
   `mb_strrichr`, `mb_strripos`) came with step 4; hot ones (`mb_strlen`) later
   become codegen builtins (with the PHP body, bootstrap rule).
2. **Every encoding name** — split in three:
   - ✅ 2a (`MbstringCodecs.php`, `MbstringTables.php` generated by
     `tools/gen_mbstring_tables.php` from Zend): all 79 names + aliases + MIME names; the 25
     single-byte encodings from byte tables read out of Zend; UCS-2 / UCS-4 / UTF-16 / UTF-32
     (BE/LE/BOM) in PHP; the CJK and stateful ones (SJIS, EUC-JP, ISO-2022-JP, CP932,
     GB18030, CP936, BIG-5, EUC-KR, UHC, HZ, …) through the host iconv, with a per-host
     candidate list (a fallback such as eucJP-win → EUC-JP trades exactness for a converter);
     `mb_convert_encoding` (string / array, candidate list), `mb_list_encodings`,
     `mb_encoding_aliases`, `mb_preferred_mime_name`; every mb_* above takes every encoding.
     All codecs meet in ONE interchange form, marked UTF-8 (see the codecs header). Fuzzed
     against Zend over 23 encodings × 18 functions incl. conversions: 0 mismatches over 9M
     cases; the iconv path has no Zend harness (FFI) — `mbstring_encodings` covers it
     natively. Known gaps: malformed CJK input is marked per byte (Zend's CJK decoders mark
     per sequence); `UTF-8-Mobile#*` is plain UTF-8; the ArmSCII-8 encoder's five
     non-mirror bytes come from a generated fix table.
   - ✅ 2b: `mb_detect_encoding` / `mb_detect_order` and the candidate list of
     `mb_convert_encoding` score candidates exactly as Zend's mb_guess_encoding (demerits,
     php-src's rare-codepoint bit vector, generated into `MbstringTables.php`; the single-precision order
     multiplier, strict elimination, the UTF-7/JIS/ISO-2022-JP pre-validators), and parse
     encoding lists Zend's way ("auto" and every prefix of it, quotes).
   - ✅ 2c: UTF-7 / UTF7-IMAP (`MbstringUtf7.php`, libmbfl transcribed, validators included);
     BASE64 / Quoted-Printable / UUENCODE / HTML-ENTITIES (`MbstringBytes.php`, with
     mb_fast_convert's byte rule: to Base64/QPrint reads the source as 8bit, from
     Base64/QPrint/UUENCODE writes raw bytes — also in scrub / search / substr_count;
     UUENCODE counts as single-byte); `mb_encode_numericentity` /
     `mb_decode_numericentity`; `mb_encode_mimeheader` / `mb_decode_mimeheader`
     (`MbstringMime.php`, the encoder's 90-codepoint buffer emulated — its line breaks
     depend on it). The E_DEPRECATED notices of the byte encodings are not printed.
     CJK `mb_str_split` / `mb_strcut` walk php's lead-byte tables (generated).
     Fuzz: 29 encodings × 26 functions, 0 mismatches.
   - Open: `mb_strcut` over UTF-7 / UTF7-IMAP / JIS / ISO-2022-* / CP5022x / HZ / GB18030 /
     CP950 and the byte encodings (Zend cuts those through its legacy byte-at-a-time
     filters with a 20-byte look-back heuristic) throws an Error; `mb_convert_variables`
     waits on by-reference variadics (`mixed &...$vars`, a compiler gap); `mb_language`,
     `mb_get_info`, `mb_http_input` / `mb_http_output`, `mb_parse_str`, `mb_output_handler`,
     `mb_send_mail`, `mb_convert_kana`. ⚠ `extension_loaded('mbstring')` now answers true, so
     symfony/polyfill-mbstring no longer fills these in — they are owed here.
3. ✅ **ICU link infrastructure** (2026-09-28) — `prelude/intl.php` (demand-gated, so only a
   program using intl links libicu), `#[Library('icuuc'|'icui18n')]` resolved by
   `icu_link_flags()` (pkg-config; Homebrew's keg-only icu4c included), the version suffix
   read from `unicode/uvernum.h` by `icu_symbol_suffix()` and appended by the FFI emitter;
   ICU packages in both Docker images (php-intl in the oracle) and in macOS CI. First
   consumer: `Normalizer` / `normalizer_normalize` / `normalizer_is_normalized`
   (`intl_normalizer`). Linux not yet run against the new images. Was:
   - ICU C symbols are VERSION-SUFFIXED (`u_strToUpper_74`) unless ICU was built with
     `U_DISABLE_RENAMING`; `#[Symbol]` needs the suffix resolved at build time (probe
     `U_ICU_VERSION_MAJOR_NUM` / `icu-config`, like `pcre2_link_flags()`).
   - ✅ decided 2026-09-28: DYNAMIC. A binary that uses intl links the system ICU at run time
     (like `-lcurl` / `-lsqlite3`); no static ICU data.
   - docker images, CI and Alpine get the ICU packages.
   - AGENTS.md Design principle §2 and README list "the libraries of the extensions a
     program uses", not just libc + PCRE2 + OpenSSL.
4. ✅ **mbstring case / width** (`MbstringCase.php`) — `mb_strtoupper` / `mb_strtolower` /
   `mb_convert_case` (all eight MB_CASE_* modes), `mb_ucfirst` / `mb_lcfirst`, `mb_stripos` /
   `mb_strripos` / `mb_stristr` / `mb_strrichr`, `mb_strwidth` / `mb_strimwidth`. Zend's
   mbstring uses its OWN Unicode tables, not ICU, so no ICU is needed here: the full and simple
   mappings, the case-ignorable / cased properties and the double-width ranges are read out
   of Zend codepoint by codepoint by `tools/gen_mbstring_tables.php` (~200 KB of tables in
   the stdlib `.o`; a program that does not call them does not grow). php_unicode.c's
   context rules are transcribed — the final sigma including its 64-codepoint buffer
   look-back / look-ahead, ISO-8859-9's dotted/dotless i. Fuzz: 0 mismatches.
5. **intl over ICU** — one demand-gated prelude file per class family
   (`prelude/intl*.php`), php-src's ext/intl transcribed call for call, so parity is
   near-free (Zend calls the same ICU):
   - ✅ `Normalizer`, `grapheme_*` (break iterator + usearch), `Collator` (three sort modes,
     sort keys), `NumberFormatter` (all styles/types, currency, parse offsets, attributes,
     symbols, patterns), the intl error state (`intl_get_error_*`, `intl_error_name`),
     `Transliterator` (symfony/string's slugger), `Locale` + `locale_*` (subtags, display
     names, keywords, compose/parse, lookup, acceptFromHttp, likely subtags).
   - ✅ `IntlChar` (all methods + constants; full-range parity over every code point).
   - ✅ `IntlTimeZone` + `intltz_*` + `IntlIterator`: ucal_* for offsets/names/IDs, the zoneinfo64
     resource (ures_*) for what only C++ exposes (equivalent IDs, region, hasSameRules,
     useDaylightTime, getDSTSavings) — parity over every system zone × 3 locales × 8 styles.
     Known: an explicit `IntlIterator::rewind()` resets `key()` (php keeps the old index; its
     foreach resets it), and `current()` past the end is null (php var_dumps `UNKNOWN:0`).
   - ✅ `IntlCalendar` + `IntlGregorianCalendar` + `intlcal_*` / `intlgregcal_*` over ucal_* —
     fields, limits, add/roll/fieldDifference identical to Zend across 14 calendar types.
     `isLeapYear` (C++ only) is GregorianCalendar's rule against the cutover year. The
     deprecated forms (`set()` with >2 args, the 3+-arg constructor) stay silent.
   - ✅ `IntlDateFormatter` + `datefmt_*` + `IntlDatePatternGenerator` over udat_* / udatpg_* —
     every style × 14 locales × both calendar kinds, the whole pattern alphabet, parse round
     trips: identical to Zend.
   - ✅ `IntlBreakIterator` / `IntlRuleBasedBreakIterator` / `IntlCodePointBreakIterator` /
     `IntlPartsIterator` over ubrk_* and utext_* (getRules() read from the compiled data).
   - ✅ `idn_to_ascii` / `idn_to_utf8` (UTS #46) and `Spoofchecker` (uspoof_*; Zend's
     warning sites answer their value silently, pending the warnings → exceptions epic).
   - ✅ `UConverter` (ucnv_*; a subclass's toUCallback/fromUCallback are called back through
     fixed trampolines, as Zend does — Zend itself segfaults converting with a CLONED one).
   - ✅ `ResourceBundle` (ures_*; element reads ride ArrayAccess, so `instanceof ArrayAccess`
     and a bare isset() answer where php says false / throws).
   - ✅ `MessageFormatter` + `msgfmt_*`: the pattern is scanned in PHP (MessagePattern) and
     every argument occurrence renumbered into its own `umsg_vformat` slot through a va_list
     built per ABI (Apple arm64 / SysV x86_64 / AAPCS64), so ICU formats everything; parse is
     MessageFormat::parse transcribed (umsg_vparse crashes on a part-way failure). Identical
     to Zend over 12 locales × 19 patterns × 5 value sets and a parse sweep. Known: after a
     FAILED setPattern Zend formats from ICU's half-reset state (`{}` per argument) — not
     reproduced; var_dump of a closure lacks php 8.5's name/file/line keys.
   - ✅ `IntlListFormatter` (ulistfmt_*), `normalizer_get_raw_decomposition`. **ext/intl is
     complete**; `extension_loaded('intl')` and `extension_loaded('mbstring')` answer true
     (folded and at run time). Open around it: `print_r` of objects, DateTime adopting a
     zone named in the date string.
6. **`mb_ereg*`** — UNDECIDED (2026-09-28): Zend binds Oniguruma, which is end-of-life
   upstream; neither vendoring it nor faking its syntax over PCRE2 is agreed yet. Parked.

Separate, not blocking: the stdlib `.o` links `-lssl -lcrypto -lpcre2-8` (+ `-liconv` on
macOS) into EVERY binary, hello-world included. Gating those on use is a size / deps cleanup
of the same mechanism.
