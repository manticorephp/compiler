<?php
// var_dump of an IntlCalendar renders its debug info: type, time zone, locale and every field.
$c = IntlCalendar::createInstance("Asia/Tokyo", "ja_JP@calendar=japanese");
$c->setTime(1700000000000.0);
var_dump($c);
