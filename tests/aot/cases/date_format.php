<?php

// date()/gmdate()/mktime() and friends. This case runs under the php
// interpreter too, so difftest compares it 1:1.
//
// Determinism rules: an explicit default timezone (never the ambient one --
// the php CLI defaults to UTC while a native binary reads /etc/localtime), no
// time()/"now", and every instant inside 1970..2026, the window where the
// system tz database and php's bundled copy agree exactly.

$stamps = [0, 86399, 978307200, 1104537600, 1234567890, 1500000000, 1600000000, 1700000000];
$zones = ['UTC', 'Europe/Kyiv', 'America/New_York', 'Asia/Kathmandu', 'Australia/Lord_Howe'];

foreach ($zones as $zn) {
    \date_default_timezone_set($zn);
    echo "== ", $zn, " tz=", \date_default_timezone_get(), "\n";
    foreach ($stamps as $ts) {
        echo \date('Y-m-d H:i:s T P e I Z', $ts), "\n";
    }
}

\date_default_timezone_set('Europe/Kyiv');

// Every specifier at one instant, so a regression names itself.
$specs = ['d', 'D', 'j', 'l', 'N', 'S', 'w', 'z', 'W', 'o', 'F', 'm', 'M', 'n', 't',
    'L', 'X', 'x', 'Y', 'y', 'a', 'A', 'B', 'g', 'G', 'h', 'H', 'i', 's', 'u', 'v',
    'e', 'I', 'O', 'P', 'p', 'T', 'Z', 'c', 'r', 'U'];
foreach ($specs as $sp) {
    echo $sp, "=", \date($sp, 1500000000), "\n";
}

// The specifiers that disagree with their neighbours at a year boundary.
foreach ([1609459199, 1609459200, 1577836800, 1451606400] as $ts) {
    echo "isoweek ", \date('Y-m-d o-\WW N z L t', $ts), "\n";
}

// Backslash escapes are a scanner concern, not a replace pass.
echo \date('\\Y-m-d', 1500000000), "\n";
echo \date('l jS \\o\\f F Y h:i:s A', 1500000000), "\n";
echo \date('\\\\Y', 1500000000), "\n";

// gmdate: 'T' is GMT while 'e' is UTC -- they genuinely disagree.
foreach ($stamps as $ts) {
    echo \gmdate('Y-m-d H:i:s T e P', $ts), "\n";
}

// mktime, including out-of-range fields that must normalize.
foreach ($zones as $zn) {
    \date_default_timezone_set($zn);
    foreach ([[0, 0, 0, 1, 1, 2000], [12, 30, 45, 7, 14, 2017], [0, 0, 0, 13, 1, 2001],
        [0, 0, 0, 1, 0, 2001], [0, 0, 0, 2, 31, 2001], [23, 59, 59, 12, 31, 1999],
        [2, 30, 0, 3, 10, 2019], [1, 30, 0, 11, 3, 2019]] as $a) {
        echo "mktime ", $zn, " ", \mktime($a[0], $a[1], $a[2], $a[3], $a[4], $a[5]), "\n";
    }
}
\date_default_timezone_set('UTC');
foreach ([[0, 0, 0, 1, 1, 2000], [0, 0, 0, 13, 1, 2001], [0, 0, 0, 3, 0, 2024]] as $a) {
    echo "gmmktime ", \gmmktime($a[0], $a[1], $a[2], $a[3], $a[4], $a[5]), "\n";
}

// checkdate: the leap rule at the century boundaries.
foreach ([[2, 29, 2000], [2, 29, 1900], [2, 29, 2024], [2, 30, 2024], [13, 1, 2000],
    [0, 1, 2000], [1, 0, 2000], [12, 31, 9999]] as $c) {
    echo "checkdate ", $c[0], "/", $c[1], "/", $c[2], " ", \checkdate($c[0], $c[1], $c[2]) ? 'true' : 'false', "\n";
}

// getdate / localtime build their arrays at the return site.
\date_default_timezone_set('Europe/Kyiv');
$g = \getdate(1500000000);
echo "getdate ", $g['year'], "-", $g['mon'], "-", $g['mday'], " ", $g['hours'], ":", $g['minutes'], ":", $g['seconds'],
    " wday=", $g['wday'], " yday=", $g['yday'], " ", $g['weekday'], " ", $g['month'], " ts=", $g[0], "\n";
$l = \localtime(1500000000);
echo "localtime ", \implode(',', $l), "\n";
$la = \localtime(1500000000, true);
echo "localtime_assoc ", $la['tm_year'], " ", $la['tm_mon'], " ", $la['tm_mday'], " ", $la['tm_isdst'], "\n";

// idate returns an int, so leading zeros are gone.
echo "idate ", \idate('Y', 1500000000), " ", \idate('m', 1500000000), " ", \idate('d', 1500000000), "\n";
