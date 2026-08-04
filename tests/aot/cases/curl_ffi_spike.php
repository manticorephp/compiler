<?php

// ext/curl PHASE-0 SPIKE — the go/no-go for binding libcurl through FFI.
//
// It proves, in one binary, the five things the whole extension rests on:
//   1. `-lcurl` reaches the link line from #[Ffi\Library('curl')] alone
//      (pkg-config has no `curl.pc`; the driver must fall through to
//      `curl-config --libs`).
//   2. ONE #[Variadic(2)] binding of curl_easy_setopt carries a char*, a
//      function pointer and a long through the same third argument — the
//      vararg's LLVM type never enters the `declare`, only the `call`.
//   3. fn_to_ptr() hands libcurl the address of a compiled PHP function, and
//      the uniform i64 ABI *is* the C ABI for its (char*, size_t, size_t,
//      void*) signature.
//   4. str_from_buffer() ALLOCATES a headered PHP string while a C frame is on
//      the stack, hundreds of times, without clobbering the arena. qsort
//      re-entered PHP but never allocated; this is the untested half.
//   5. curl_easy_perform() completes a file:// transfer and curl_easy_getinfo
//      reads an off_t back out of a caller-allocated out-param.
//
// ⚠ EVERY manticore-only call is hoisted ABOVE the first echo. tools/difftest.sh
// classifies a case as PHP-SKIP only when php produced NO stdout, so a prefix
// printed before php's "undefined function" fatal turns this into a bogus DIFF.

