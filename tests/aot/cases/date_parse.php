<?php

// date_parse / date_parse_from_format. Runs under the php interpreter too.
// Absent fields are reported as false, which is what makes the shape
// heterogeneous -- and why the row is assembled in the prelude.

\date_default_timezone_set('UTC');

$inputs = ['2017-07-14 16:01:07', '2017-07-14', '16:01:07', '2017-07-14T16:01:07+02:00',
    '@1500000000', 'July 14, 2017'];
foreach ($inputs as $in) {
    $r = \date_parse($in);
    echo $in, "\n";
    foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $k) {
        echo "  ", $k, "=", \var_export($r[$k], true), "\n";
    }
    echo "  errors=", $r['error_count'] > 0 ? 'yes' : 'no', "\n";
}

// Garbage reports an error rather than a wrong number. php and this compiler
// count errors differently, so the test asserts only that there IS one.
$bad = \date_parse('not a date at all');
echo "bad year=", \var_export($bad['year'], true), " errors=", $bad['error_count'] > 0 ? 'yes' : 'no', "\n";

foreach ([['Y-m-d', '2017-07-14'], ['d/m/Y', '14/07/2017'], ['Y-m-d H:i:s', '2017-07-14 16:01:07'],
    ['H:i', '16:01']] as $c) {
    $r = \date_parse_from_format($c[0], $c[1]);
    echo $c[0], " <- ", $c[1], " y=", \var_export($r['year'], true),
        " m=", \var_export($r['month'], true), " d=", \var_export($r['day'], true),
        " h=", \var_export($r['hour'], true), " i=", \var_export($r['minute'], true), "\n";
}
