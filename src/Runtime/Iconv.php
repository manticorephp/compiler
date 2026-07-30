<?php

namespace Runtime\Iconv;

use Ffi\Library;
use Ffi\Symbol;
use Ffi\CType;
use Ffi\Ptr;

/**
 * Host libiconv — character-set conversion.
 *
 * Bound rather than reimplemented for one reason: `//TRANSLIT`. symfony/string's
 * ascii() throws a LogicException without a translit-able iconv, and building a
 * transliteration table by hand would be a worse copy of what both Darwin's
 * libiconv and glibc already ship.
 *
 * On Darwin the symbols are plain `iconv_open` / `iconv` / `iconv_close`
 * (verified against the SDK header — no `libiconv_*` prefix macro), and the
 * link needs `-liconv`. On glibc they live in libc itself and the flag must NOT
 * be passed; see `iconv_link_flags()` in Main.php.
 *
 * The handle is carried as a raw int address, like pcre2's, so the failure
 * sentinel `(iconv_t)-1` is a plain integer comparison.
 */

/** `iconv_t iconv_open(const char *tocode, const char *fromcode)` */
#[Library('iconv'), Symbol('iconv_open')]
function iconv_open(string $tocode, string $fromcode): int {}

/**
 * `size_t iconv(iconv_t cd, char **inbuf, size_t *inbytesleft,
 *               char **outbuf, size_t *outbytesleft)`
 *
 * All four buffer arguments are IN/OUT pointers-to-pointer: iconv advances
 * them, and the caller reads them back to learn how much moved. So they are
 * caller-allocated scratch cells, exactly as socket_recvfrom's socklen_t is.
 * Returns the number of irreversible conversions, or (size_t)-1 on error.
 */
#[Library('iconv'), Symbol('iconv')]
function iconv_convert(int $cd, Ptr $inbuf, Ptr $inbytesleft, Ptr $outbuf, Ptr $outbytesleft): int {}

/**
 * `int iconv_close(iconv_t cd)`
 *
 * ⚠ Function-level `#[CType('int')]`: a C `int` return is written into w0 only,
 * so without the forced sign-extension `-1` reads back as 4294967295.
 */
#[Library('iconv'), Symbol('iconv_close'), CType('int')]
function iconv_close(int $cd): int {}
