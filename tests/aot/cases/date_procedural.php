<?php

// The procedural aliases and the timezone tables. Runs under the php
// interpreter too, so difftest compares it 1:1.

\date_default_timezone_set('UTC');

// --- procedural construction and access
$d = \date_create('2017-07-14 16:01:07', \timezone_open('UTC'));
echo \date_format($d, 'Y-m-d H:i:s T'), "\n";
echo \date_timestamp_get($d), "\n";
echo \date_offset_get($d), "\n";
echo \timezone_name_get(\date_timezone_get($d)), "\n";

\date_modify($d, '+1 day');
echo "modify ", \date_format($d, 'Y-m-d'), "\n";
\date_add($d, new DateInterval('P1M'));
echo "add ", \date_format($d, 'Y-m-d'), "\n";
\date_sub($d, new DateInterval('P1Y'));
echo "sub ", \date_format($d, 'Y-m-d'), "\n";
\date_date_set($d, 2020, 2, 29);
echo "dateset ", \date_format($d, 'Y-m-d'), "\n";
\date_time_set($d, 1, 2, 3);
echo "timeset ", \date_format($d, 'H:i:s'), "\n";
\date_isodate_set($d, 2017, 28, 5);
echo "isoset ", \date_format($d, 'Y-m-d'), "\n";
\date_timestamp_set($d, 1500000000);
echo "tsset ", \date_format($d, 'Y-m-d H:i:s'), "\n";

$a = \date_create('2017-01-01', \timezone_open('UTC'));
$b = \date_create('2018-03-15', \timezone_open('UTC'));
$iv = \date_diff($a, $b);
echo "diff ", \date_interval_format($iv, '%y %m %d %R'), " days=", \var_export($iv->days, true), "\n";

$im = \date_create_immutable('2017-07-14', \timezone_open('UTC'));
echo "immutable ", \date_format($im, 'Y-m-d'), "\n";
$cf = \date_create_from_format('d/m/Y', '14/07/2017', \timezone_open('UTC'));
echo "fromformat ", $cf === false ? 'FALSE' : \date_format($cf, 'Y-m-d'), "\n";

// --- timezone tables
$tz = \timezone_open('Europe/Kyiv');
echo "offset ", \timezone_offset_get($tz, \date_create('2017-07-14', \timezone_open('UTC'))), "\n";

$ids = \timezone_identifiers_list();
echo "identifiers ", \count($ids), "\n";
echo "first ", $ids[0], "\n";
foreach (['Europe/Kyiv', 'America/New_York', 'UTC', 'Asia/Tokyo'] as $z) {
    echo "  has ", $z, " ", \in_array($z, $ids) ? 'yes' : 'no', "\n";
}

// A DST year in Kyiv: the leading row is the state AT the begin timestamp,
// then each real switch.
$tr = \timezone_transitions_get($tz, 1483228800, 1514764800);
echo "transitions ", \count($tr), "\n";
foreach ($tr as $t) {
    echo "  ", $t['ts'], " ", $t['offset'], " ", $t['abbr'], " ", $t['isdst'] ? 1 : 0, "\n";
}

$loc = \timezone_location_get($tz);
echo "country ", $loc['country_code'], "\n";
echo "comments ", $loc['comments'], "\n";

// --- interval helpers
$i2 = \date_interval_create_from_date_string('2 days');
echo "fromdatestring d=", $i2->d, "\n";
echo "fmt ", \date_interval_format(new DateInterval('P1Y2M3DT4H5M6S'), '%y-%m-%d %h:%i:%s'), "\n";
