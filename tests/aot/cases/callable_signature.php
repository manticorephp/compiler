<?php

// `@param callable(int): int` — the callable's signature spelled out.
//
// docTagType scanned a type token as "up to the first space", angle-aware but not
// paren-aware, so this hint arrived truncated to `callable(int):` and resolved to
// nothing. While docblocks were only ever consulted to refine a bare `array` that
// was invisible; once a docblock became the type of an un-hinted param, it
// SIGSEGV'd. The token now keeps its parens and its `: R` tail.
//
// The invoke result is typed from the signature. The value still ARRIVES as a
// tagged cell — a dynamically-dispatched closure has a uniform ABI and cannot know
// its caller — so this buys typing, not fewer boxing ops.

/** @param callable(int): int $f */
function withHint(callable $f, int $x): int { return $f($x) + 1; }

/**
 * @param callable(int): int $f
 * @param int $x
 * @return int
 */
function noHint($f, $x) { return $f($x) + 1; }

/** @param callable $f */
function bare($f, int $x): int { return $f($x) + 1; }

/** @param callable(string, string): string $j */
function join2($j, string $a, string $b): string { return $j($a, $b); }

/** @param callable(): int $z */
function nullary($z): int { return $z(); }

$double = fn(int $n): int => $n * 2;
$triple = fn(int $n): int => $n * 3;

echo withHint($double, 20), "\n";
echo noHint($double, 20), "\n";
echo bare($double, 20), "\n";
echo join2(fn(string $a, string $b): string => $a . '-' . $b, 'x', 'y'), "\n";
echo nullary(fn(): int => 7), "\n";

// through an opaque boundary, so the closure isn't a literal at the invoke
$t = 0;
for ($i = 0; $i < 4; $i++) {
    $pick = ($i & 1) === 0 ? $double : $triple;
    $t = $t + noHint($pick, $i);
}
echo $t, "\n";
