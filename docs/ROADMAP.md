# Manticore — status & roadmap

**Single source of truth for "where the compiler is and what's next."**

_Last updated: 2026-09-21 · branch `main` · HEAD `49ced4d`._

## Current state

Pure-PHP, self-hosting PHP→native AOT compiler. `bin/manticore` compiles its own `src/` to a
byte-identical fixpoint, and the runtime is emitted as LLVM IR from PHP.

**Self-hosting is done.** That was the north star; it is now the floor. What replaced it is
**real-world applications** — the quality corpus is no longer the compiler alone but
third-party PHP compiled through it (symfony/console is the current driver), and the oracle is
still Zend: if `php` runs it, `tools/difftest.sh` must agree byte-for-byte.

**Gates:** `tests/aot/run.sh` · `tools/difftest.sh` (parity vs PHP 8.5) ·
`tools/selfhost_fixpoint.sh` (fixpoint byte-identical · self-host suite · rebuild stability
5×2) · `tools/docker/run_tests.sh --gate` (Linux). Counts move every session — run them
rather than trusting a number written here.

**Build:** `bin/build` (self-host — the normal loop), `bin/build --seed` (cold Zend
bootstrap), `bin/build --verify` (+ the gate). `bin/compile` is the cold-bootstrap fallback
only.

⚠ **`bin/build` green says nothing about `tools/selfhost.sh`.** The manifest build compiles
`src/Runtime` as a LIBRARY with a flattened namespace; the self-host path takes everything as
ONE module. They diverge on emitted symbol names, and only the stability gate covers the
second. Corollary: **never ship a compiler fix together with tree code that needs that fix** —
the previous generation then cannot build the tree at all, and only a cold seed recovers.

⚠ **A new codegen builtin used by the stdlib needs `bin/build --seed`.** The previous
generation does not know the symbol, so the stdlib `.o` build dies on an undefined symbol.

## Direction (2026-09) — read this before planning work

[`design/backend-and-build-strategy.md`](design/backend-and-build-strategy.md) is the decision
doc: why the next year is middle-end and build-infrastructure work, **why we are NOT writing
our own arm64/amd64 backend** (and the three criteria that would reopen that), and the ordered
ladder — CI · fast loop · name-id dispatch tables · incremental build · self-describing value
channels + a MIR verifier · opt-in strict containers.

One-line summary: every root cause of the last two months was ours, not LLVM's. What we pay
for is that PHP has no static value representation and the pipeline is committed only halfway
to erasure. A backend would replace the healthiest component and close none of it.

**Optimisation levels are decided policy, not taste:** `-O2` for anything shipped or whose own
speed matters (`bin/build`, `lib/*.o`, the installed binary); **`-O1 -j0`, no LTO, for the
iteration loop**; `-O0 --keep-ir` only for a binary `lldb` must walk. ⚠ A green `-O1`/`-O0`
run is NOT evidence about the `-O2` artifact — the `sjlj` locals bug was right at `-O0` and
wrong at `-O2`.

Built on branch `ci` (2026-09-07): **`bin/build --fast`** (`-O1`, apps only, to
`bin/manticore.fast`, never the canonical slot — 71 s vs 83 s), **`tests/aot/run.sh -O <n>`**,
and CI — `tools/docker/gate.sh` is the single definition of a Linux gate, consumed by
`tools/docker/run_tests.sh` and by `.github/workflows/{ci,nightly}.yml`.
### Recently completed (2026-09)

- ✅ **`Http\WebSocket`** — RFC 6455 server (`upgrade()` over `Http\Server`'s new
  `Response::takeover()`/`Server::onStop()` hooks) and client (`connect()`, `ws://`/
  `wss://`), plus permessage-deflate (RFC 7692, all four parameters). Superset —
  own suite (`tests/aot/cases/ws_*`) plus a manual Autobahn|Testsuite gate
  (`tools/autobahn.sh`); `docs/websocket.md`. Still open, all deliberately out of
  scope for v1: WebSockets over HTTP/2 (RFC 8441), extensions other than
  permessage-deflate, fragmentation on send, a socket-hijack API, WebSocket through
  `ext/curl`, following redirects in `connect()`.
