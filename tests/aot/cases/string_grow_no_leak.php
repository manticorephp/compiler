<?php

// A string handed out of a property and then reset — `$r = $c->out;
// $c->out = ''; return $r;`, the deflate core's last three lines — kept one
// buffer per call: a string property read was a bare borrow, so every such read
// vetoed the slot's release-before-overwrite for the whole class, and the
// overwrite then released nothing. gzdeflate lost its whole output buffer that
// way on every call. The read now co-owns what it reads. The last rows check
// that it is still a VALUE: the local keeps the old string after the slot is
// overwritten. memory_get_usage() answers the peak RSS (ru_maxrss) here, which a
// leak can only raise; the bound is 3 MB. @serial: a memory measurement.

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

final class Sink
{
    public string $out = '';
    public int $bb = 0;
    public int $bc = 0;
}

function put(Sink $c, int $bits, int $n): void
{
    $c->bb = $c->bb | ($bits << $c->bc);
    $c->bc = $c->bc + $n;
    while ($c->bc >= 8) {
        $c->out = $c->out . \chr($c->bb & 0xFF);
        $c->bb = $c->bb >> 8;
        $c->bc = $c->bc - 8;
    }
}

function drain(Sink $c, int $n): string
{
    $c->out = '';
    for ($i = 0; $i < $n; $i++) { put($c, $i & 0x1FF, 9); }
    $r = $c->out;
    $c->out = '';
    return $r;
}

measure('handed out and reset', 20000, function (int $i): int {
    return \strlen(drain(new Sink(), 3000 + $i % 3));
});

$kept = new Sink();
measure('handed out and reset, one sink', 20000, function (int $i) use ($kept): int {
    return \strlen(drain($kept, 3000 + $i % 3));
});

function grow(int $n): string
{
    $out = \str_repeat("\0", 64);
    $olen = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($olen >= \strlen($out)) { $out = $out . \str_repeat("\0", \strlen($out)); }
        $out[$olen] = \chr($i & 0xFF);
        $olen++;
    }
    return \substr($out, 0, $olen);
}

measure('offset writes into a concat-grown local', 20000, function (int $i): int {
    return \strlen(grow(3000 + $i % 3));
});

function append(Sink $c, int $n): int
{
    for ($i = 0; $i < $n; $i++) { $c->out = $c->out . 'ab'; }
    $len = \strlen($c->out);
    $c->out = '';
    return $len;
}

$acc = new Sink();
measure('property append in a loop', 20000, function (int $i) use ($acc): int {
    return append($acc, 1500 + $i % 3);
});

$s = new Sink();
$s->out = \str_repeat('old', 3);
$snap = $s->out;
$s->out = 'new';
$now = $s->out;
echo $snap, ' ', $now, "\n";
$s->out = $s->out . '!';
$snap2 = $s->out;
$s->out = '';
$s->out = $s->out . 'x';
$now = $s->out;
echo $snap2, ' ', $now, "\n";
echo "done\n";
