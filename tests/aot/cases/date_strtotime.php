<?php

// strtotime(). Runs under the php interpreter too, so difftest compares 1:1.
// Every call passes an EXPLICIT base timestamp -- the one-argument form reads
// the wall clock and could never be compared.

\date_default_timezone_set('UTC');

$bases = [1500000000, 1580515200, 1609459199];

$absolute = ['2017-07-14', '2017-07-14 16:01:07', '2017-07-14T16:01:07',
    '2017-07-14T16:01:07+02:00', '2017-07-14T16:01:07-0500', '2017-07-14 16:01:07 UTC',
    '@1500000000', '@0', '@-86400', '2017/07/14', '7/14/2017', '14.07.2017', '14-07-2017',
    '14 July 2017', 'July 14, 2017', 'jul 14 2017', 'July 2017', 'january 2018',
    '2017-W28-5', '2017-W01-1', '20170714', '16:01:07', '16:01', '4pm', '12am', '12pm',
    '11:59:59pm', '1999-12-31 23:59:59'];
foreach ($absolute as $c) {
    echo $c, " => ", \var_export(\strtotime($c, 1500000000), true), "\n";
}

// Relative. The application ORDER is what these pin down.
$relative = ['+1 day', '-1 day', '+1 week', '+1 month', '-1 month', '+1 year',
    '+3 days', '+10 hours', '+30 minutes', '+45 seconds', '+1 fortnight',
    'tomorrow', 'yesterday', 'today', 'midnight', 'noon',
    'monday', 'next monday', 'last monday', 'this monday', 'sunday', 'next sunday',
    'first monday', 'second monday', 'third tuesday',
    'next week', 'last week', 'this week', 'next month', 'last month',
    'next year', 'last year', '3 days ago', '1 week ago', '2 months ago',
    '+1 month 2 days', '+1 week 2 days', '+1 year 2 months 3 days'];
foreach ($bases as $b) {
    echo "-- base ", $b, "\n";
    foreach ($relative as $c) {
        echo $c, " => ", \var_export(\strtotime($c, $b), true), "\n";
    }
}

// The month-overflow spill: relative months are added BEFORE the day is
// normalized, so Jan 31 + 1 month is March 3, not February 28.
foreach (['2001-01-31 +1 month', '2001-01-31 +2 months', '2024-01-31 +1 month',
    '2024-02-29 +1 year', '2001-03-31 -1 month'] as $c) {
    echo $c, " => ", \date('Y-m-d H:i:s', (int)\strtotime($c, 1500000000)), "\n";
}

// "first/last day of" pins the day INSIDE the target month, before the
// overflow normalize, and discards any relative day component.
foreach (['first day of', 'last day of', 'first day of next month', 'last day of next month',
    'last day of this month', 'last day of last month', 'first day of january 2018',
    'last day of february 2024', 'last day of february 2023',
    'last day of next month +2 weeks'] as $c) {
    foreach ([1500000000, 1580515200] as $b) {
        echo $c, " @", $b, " => ", \date('Y-m-d H:i:s', (int)\strtotime($c, $b)), "\n";
    }
}

// Garbage must be false, not a wrong number.
foreach (['not a date', '', 'week', 'first week', 'zzz', '14th of July'] as $c) {
    echo \var_export($c, true), " => ", \var_export(\strtotime($c, 1500000000), true), "\n";
}

// Across zones: the local -> UTC conversion happens LAST, so a relative day
// preserves the WALL CLOCK across a DST boundary rather than adding 86400s.
foreach (['America/New_York', 'Europe/Kyiv', 'Australia/Lord_Howe'] as $zn) {
    \date_default_timezone_set($zn);
    echo "== ", $zn, "\n";
    foreach (['2019-03-09 12:00:00 +1 day', '2019-11-02 12:00:00 +1 day',
        '2017-07-14 12:00:00', 'next monday', '+1 month'] as $c) {
        $t = \strtotime($c, 1500000000);
        echo $c, " => ", \var_export($t, true), " ", \date('Y-m-d H:i:s T', (int)$t), "\n";
    }
}