- ✅ **`ext/zlib` incremental API** — `deflate_init`/`deflate_add`/`inflate_init`/
  `inflate_add`/`inflate_get_status`/`inflate_get_read_len` (`DeflateContext`,
  `InflateContext`), pure PHP, Zend-faithful (`tools/difftest.sh` is the oracle);
  built as the permessage-deflate prerequisite above. Inflate decoding resumes at
  the last complete unit (a symbol, a stored-block slice, a header field), so a
  stream fed in any chunking answers what zlib answers; a data error returns
  `false`, matching the existing one-shot `gzinflate` contract.
- ✅ a closure in an array element is owned by the buffer (repr `CLO`, ABI v11): released on overwrite, `unset` and container death, co-owned by element reads, `foreach` and a direct `($a[$k])()` call; a `callable` that is not a closure env is never released as one; `unset` on a shared buffer separates first (br `websocket`, 2026-09-24).
- ✅ array elements belong to the buffer — one ownership model and one key (hint) for every element walk; copies, spreads, unions and packs own what they hold (br `elemown`, 2026-09-23).
- ✅ reference boxes are counted — the box and its value die with the last holder (ABI v9); a closure env is counted like an object wherever it is held; a by-value parameter and a property are promoted into a box by `&`; `$a = &$b` makes both names one reference (br `refbox`, 2026-09-23).
- ✅ docblock array shapes — per-field typing, early unbox, `TypeError` on a lie, static shape
  errors (br `shapes`, 2026-09-21).

### Recently completed (2026-07)

- **`ext/pdo` + `pdo_sqlite` — a database layer** (`docs/pdo.md`). `PDO` / `PDOStatement`
  are a thin facade over an internal driver seam, so a future mysql/pgsql driver (a socket
  with pack/unpack, no FFI) drops in without touching them — SQLite is not a server, which
  is why this one is FFI. Demand-gated on a `PDO` mention, so a binary links `-lsqlite3`
  only if it uses it. No trampolines: driving prepare/step directly avoids `sqlite3_exec`'s
  callback entirely. No SQL scanner either — `sqlite3_bind_parameter_index()` already
  resolves `:name` with sqlite's own quoting and comment rules.
  SQLITE_BUSY is waited out through `\Runtime\AsyncHook` (never `sqlite3_busy_timeout`,
  which sleeps in a C frame and stalls the netpoller).
  Divergences, each documented: `getCode()` is the driver's int because
  `Throwable::getCode(): int` is a contract here; `bindParam()` binds by value at bind time;
  `bindColumn()` / `FETCH_BOUND` throw — all three for the same missing zval reference.
  ⛔ `FETCH_OBJ` / `FETCH_CLASS` / `FETCH_INTO` / `FETCH_LAZY`, and `fetch()` with no
  argument under the default `FETCH_BOTH`, are blocked on the ERASED-VALUE work, not on the
  driver: a value returned through a `mixed` channel whose arms have different shapes is not
  self-describing. `docs/pdo.md` carries three minimal repros that never mention PDO.
- **`ext/curl` — an HTTP client** (`docs/curl.md`). The easy API, `curl_multi_*` and
  `curl_share_*`, bound to libcurl through FFI and demand-gated, so a binary links
  `-lcurl` only if it calls one of them. `CURLOPT_WRITEFUNCTION` and its three siblings
  take real Closures: `fn_to_ptr` needs a string literal, so libcurl holds one of four
  fixed trampolines and carries the handle **id** in the `void*` it hands back. One
  `#[Variadic(2)]` binding serves every `curl_setopt` option class, because libcurl
  encodes the C type in the option NUMBER.
  Not implemented, each with a named throw: `CURLFile`/`CURLOPT_MIMEPOST` multipart,
  the `*_BLOB` options, `CURLMOPT_PUSHFUNCTION`, and a real `CURLINFO_CERTINFO`.
  Every float `CURLINFO` is read through its `_T` sibling — this build cannot read a
  C `double` out of memory (no `peek_f64`, no bitcast builtin, no `unpack('d')`), which
  is the one gap worth closing if a caller ever needs a genuine double from C.
