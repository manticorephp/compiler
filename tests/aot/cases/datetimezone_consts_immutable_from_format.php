<?php
// DateTimeZone group constants and date_create_immutable_from_format(), as WordPress uses them.
var_dump(DateTimeZone::UTC, DateTimeZone::ALL, DateTimeZone::ALL_WITH_BC, DateTimeZone::PER_COUNTRY);
$d = date_create_immutable_from_format('Y-m-d H:i:s', '2026-11-10 12:34:56', new DateTimeZone('UTC'));
echo get_class($d), ' ', $d->format('D, d M Y H:i'), "\n";
var_dump(date_create_immutable_from_format('Y-m-d', 'nope'));