#[\Ffi\Library('c'), \Ffi\Symbol('calloc'), \Ffi\Give]
function sp_calloc(#[\Ffi\CType('size_t')] int $n, #[\Ffi\CType('size_t')] int $sz): \Ffi\Ptr {}

#[\Ffi\Library('c'), \Ffi\Symbol('free')]
function sp_free(#[\Ffi\Take] \Ffi\Ptr $p): void {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_init')]
function sp_curl_init(): \Ffi\Ptr {}

// The one binding that has to carry everything. CURLoption and CURLcode are
// enums => C `int`, hence the FUNCTION-level #[CType('int')] (without it a
// returned -1 reads back as 4294967295). The vararg is declared `long`: on LP64
// a long, a char*, a void* and a function pointer all ride one 8-byte slot, and
// #[Variadic(2)] is what makes the backend put it where Darwin arm64's va_arg
// looks — the STACK, not a register. Same rationale as sys_fcntl.
#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_setopt'), \Ffi\Variadic(2), \Ffi\CType('int')]
function sp_curl_setopt(\Ffi\Ptr $h, #[\Ffi\CType('int')] int $opt,
                        #[\Ffi\CType('long')] int $val): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_perform'), \Ffi\CType('int')]
function sp_curl_perform(\Ffi\Ptr $h): int {}

// The third argument is ALWAYS an out-pointer here, so it is declared \Ffi\Ptr.
#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_getinfo'), \Ffi\Variadic(2), \Ffi\CType('int')]
function sp_curl_getinfo(\Ffi\Ptr $h, #[\Ffi\CType('int')] int $info, \Ffi\Ptr $out): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_cleanup')]
function sp_curl_cleanup(\Ffi\Ptr $h): void {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_strerror')]
function sp_curl_strerror(#[\Ffi\CType('int')] int $c): \Ffi\Ptr {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_version_info')]
function sp_curl_version_info(#[\Ffi\CType('int')] int $age): \Ffi\Ptr {}

/** The side table a trampoline can reach with nothing but an int in hand. */
final class SpCurl
{
    /** @var array<int,string> id => accumulated body */
    public static array $body = [];
    /** @var array<int,int> id => number of write-callback invocations */
    public static array $calls = [];
}

/**
 * `size_t write_cb(char *buffer, size_t size, size_t nitems, void *outstream)`
 *
 * ⚠ NOTHING may throw out of here: a throw longjmps to the nearest PHP `try`,
 * which sits ABOVE libcurl's own frames, leaving its transfer state half
 * updated. Returning a count != size*nitems is the sanctioned way to fail — it
 * becomes CURLE_WRITE_ERROR (23) on the PHP side of the call.
 */
function sp_write_tramp(\Ffi\Ptr $buf, int $size, int $nmemb, \Ffi\Ptr $ud): int
{
    $n = $size * $nmemb;
    if ($n <= 0) { return 0; }
    $id = \ptr_to_int($ud);
    // COPY OUT FIRST. $buf is libcurl's own block and has no rc header, so it
    // must become a real headered string before any PHP code touches it.
    $s = \str_from_buffer($buf, $n);
    SpCurl::$body[$id] = (SpCurl::$body[$id] ?? '') . $s;
    SpCurl::$calls[$id] = (SpCurl::$calls[$id] ?? 0) + 1;
    return $n;
}

/** A body big enough to force libcurl to call back many times (16 KiB chunks). */
function sp_make_fixture(string $path, int $bytes): int
{
    $chunk = \str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 1024);   // 36 KiB
    $fh = \fopen($path, 'wb');
    $written = 0;
    while ($written < $bytes) {
        \fwrite($fh, $chunk);
        $written = $written + \strlen($chunk);
    }
    \fclose($fh);
    return $written;
}

$tmp = \sys_get_temp_dir() . '/mc_curl_spike.txt';
$want = \sp_make_fixture($tmp, 1048576);

$id = 1;
SpCurl::$body[$id] = '';
SpCurl::$calls[$id] = 0;

$h = \sp_curl_init();
$initOk = \ptr_to_int($h) !== 0;

// WRITEFUNCTION BEFORE WRITEDATA, always. If WRITEDATA held our int id while
// libcurl's default writer was still installed, it would fwrite() to a FILE*
// that is really the integer 1.
$rcFn  = \sp_curl_setopt($h, 20011 /*CURLOPT_WRITEFUNCTION*/, \ptr_to_int(\fn_to_ptr('sp_write_tramp')));
$rcDat = \sp_curl_setopt($h, 10001 /*CURLOPT_WRITEDATA*/,     $id);
$url   = 'file://' . $tmp;
$rcUrl = \sp_curl_setopt($h, 10002 /*CURLOPT_URL*/,           \str_bytes($url));

$rcRun = \sp_curl_perform($h);

// CURLINFO_SIZE_DOWNLOAD_T = CURLINFO_OFF_T(0x600000) + 8
$out = \sp_calloc(8, 1);
$rcInfo = \sp_curl_getinfo($h, 6291464, $out);
$dl = \peek_i64($out, 0);
\sp_free($out);

\sp_curl_cleanup($h);

$got   = SpCurl::$body[$id];
$calls = SpCurl::$calls[$id];

// A `long` through the same variadic slot. The NEGATIVE case is the real test:
// libcurl rejects a negative CURLOPT_TIMEOUT with CURLE_BAD_FUNCTION_ARGUMENT
// (43), so rc=43 is proof the value arrived as -1 and not as 4294967295 — the
// exact bug an unsigned vararg slot would produce, and one no positive value
// can detect.
$h2 = \sp_curl_init();
$rcPos = \sp_curl_setopt($h2, 13 /*CURLOPT_TIMEOUT*/, 7);
$rcNeg = \sp_curl_setopt($h2, 13 /*CURLOPT_TIMEOUT*/, -1);
$rcOff = \sp_curl_setopt($h2, 30120 /*CURLOPT_POSTFIELDSIZE_LARGE*/, -1);
\sp_curl_cleanup($h2);

// A const char* return, and an int argument that must NOT be zero-extended.
$err23 = \cstr_to_str(\sp_curl_strerror(23));
$err0  = \cstr_to_str(\sp_curl_strerror(0));

// The version struct, read at the offsets `offsetof` reported: age@0,
// version@8, version_num@16, features@32, protocols@64.
$vi   = \sp_curl_version_info(10);
$age  = \peek_i32($vi, 0);
$vnum = \peek_u32($vi, 16);
$prot = \peek_i64($vi, 64);
$hasFile = false;
$nProt = 0;
for ($i = 0; $i < 64; $i = $i + 1) {
    $p = \peek_i64(\int_to_ptr($prot), $i * 8);
    if ($p === 0) { break; }
    if (\cstr_to_str(\int_to_ptr($p)) === 'file') { $hasFile = true; }
    $nProt = $nProt + 1;
}

\unlink($tmp);

echo "init:      ", $initOk ? 'ok' : 'NULL', "\n";
echo "setopt rc: fn=", $rcFn, " data=", $rcDat, " url=", $rcUrl, "\n";
echo "perform:   ", $rcRun, "\n";
echo "getinfo:   rc=", $rcInfo, " size_download=", $dl === $want ? 'match' : ('MISMATCH ' . $dl), "\n";
echo "body:      len=", \strlen($got) === $want ? 'match' : ('MISMATCH ' . \strlen($got)), "\n";
echo "callbacks: ", $calls > 8 ? 'many' : ('too few: ' . $calls), "\n";
echo "head:      ", \substr($got, 0, 16), "\n";
echo "tail:      ", \substr($got, -16), "\n";
echo "long arg:  timeout=", $rcPos, " timeout(-1)=", $rcNeg, " postfieldsize(-1)=", $rcOff, "\n";
echo "strerror:  23=", $err23, " | 0=", $err0, "\n";
echo "version:   age>=4=", $age >= 4 ? 'yes' : 'no', " vnum>0=", $vnum > 0 ? 'yes' : 'no',
     " protocols=", $nProt > 4 ? 'many' : 'few', " file=", $hasFile ? 'yes' : 'no', "\n";