- **`Http\` — an HTTP/1.1 server** (`docs/http.md`). A handler is
  `callable(Request): Response`; one process serves many requests at once, and php's
  `header()`/`setcookie()`/`http_response_code()`/`headers_sent()`/`echo` work inside it
  per request — as do `$_GET`/`$_POST`/`$_COOKIE`/`$_SERVER`/`$_SESSION` under
  `compat(true)`. Streamed request and response bodies, chunked framing,
  `Expect: 100-continue`, keep-alive with pipelining, and every limit answered by a
  precomputed refusal. `Buffer\ByteBuffer`/`Reader`/`Writer` underneath.
- **`serialize` / `unserialize` + magic methods** — `__serialize`/`__unserialize`,
  `allowed_classes`, `__PHP_Incomplete_Class`, `__debugInfo`, `var_export` of objects, and
  `__get`/`__set`/`__isset`/`__unset`/`__call` firing on an **erased** receiver.
- **One ownership contract for conditionals** — `?:`, `??`, ternary and `match` share
  `Compile\Mir\CondOwn`, so the arms and their consumer agree on who owns the result.
- **Generators: a resumed `try` owns its landing pad** — `Generator::throw()` no longer lands
  in the caller's catch.
- **The full `array_*` surface** — all 59 functions, including `array_multisort`,
  `array_merge_recursive` and the internal pointer.
- **The async remainder** — 1 MiB fiber stacks with `MANTICORE_FIBER_STACK`, non-quadratic
  channel waiter queues, checked `mmap`/`calloc`, `posix_getrlimit`/`setrlimit`, and a
  per-case deadline in the harness so a liveness bug fails the suite instead of hanging it.
- **Dynamic resolution** — dynamic function names, `new $cls`, `$cls::method()`, `$o->$m()`,
  `$o->$p`, `$obj instanceof $cls`, and Reflection through Tier 3.

## symfony-demo T5 — it builds, it runs (2026-09-08)

`gen_manifest.php 5` + `build -j 0` with `MANTICORE_SPLIT_JOBS=24 MANTICORE_SPLIT_BATCH=4`
produces a **171.7 MB binary** from 78 972 functions / 22 212 classes and 1.129 GB of IR, in
32m43 (front end + emission 1023 s, clang x24 925 s, link 0.95 s), compiler peak 4.72 GiB.
It RUNS TO COMPLETION: every tier line prints — including `tier T5: 43/58 root classes live` —
and the process exits 0. `class_alias()` was the last thing in the way and is now implemented
(the registry answers it). **107** undefined-function traps remain in that build — grouped by what each actually needs in
`docs/status/T5-TRAPS-HANDOFF-2026-09-08.md` (untracked, like every handoff): ~19 are pure PHP
with no dependency and no seed, ~10 need a compiler or runtime seam, ~40 are an FFI binding
(zlib, gmp, openssl, sodium, GD), ~28 are a subsystem (25 x `pg_*`, dba, SAPI)
(gmp/openssl/deepclone/image/dba/xml...), each one a runtime trap rather than a build failure.

## Tier 1 — correctness

| Gap | Repro | Today | Want |
|---|---|---|---|
| Integer overflow wraps | `PHP_INT_MAX + 1` | `PHP_INT_MIN` (two's complement) | promote to float, as php does. Needs value-range analysis to know which statically-int locals can overflow |
| `/` exact-int on variables | `$a/$b`, both int, divisible | `float` | `int`. Literal `6/2` already folds to `int(3)`; the variable case cascades through a numeric cell — low value |
| `echo` / concat of `INF`/`NAN` | — | renders lowercase | uppercase, as php does. `var_dump` is already correct. **No repro exists — write one first** |
| A reference to a by-REF parameter dangles | `function f(&$x) { return [&$x]; }` | the REF cell points at the caller's slot | the caller has to box the argument it passes |
| Division by zero never throws (found 2026-09-28) | `$z = 0; 1 % $z; 1 / $z; intdiv(1, $z); intdiv(PHP_INT_MIN, -1)` | `1`, `INF`, `0`, `PHP_INT_MIN` | `DivisionByZeroError` ("Modulo by zero" / "Division by zero") and `ArithmeticError` ("Division of PHP_INT_MIN by -1 is not an integer"). Sweep `%=` `/=` and the cell (mixed) operand paths too |
| A by-ref write that retypes a `foreach` value variable double-frees (found 2026-09-28) | `function f(&$x) { $x = 5; } foreach ([new stdClass] as $d) {} f($d); var_dump($d);` | SIGSEGV (a string element too) | `int(5)`. The loop variable holds a BORROW (see the foreach-borrow gap); the retyping write releases it as owned |
| A zone written in a date string is not adopted (found 2026-09-28) | `new DateTime("2000-01-01T00:00:00Z")`, `"… UTC"`, `"… EST"`, `"… +02:30"`, `"… Europe/Paris"` | the instant is right, but `getTimezone()` is the default zone | php adopts it: type 3 for an identifier / `UTC`, type 2 for an abbreviation (`Z`, `GMT`, `EST`), type 1 for an offset — the DateTimeZone class has no type-2 form yet |
| `print_r` of an object prints no properties | `class A { public $x = 1; } print_r(new A);` | `A Object ( )` | `[x] => 1`, visibility suffixes (`:protected`, `:A:private`) and `__debugInfo`, as var_dump's per-class arms already do |
| Scope-exit destructor order | two objects dying at one `}` where one sits in a reference box | box holders are released after the frame's other locals | php destroys the frame's variables in declaration order |

An ARRAY in a `$GLOBALS['x']` slot still reads back as a float: the slot is a cell channel
and arrays ride RAW in one by design (boxing would rebuild the array and change its identity),
so the two halves disagree for that carrier alone — scalars, strings, bools and null are
consistent across the `global $x` / top-level / `$GLOBALS` views since `globals_cell_repr`.

`(object)[10, 20]` keeps the vec shape where php makes a stdClass with the numeric-STRING
properties `"0"`/`"1"` (`{"0":10,"1":20}` vs `[10,20]`) — the cast would have to rebuild a vec
as a string-keyed assoc; `tests/aot/cases/object_cast_bag_repr.php` names it.

`['a'] === ['a']` compares pointers rather than contents, and `extract()` is unimplemented
(dynamic symbol-table writes the typed frame does not model). `compact()` works.

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
8. **Uninitialized typed properties serialize as their zero value.** Manticore zero-fills
   every slot, so `class P { public int $x; }` writes `1:{s:1:"x";i:0;}` where php writes
   `0:{}`. Needs an init bitmap in the object header — an object-ABI change.
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
     `mb_send_mail`.
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
   - Next: `MessageFormatter`, `ResourceBundle`, `Spoofchecker`, `UConverter`, `idn_to_*`.
6. **`mb_ereg*`** — UNDECIDED (2026-09-28): Zend binds Oniguruma, which is end-of-life
   upstream; neither vendoring it nor faking its syntax over PCRE2 is agreed yet. Parked.

Separate, not blocking: the stdlib `.o` links `-lssl -lcrypto -lpcre2-8` (+ `-liconv` on
macOS) into EVERY binary, hello-world included. Gating those on use is a size / deps cleanup
of the same mechanism.

## Tier 3 — infrastructure

- **`.sig` schema 2 ships classes, interfaces, enums and constants** (`tests/libs/classes` +
  `tools/libclass_smoke.sh`). What remains:
  - **`trait`s and generic (`@template`) classes still do not cross.** Both need method
    BODIES on the far side — a trait because it is copy-paste into the using class, a
    generic because each binding is reified from source. They are recorded in the `.sig`
    as `"unsupported"` so the diagnostic can say why.
  - **`instanceofMatchIds` / `descendantClassIds` remain closed-world and `catchAcceptsAll`
    still fails open** — now over the UNION of local and imported classes, which is the
    whole program for a library-first build order, but not for a plugin loaded later.
  - **A class descriptor is `linkonce_odr` and the two modules can emit different
    bytes for it** (the library's rmeta field is null unless the library itself reflects).
    The application object is linked first, so its richer copy wins — deterministic given
    how the link line is built, but an invariant rather than a guarantee. The fix is the
    descriptor extension below.
- **Per-class function pointers in the class descriptor — partly done.** `json_encode`
  and `(array)$obj` of an application class now answer correctly from inside
  `manticore_stdlib.o`: the descriptor carries `props_fn@32` (`@__mir_props_<id>`) and the
  json/`(array)` walkers read it. What remains: `tostr_fn` / `debug_fn` are not on the
  descriptor, so `__manticore_tagged_to_str` and three `LowerPrelude::*ObjectSrc()`
  generators still synthesize per-program walkers. Finishing it bumps `MemoryAbi::VERSION`
  (⇒ one `bin/build --seed`).
- **No dependency resolution, no cross-build module cache, no packaging bootstrap.** `MANTICORE_HOME`,
  `~/.manticore/cache` and a `compiler_abi` field appear in
  [`design/module-system.md`](design/module-system.md) but nowhere in `src/`. Manifest targets
  and Composer source discovery work; transitive dependency fetch does not.
- **The ABI version is not surfaced.** `MemoryAbi::VERSION` is 8 and `manticore version`
  prints only `manticore 0.11.0`, so a vendored `.o` cannot detect a mismatch.
- **`dump-mir --after=<pass>`** is described in [`design/mir.md`](design/mir.md) but not
  implemented.
- **Cycle collector: manual trigger only**, and it does not scan static or global roots. A
  threshold heartbeat and a safe-point trigger are unbuilt.
- **Monomorphize has no `$cell` fallback.** `Monomorphize.php` calls it "future, Phase 3"; the
  "every monomorphized function keeps exactly one name-addressable `$cell` entry" invariant in
  [`design/monomorphization.md`](design/monomorphization.md) is aspirational, not upheld.
- **CI is parked, no prebuilt binaries.** `.github/workflows/{ci,nightly}.yml` exist over
  `tools/docker/gate.sh` but run on manual dispatch only. Every install compiles from source.

## Tier 4 — performance

Everything already beats Zend (2–50× on compute; assoc arrays beat php outright). The
remaining levers:

- **IR volume is the build-time lever** — `clang -O2` is ~66% of `bin/build`. Emitting less IR
  beats optimising the front end.
- SSO / interning for dynamic small strings; property-bag literal hashes.
- ✅ **`TypeCheck` is ON by default** (2026-09-22; `MANTICORE_TYPECHECK=0` turns it off) — it
  catches the `str_replace`-array misuse at compile time instead of as a runtime SIGBUS. Two of
  its own rules had kept it gated, not the corpus: a spread argument was checked as "argument
  1", and arithmetic on any string operand was rejected where php coerces a numeric one
  (`docs/design/value-channels.md`).
- ⛔ **`plausiblePtrIr` → assertions** — 15 sites dereference an unvalidated word after a bounds
  guess (`> 0xFFFF && < 2^48`). Each sits in an ERASED channel, so they can only become
  assertions once those producers are self-describing: the sequel to the value-channel epic.
- Array / JSON / sort helpers sit at roughly 2× php and are competing with hand-tuned C —
  that is close to the ceiling, not a bug.

- **W2 done (2026-09-07): dynamic-name calls dispatch through a per-module table**, not a
  `strcmp` chain (`912b440`, `0023ef1`): t2 IR −12.3%, build −31%. `newdyn` still emits one
  chain per module per arg shape; `dynf` is not out-lined.
- **W3 half done: a content-addressed `.o` cache over the split parts** (`9edfe5f`) serves
  `bin/build --fast` (44 of 64 parts from cache, clang 26.7 → 8.4 s). Per-file keys are
  impossible — the module is whole-program — so a shipped build is still whole-program
  and a split build is never the artifact.
- **Opt-level policy** — see "Direction (2026-09)" above. `-O2` ships, `-O1 -j0` iterates.
## How to build the plans (the method that works here)

1. **Probe-matrix first.** One minimal repro per feature, diffed against `php`, before
   committing to a design.
2. **Find the convergent root.** Monomorphization was *the* root behind a dozen erasure
   symptoms. Ask "is there one fix that collapses several rows?" before building.
3. **Phase and gate hard, every phase:** suite + difftest + fixpoint + stability + `--seed`.
   Never batch risky changes. A "random transient" can be a real latent bug — chase it.
4. **Dual-validate the Zend seed AND the native build** — they diverge on strings, floats and
   by-ref. Some bugs only surface in the native self-build, and an emitter fix needs TWO
   generations before its effect is real.
5. **php-faithful signatures; root cause over workaround.** No reverts, no workarounds.
6. **Self-host is the gate; real programs are the probes.**

## Design references

Living reference:

- [`../README.md`](../README.md) — what it is, what it needs, how to use it.
- [`superset.md`](superset.md) — everything with no Zend oracle, and what that costs.
- [`builtins.md`](builtins.md) — generated: every PHP internal function/class, implemented or
  missing, per extension; plus the Manticore-only namespaces. `php tools/builtins_audit.php`.
- [`install.md`](install.md) — host dependencies and platform support.
- [`async.md`](async.md) — structured concurrency and transparent I/O.
- [`memory.md`](memory.md) — the memory model as a user sees it (`--memory`, env knobs).
- [`modules.md`](modules.md) — the manifest, `.sig` interfaces, Composer projects.
- [`generics.md`](generics.md) — docblock `@template`, bounds, reified `@var C<T>`.
- [`ffi.md`](ffi.md), [`attributes.md`](attributes.md) — native binding and the attribute set.
- [`http.md`](http.md), [`curl.md`](curl.md) — the HTTP server and the HTTP client.

Design notes:

- [`design/mir.md`](design/mir.md) — the IR, the type lattice, the pass pipeline.
- [`design/memory-abi.md`](design/memory-abi.md) — **the stone tablet**: layout, refcount
  encoding, destructor order, cycle-collector ABI.
- [`design/refcount-cow.md`](design/refcount-cow.md) — why refcount + CoW, not a GC.
- [`design/type-system-v2.md`](design/type-system-v2.md),
  [`design/unknown-cell-soundness.md`](design/unknown-cell-soundness.md) — the cell / union /
  NaN-box type system and the erased-representation soundness work.
- [`design/monomorphization.md`](design/monomorphization.md) — the generics / erasure engine.
- [`design/generators-and-pointers.md`](design/generators-and-pointers.md) — generators.
- [`design/module-system.md`](design/module-system.md) — the module design behind `modules.md`.
- [`design/build-and-packaging.md`](design/build-and-packaging.md) — packaging.
- [`design/backend-and-build-strategy.md`](design/backend-and-build-strategy.md) — the
  2026-09 direction: no own backend, the W0–W5 ladder, and the opt-level policy.
- [`design/late-static-binding.md`](design/late-static-binding.md) — LSB lowering.
- [`design/async-attribute.md`](design/async-attribute.md) — a designed, deliberately unbuilt
  `#[Async]`.
