<?php

// An FFI function returning \Ffi\Ptr whose result is DISCARDED must not be
// rc-released. A Ptr is a raw foreign address with no rc header: the word at
// ptr-8 belongs to the allocator, so a release decrements malloc's own metadata
// and, on reaching zero, hands the block to the string pool. The damage is
// silent until a later free() trips a libmalloc assertion, and whether it fires
// depends on what malloc happened to leave in that word — this crashed roughly
// 85% of runs, and vanished under lldb and whenever the result was assigned to
// a variable (an extra alloca perturbs the layout).
//
// Every Ptr-returning binding is exercised as a bare statement, then the buffer
// is freed: the free is what detects the corruption.

$b = \Runtime\Libc\calloc(64, 1);
\Runtime\Libc\memset($b, 65, 8);
echo "memset ok\n";
\Runtime\Libc\free($b);
echo "free ok\n";

$b2 = \Runtime\Libc\malloc(64);
\Runtime\Libc\memset($b2, 0, 64);
\Runtime\Libc\strcpy($b2, 'hello');
echo cstr_to_str($b2), "\n";
\Runtime\Libc\strcat($b2, ' world');
echo cstr_to_str($b2), "\n";
\Runtime\Libc\free($b2);
echo "free2 ok\n";

$src = \Runtime\Libc\calloc(16, 1);
$dst = \Runtime\Libc\calloc(16, 1);
\Runtime\Libc\strcpy($src, 'abc');
\Runtime\Libc\memcpy($dst, $src, 4);
echo cstr_to_str($dst), "\n";
\Runtime\Libc\free($src);
\Runtime\Libc\free($dst);
echo "free3 ok\n";

// Many discard-then-free rounds: a single leaked release can go unnoticed, a
// loop makes the corruption overwhelmingly likely.
for ($i = 0; $i < 200; $i = $i + 1) {
    $p = \Runtime\Libc\calloc(17 + $i, 1);
    \Runtime\Libc\memset($p, 66, 8);
    \Runtime\Libc\strcpy($p, 'x');
    \Runtime\Libc\free($p);
}
echo "loop ok\n";
echo "-- done --\n";
