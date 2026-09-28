<?php
// var_dump of an IntlTimeZone renders its debug info (valid, id, rawOffset, currentOffset).
var_dump(IntlTimeZone::createTimeZone("Asia/Kolkata"));
var_dump(IntlTimeZone::createTimeZone("GMT-05:30"));
var_dump(IntlTimeZone::getGMT());
