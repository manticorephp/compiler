<?php

// The DateTime class family. Runs under the php interpreter too, so difftest
// compares it 1:1. Fixed instants inside 1970..2026 only, and no zero-argument
// constructor (that would read the wall clock).

\date_default_timezone_set('UTC');
$utc = new DateTimeZone('UTC');
$kyiv = new DateTimeZone('Europe/Kyiv');
$ny = new DateTimeZone('America/New_York');

// --- DateTimeZone
foreach ([$utc, $kyiv, $ny] as $z) {
    echo $z->getName(), "\n";
}
$fixed = new DateTimeZone('+05:30');
echo $fixed->getName(), "\n";

// --- construction and formatting
foreach (['2017-07-14 16:01:07', '@1500000000', '2001-01-01', '1999-12-31 23:59:59'] as $s) {
    foreach ([$utc, $kyiv] as $z) {
        $d = new DateTime($s, $z);
        echo $s, " [", $z->getName(), "] ", $d->format('Y-m-d H:i:s T P e I'), " ts=", $d->getTimestamp(), " off=", $d->getOffset(), "\n";
    }
}

// --- setters
$d = new DateTime('2017-07-14 16:01:07', $utc);
echo "setDate ", $d->setDate(2020, 2, 29)->format('Y-m-d H:i:s'), "\n";
echo "setTime ", $d->setTime(1, 2, 3)->format('Y-m-d H:i:s'), "\n";
echo "setTimestamp ", $d->setTimestamp(1500000000)->format('Y-m-d H:i:s'), "\n";
$d2 = new DateTime('2017-01-01', $utc);
echo "setISODate ", $d2->setISODate(2017, 28, 5)->format('Y-m-d'), "\n";
echo "setDate overflow ", (new DateTime('2017-07-14', $utc))->setDate(2017, 13, 1)->format('Y-m-d'), "\n";

// --- modify: the same grammar strtotime uses
$mods = ['+1 day', '-1 day', '+1 month', '+1 year', 'next monday', 'last day of next month',
    '+2 weeks', 'tomorrow', 'midnight'];
foreach ($mods as $mod) {
    $x = new DateTime('2017-07-14 16:01:07', $utc);
    echo "modify ", $mod, " => ", $x->modify($mod)->format('Y-m-d H:i:s'), "\n";
}

// --- add / sub. Months SPILL: Jan 31 + 1 month is March 3.
$ivs = ['P1D', 'P1M', 'P1Y', 'P1Y2M3DT4H5M6S', 'PT1H', 'PT90M', 'P2W'];
foreach ($ivs as $spec) {
    $x = new DateTime('2001-01-31 10:00:00', $utc);
    echo "add ", $spec, " => ", $x->add(new DateInterval($spec))->format('Y-m-d H:i:s'), "\n";
    $y = new DateTime('2001-03-31 10:00:00', $utc);
    echo "sub ", $spec, " => ", $y->sub(new DateInterval($spec))->format('Y-m-d H:i:s'), "\n";
}

// --- DateInterval
foreach (['P1Y2M3DT4H5M6S', 'P2W', 'PT0S', 'P1D', 'PT36H'] as $spec) {
    $i = new DateInterval($spec);
    echo $spec, " y=", $i->y, " m=", $i->m, " d=", $i->d, " h=", $i->h, " i=", $i->i,
        " s=", $i->s, " invert=", $i->invert, " days=", \var_export($i->days, true), "\n";
}
$i = new DateInterval('P1Y2M3DT4H5M6S');
echo "fmt ", $i->format('%y %m %d %h %i %s %R %a %%'), "\n";
echo "fmt2 ", $i->format('%Y-%M-%D %H:%I:%S'), "\n";

// --- diff
$pairs = [['2017-01-01 00:00:00', '2018-03-15 12:30:45'], ['2020-02-29 00:00:00', '2021-03-01 00:00:00'],
    ['2017-07-14 16:00:00', '2017-07-14 16:00:00'], ['2018-03-15 12:30:45', '2017-01-01 00:00:00'],
    ['2001-01-31 00:00:00', '2001-03-01 00:00:00']];
