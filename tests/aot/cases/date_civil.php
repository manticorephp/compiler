<?php

// Civil calendar core. Manticore-only (calls __mc_* stdlib helpers), so
// difftest skips it. Prints a checksum rather than 292k lines so `expected`
// stays one screen.

$fail = 0;
$chk = 0;
$z0 = \__mc_days_from_civil(1600, 1, 1);
$z1 = \__mc_days_from_civil(2400, 1, 1);
for ($z = $z0; $z <= $z1; $z++) {
    $p = \__mc_civil_from_days($z);
    $y = \__mc_civ_y($p);
    $m = \__mc_civ_m($p);
    $d = \__mc_civ_d($p);
    if (\__mc_days_from_civil($y, $m, $d) !== $z) {
        $fail = $fail + 1;
    }
    $chk = ($chk + $y * 31 + $m * 7 + $d) % 1000000007;
}
echo "roundtrip fail=", $fail, " chk=", $chk, "\n";

// Floor division: the pre-1970 correctness gate.
echo "fdiv ", \__mc_fdiv(-1, 400), " ", \__mc_fdiv(-400, 400), " ", \__mc_fdiv(-401, 400), " ", \__mc_fdiv(7, 2), "\n";
echo "fmod ", \__mc_fmod(-1, 7), " ", \__mc_fmod(-7, 7), " ", \__mc_fmod(8, 7), "\n";

// Day-of-week anchors.
echo "dow ", \__mc_dow(\__mc_days_from_civil(1970, 1, 1)), " ",
    \__mc_dow(\__mc_days_from_civil(2000, 1, 1)), " ",
    \__mc_dow(\__mc_days_from_civil(2026, 7, 20)), "\n";

// Leap rule at the century boundaries.
echo "leap ", \__mc_is_leap(1900) ? 1 : 0, \__mc_is_leap(2000) ? 1 : 0,
    \__mc_is_leap(2024) ? 1 : 0, \__mc_is_leap(2023) ? 1 : 0, "\n";
echo "dim ", \__mc_days_in_month(2000, 2), " ", \__mc_days_in_month(1900, 2), " ",
    \__mc_days_in_month(2024, 2), " ", \__mc_days_in_month(2023, 4), "\n";

// ISO week boundaries — where `o` and `Y` disagree.
$iso = [[2020, 12, 31, 2020, 53], [2021, 1, 1, 2020, 53], [2019, 12, 30, 2020, 1],
    [2016, 1, 1, 2015, 53], [2015, 12, 28, 2015, 53], [2026, 7, 20, 2026, 30]];
foreach ($iso as $t) {
    $w = \__mc_iso_week($t[0], $t[1], $t[2]);
    echo $t[0], "-", $t[1], "-", $t[2], " => ", \__mc_iso_y($w), "-W", \__mc_iso_w($w),
        " ", (\__mc_iso_y($w) === $t[3] && \__mc_iso_w($w) === $t[4]) ? "ok" : "BAD", "\n";
}

// Day of year, 1-based here (date('z') is 0-based).
echo "doy ", \__mc_doy(2024, 3, 1), " ", \__mc_doy(2023, 3, 1), " ", \__mc_doy(2024, 12, 31), "\n";

// Normalization. Month carries into the year BEFORE the day spills, which is
// what makes "Jan 31 + 1 month" land on March 3.
$cases = [[2001, 2, 31], [2001, 13, 1], [2001, 1, 0], [2001, 0, 1], [2024, 2, 30], [2000, 25, 45]];
foreach ($cases as $c) {
    echo $c[0], "-", $c[1], "-", $c[2], " => ",
        \__mc_norm_ymd($c[0], $c[1], $c[2], 0), "-",
        \__mc_norm_ymd($c[0], $c[1], $c[2], 1), "-",
        \__mc_norm_ymd($c[0], $c[1], $c[2], 2), "\n";
}

// Pre-epoch and negative years round-trip.
$old = [[1969, 12, 31], [1900, 1, 1], [1, 1, 1], [-1, 12, 31], [-400, 2, 29]];
foreach ($old as $t) {
    $z = \__mc_days_from_civil($t[0], $t[1], $t[2]);
    $p = \__mc_civil_from_days($z);
    echo $t[0], "-", $t[1], "-", $t[2], " z=", $z, " ",
        (\__mc_civ_y($p) === $t[0] && \__mc_civ_m($p) === $t[1] && \__mc_civ_d($p) === $t[2]) ? "ok" : "BAD", "\n";
}
