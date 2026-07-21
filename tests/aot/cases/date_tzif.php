<?php

// TZif reader. Manticore-only (calls __mc_* stdlib helpers), so difftest skips
// it. Every instant here is inside 1970..2026, the window where the system tz
// database and the php interpreter's bundled one agree exactly.

$zones = ['UTC', 'Europe/Kyiv', 'America/New_York', 'Australia/Lord_Howe',
    'Asia/Kathmandu', 'Pacific/Kiritimati', 'America/Sao_Paulo', 'Asia/Tokyo'];
foreach ($zones as $zn) {
    $zid = \__mc_tz_open($zn);
    echo $zn, " zid>=0 ", $zid >= 0 ? "ok" : "BAD", "\n";
}

// Offset / DST / abbreviation at fixed instants.
$probe = [
    ['Europe/Kyiv', 1234567890],     // 2009-02-14 winter
    ['Europe/Kyiv', 1500000000],     // 2017-07-14 summer
    ['America/New_York', 1234567890],
    ['America/New_York', 1500000000],
    ['Asia/Kathmandu', 1500000000],  // +05:45, never DST
    ['Australia/Lord_Howe', 1500000000], // 30-minute DST step
    ['Pacific/Kiritimati', 1500000000],  // +14
    ['America/Sao_Paulo', 1500000000],
    ['Asia/Tokyo', 1500000000],
    ['UTC', 0],
];
foreach ($probe as $p) {
    $zid = \__mc_tz_open($p[0]);
    echo $p[0], " @", $p[1], " off=", \__mc_tz_offset($zid, $p[1]),
        " dst=", \__mc_tz_isdst($zid, $p[1]),
        " abbr=", \__mc_tz_abbr($zid, $p[1]), "\n";
}

// The exact instants either side of a DST switch (Kyiv, 2017-03-26 03:00 local).
$k = \__mc_tz_open('Europe/Kyiv');
echo "kyiv before=", \__mc_tz_offset($k, 1490490000 - 1), " after=", \__mc_tz_offset($k, 1490490000), "\n";

// Local -> UTC. The fold takes the first (still-DST) occurrence; the gap moves
// the wall clock forward by using the pre-transition offset.
$ny = \__mc_tz_open('America/New_York');
$gap = \__mc_days_from_civil(2019, 3, 10) * 86400 + 2 * 3600 + 1800;   // never happened
$fold = \__mc_days_from_civil(2019, 11, 3) * 86400 + 1 * 3600 + 1800;  // happened twice
$plain = \__mc_days_from_civil(2019, 6, 15) * 86400 + 12 * 3600;
echo "ny gap  ", \__mc_tz_localtoutc($ny, $gap), "\n";
echo "ny fold ", \__mc_tz_localtoutc($ny, $fold), "\n";
echo "ny mid  ", \__mc_tz_localtoutc($ny, $plain), "\n";

// Round-trip: UTC -> local -> UTC is the identity outside a gap or fold.
$bad = 0;
foreach ($zones as $zn) {
    $zid = \__mc_tz_open($zn);
    for ($ts = 100000000; $ts < 1700000000; $ts = $ts + 7776001) {
        $local = $ts + \__mc_tz_offset($zid, $ts);
        if (\__mc_tz_localtoutc($zid, $local) !== $ts) {
            $bad = $bad + 1;
        }
    }
}
echo "roundtrip bad=", $bad, "\n";

// Abbreviation packing is the trick that keeps the registry integer-only.
foreach (['EEST', 'GMT', 'LMT', '+0330', '-03', 'ACWST'] as $s) {
    $p = \__mc_tz_packabbr($s . "\0", 0);
    echo "pack ", $s, " -> ", $p, " -> ", \__mc_tz_unpackabbr($p),
        " ", \__mc_tz_unpackabbr($p) === $s ? "ok" : "BAD", "\n";
}

// Bad names must be rejected, not fed to the filesystem.
foreach (['../etc/passwd', '/etc/passwd', 'No/Such_Zone', ''] as $n) {
    echo "reject ", $n === '' ? '(empty)' : $n, " -> ", \__mc_tz_open($n), "\n";
}