foreach ($pairs as $p) {
    $a = new DateTime($p[0], $utc);
    $b = new DateTime($p[1], $utc);
    $f = $a->diff($b);
    echo "diff ", $p[0], " -> ", $p[1], " = ", $f->y, "y ", $f->m, "m ", $f->d, "d ",
        $f->h, "h ", $f->i, "i ", $f->s, "s days=", \var_export($f->days, true), " invert=", $f->invert, "\n";
    $g = $a->diff($b, true);
    echo "  absolute invert=", $g->invert, "\n";
}

// --- immutability: a mutator must NOT touch the original
$im = new DateTimeImmutable('2017-07-14 12:00:00', $utc);
$im2 = $im->modify('+1 year');
$im3 = $im->add(new DateInterval('P1M'));
$im4 = $im->setDate(2000, 1, 1);
$im5 = $im->setTime(0, 0, 0);
$im6 = $im->setTimezone($kyiv);
echo "orig  ", $im->format('Y-m-d H:i:s T'), "\n";
echo "mod   ", $im2->format('Y-m-d H:i:s T'), "\n";
echo "add   ", $im3->format('Y-m-d H:i:s T'), "\n";
echo "date  ", $im4->format('Y-m-d H:i:s T'), "\n";
echo "time  ", $im5->format('Y-m-d H:i:s T'), "\n";
echo "tz    ", $im6->format('Y-m-d H:i:s T'), "\n";
echo "orig again ", $im->format('Y-m-d H:i:s T'), "\n";

// A mutable DateTime, by contrast, changes in place.
$mu = new DateTime('2017-07-14 12:00:00', $utc);
$mu->modify('+1 year');
echo "mutable after modify ", $mu->format('Y-m-d H:i:s'), "\n";

// --- setTimezone keeps the instant and moves the wall clock
$t = new DateTime('2017-07-14 16:01:07', $utc);
echo "utc  ", $t->format('Y-m-d H:i:s T P'), " ts=", $t->getTimestamp(), "\n";
$t->setTimezone($kyiv);
echo "kyiv ", $t->format('Y-m-d H:i:s T P'), " ts=", $t->getTimestamp(), "\n";
$t->setTimezone($ny);
echo "ny   ", $t->format('Y-m-d H:i:s T P'), " ts=", $t->getTimestamp(), "\n";

// --- DatePeriod
$p = new DatePeriod(new DateTime('2017-01-01', $utc), new DateInterval('P1D'), new DateTime('2017-01-05', $utc));
foreach ($p as $k => $x) {
    echo "period ", $k, " ", $x->format('Y-m-d'), "\n";
}
$p2 = new DatePeriod(new DateTime('2017-01-01', $utc), new DateInterval('P1D'), 3);
foreach ($p2 as $k => $x) {
    echo "recur ", $k, " ", $x->format('Y-m-d'), "\n";
}
$p3 = new DatePeriod(new DateTime('2017-01-01', $utc), new DateInterval('P1M'), 3);
foreach ($p3 as $k => $x) {
    echo "month ", $k, " ", $x->format('Y-m-d'), "\n";
}
$p4 = new DatePeriod(new DateTime('2017-01-01', $utc), new DateInterval('P1D'),
    new DateTime('2017-01-05', $utc), DatePeriod::EXCLUDE_START_DATE);
foreach ($p4 as $k => $x) {
    echo "exclstart ", $k, " ", $x->format('Y-m-d'), "\n";
}

// --- class constants
echo DateTime::ATOM, "\n";
echo DateTime::RFC2822, "\n";
echo (new DateTime('2017-07-14 16:01:07', $utc))->format(DateTime::ATOM), "\n";
echo (new DateTime('2017-07-14 16:01:07', $utc))->format(DateTime::RFC2822), "\n";

// --- instanceof across the family
$d = new DateTime('2017-07-14', $utc);
$i = new DateTimeImmutable('2017-07-14', $utc);
echo "dt instanceof DateTimeInterface ", $d instanceof DateTimeInterface ? 'yes' : 'no', "\n";
echo "im instanceof DateTimeInterface ", $i instanceof DateTimeInterface ? 'yes' : 'no', "\n";
echo "dt instanceof DateTime ", $d instanceof DateTime ? 'yes' : 'no', "\n";
echo "im instanceof DateTime ", $i instanceof DateTime ? 'yes' : 'no', "\n";

// diff accepts either side of the interface
echo "cross diff days=", \var_export($d->diff($i)->days, true), "\n";
