<?php
// DateTime::getLastErrors(): false after a clean parse, an error count after a failed createFromFormat
new DateTime('2024-03-05 10:00:00');
var_dump(DateTime::getLastErrors());
var_dump(DateTime::createFromFormat('Y-m-d', 'nonsense'));
$e = DateTimeImmutable::getLastErrors();
var_dump($e['error_count'] > 0);
new DateTimeImmutable('2024-03-05');
var_dump(DateTimeImmutable::getLastErrors());
try { new DateTime('not a date at all'); } catch (Exception $x) { echo "threw\n"; }
var_dump(DateTime::getLastErrors()['error_count'] > 0);
