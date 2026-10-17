<?php
// every date() specifier, escapes, empty format, zones with DST, epoch edges
$fmt = 'd D j l N S w z W F m M n t L o Y y a A B g G h H i s u v e I O P p T Z c r U';
foreach (['UTC', 'Europe/Kyiv', 'America/New_York', 'Asia/Kolkata', 'Australia/Lord_Howe', 'Pacific/Apia'] as $tz) {
    date_default_timezone_set($tz);
    echo $tz, "\n";
    foreach ([0, -1, 1, 86399, 951782400, 1710054000, 1710057599, 1711846800, 1711850400, 1730000000, 1730613600, 1730617200,
              2147483647, 2147483648, -2147483648, -62135596800, 253402300799, 4102444800] as $ts) {
        echo $ts, ' ', date($fmt, $ts), "\n";
        echo gmdate($fmt, $ts), "\n";
    }
}
var_dump(date(''));
date_default_timezone_set('UTC');
echo date('\Y\m\d \\\\ Y', 86400), "|\n";
echo date('jS', 86400 * 0), date(' jS', 86400 * 1), date(' jS', 86400 * 2), date(' jS', 86400 * 10), date(' jS', 86400 * 20), "\n";
echo date('Y-m-d H:i:s', 0), ' ', idate('y', 86400 * 366 * 30), "\n";
echo date('x X', 0), "\n";
echo date('[Q%]', 5), "\n";
