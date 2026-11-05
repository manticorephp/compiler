<?php
// DateTime::createFromFormat with an escaped T, microseconds and a zone offset (Carbon's ISO format) returns false
// issue: #572
$e = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', '2026-11-05T11:22:33.000000Z');
echo $e ? $e->format('c') : 'false', "\n";
