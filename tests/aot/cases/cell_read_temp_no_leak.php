<?php

// Every consumer of a value read out of a cell (`mixed`) channel must give
// back what the read retained: a concat operand, a `??` that hands out +1
// from its arm, a builtin argument, a numeric cast of a fresh string. Each
// shape below stranded one string per iteration — a compat-mode handler
// reading `$_GET['a'] ?? '-'` into its body leaked ~255 B per request and
// the Linux gate measured it linear 20k → 40k. The values are MINTED per
// iteration (a literal is immortal and a stranded retain on it costs no
// memory, only a count) in a function of their OWN, so the reader sees the
// cell channel (`mixed` values, as Sapi\\requestBegin seeds them) and not a
// store its own body narrowed to string. memory_get_usage() answers
// the peak RSS (ru_maxrss) here, which a leak can only raise: 200 000
// iterations of an 80-byte leak are 16 MB, the bound is 3 MB. @serial: a
// memory measurement.

/** @param callable(int): int $body */
function measure(string $label, callable $body): void
{
    $sum = 0;
    for ($i = 0; $i < 2000; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $before = memory_get_usage();
    for ($i = 0; $i < 200000; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $growth = memory_get_usage() - $before;
    echo $label, ': sum=', $sum, ' ', $growth < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}

$line = 'Content-Length: 14';

/** A query value the way the server seeds it: a `mixed`, minted per request. */
function value(string $tag, int $i): mixed
{
    return substr('x' . $tag . $i, 1);
}

/** The reset value, typed the way Sapi\\Context::$empty is. */
final class Ctx
{
    /** @var array<string,mixed> */
    public static array $empty = [];
}

/** Seed the way Manticore\\Sapi\\requestBegin does: reset, then element stores. */
function seed(int $i): void
{
    $_GET = Ctx::$empty;
    $_GET['a'] = value('qA', $i);
    $_GET['b'] = value('qB', $i);
}

measure('concat cell', function (int $i): int {
    seed($i);
    $r = 'p' . $_GET['a'] . $i;
    return strlen($r);
});
measure('concat coalesce', function (int $i): int {
    seed($i);
    $r = 'p' . ($_GET['a'] ?? '-') . ($_GET['zz'] ?? substr('dflt', 1));
    return strlen($r);
});
measure('append cell', function (int $i): int {
    seed($i);
    $r = 'p';
    $r .= $_GET['b'];
    $r .= ($_GET['a'] ?? '-');
    return strlen($r);
});
measure('builtin coalesce', function (int $i): int {
    seed($i);
    return strlen($_GET['a'] ?? '-') + strlen(trim($_GET['b'] ?? '-'));
});
measure('cast coalesce', function (int $i): int {
    seed($i);
    $s = (string)($_GET['a'] ?? '-');
    return strlen($s);
});
measure('int cast of temp', function (int $i) use ($line): int {
    return (int)trim(substr($line, 15)) + intval(substr($line, 16)) + (int)(float)substr($line, 16);
});
echo "done\n";
