<?php

// A `(string)` cast is a fresh +1 for every operand but a string — the consumers
// of a temp have always read it so ({@see EmitLlvm::isFreshStringTemp}). The
// conditional's ownership contract did not: its arm check treated the cast as
// a BORROW and retained the +1 again, so `isset($u['host']) ? (string)$u['host']
// : ''` (Http\WebSocket\connect()'s URL parsing) leaked the host once per call,
// and every ternary / `?:` / `??` / match arm over `(string)$int` leaked the
// minted digits.
// memory_get_usage() answers the peak RSS (ru_maxrss) here, which a leak can
// only raise; the bound is 3 MB over 100 000 calls. @serial: a memory
// measurement.

/** @param callable(int): int $body */
function measure(string $label, int $n, callable $body): void
{
    $sum = 0;
    for ($i = 0; $i < 200; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $before = memory_get_usage();
    for ($i = 0; $i < $n; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $growth = memory_get_usage() - $before;
    echo $label, ': sum=', $sum, ' ', $growth < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}

function host(string $url): int
{
    $u = parse_url($url);
    $h = isset($u['host']) ? (string)$u['host'] : '';
    return strlen($h);
}

function digits(int $i): int
{
    $s = $i % 2 === 0 ? (string)($i * 1000003) : 'odd';
    return strlen($s);
}

function elvis(int $i): int
{
    $s = (string)($i * 7919) ?: 'zero';
    return strlen($s);
}

/** @param array<string,mixed> $m */
function coalesce(array $m, int $i): int
{
    $s = $m['missing'] ?? (string)($i * 31337);
    return strlen((string)$s);
}

function matched(int $i): int
{
    $s = match ($i % 3) { 0 => (string)($i * 65537), 1 => 'one', default => (string)(float)$i };
    return strlen($s);
}

measure('ternary cast of an element', 100000, fn (int $i): int => host('ws://example' . ($i % 10) . '.test:80/p'));
measure('ternary cast of an int', 100000, fn (int $i): int => digits($i));
measure('elvis cast', 100000, fn (int $i): int => elvis($i));
$m = ['present' => 1];
measure('coalesce cast', 100000, fn (int $i): int => coalesce($m, $i));
measure('match cast', 100000, fn (int $i): int => matched($i));
echo host('ws://a.example:1/'), ' ', digits(4), ' ', elvis(0), ' ', coalesce($m, 2), ' ', matched(5), "\n";
