<?php
// DateTime::createFromFormat with an escaped T, microseconds and a zone offset (Carbon's ISO format) returns false
$e = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', '2026-11-05T11:22:33.000000Z');
echo $e ? $e->format('c') : 'false', "\n";
foreach ([['Y-m-d\TH:i:sP', '2026-11-05T11:22:33z'], ['Y-m-d\TH:i:sp', '2026-11-05T11:22:33+02:00'], ['Y-m-d\TH:i:sO', '2026-11-05T11:22:33Z'], ['Y-m-d\XH', '2026-11-05Y11'], ['Y-m-d\TH:i:s.uP', '2026-11-05T11:22:33.5Z']] as [$f, $v]) {
    $e = DateTime::createFromFormat($f, $v);
    echo $e ? $e->format('c u') : 'false', "\n";
}
date_default_timezone_set('Europe/Kyiv');
foreach ([['Y-m-d H:i', '2026-07-01 10:00', null], ['Y-m-d H:i', '2026-07-01 10:00', 'America/New_York'], ['U', '1700000000', null], ['U.u', '1700000000.25', 'Asia/Tokyo'], ['Y-m-d H:i P', '2026-01-01 00:00 -05:30', 'Asia/Tokyo'], ['!d/m/Y', '15/08/2026', null]] as [$f, $v, $z]) {
    $e = DateTime::createFromFormat($f, $v, $z === null ? null : new DateTimeZone($z));
    $i = DateTimeImmutable::createFromFormat($f, $v, $z === null ? null : new DateTimeZone($z));
    echo $e->format('c u e'), ' | ', $i->format('c u e'), ' | ', $e->getTimestamp(), "\n";
}
