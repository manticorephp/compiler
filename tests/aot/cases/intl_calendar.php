<?php
// ext/intl IntlCalendar / IntlGregorianCalendar over ICU: creation by zone and locale (the
// calendar type picks the class), field reads and limits, arithmetic, setters, week data,
// wall-time options, comparisons, DateTime round trips, the Gregorian cutover, and errors.
date_default_timezone_set("Europe/Kyiv");
Locale::setDefault("en_US");
$t = 1700000000000.0;
foreach ([["Europe/Kyiv", "uk_UA"], ["America/New_York", "en_US"], ["Asia/Tokyo", "ja_JP@calendar=japanese"],
          ["Asia/Jerusalem", "he_IL@calendar=hebrew"], ["UTC", "th_TH"], [null, "de_DE"]] as [$z, $l]) {
    $c = IntlCalendar::createInstance($z, $l);
    $c->setTime($t);
    echo get_class($c), " ", $c->getType(), " ", $c->getTimeZone()->getID(), " ", $c->getLocale(Locale::VALID_LOCALE), " first=", $c->getFirstDayOfWeek(),
        " minDays=", $c->getMinimalDaysInFirstWeek(), "\n  ";
    for ($f = 0; $f < 23; $f++) { echo $c->get($f), ","; }
    echo "\n  max:";
    foreach ([IntlCalendar::FIELD_DATE, IntlCalendar::FIELD_MONTH, IntlCalendar::FIELD_DAY_OF_YEAR, IntlCalendar::FIELD_WEEK_OF_YEAR] as $f) {
        echo " ", $c->getActualMaximum($f), "/", $c->getMaximum($f), "/", $c->getLeastMaximum($f), "/", $c->getActualMinimum($f), "/", $c->getMinimum($f), "/", $c->getGreatestMinimum($f);
    }
    echo "\n  dow:";
    for ($d = 1; $d <= 7; $d++) { echo " ", $c->getDayOfWeekType($d); }
    echo " weekend=", var_export($c->isWeekend(), true), " ", var_export($c->isWeekend(1700300000000.0), true), " dst=", var_export($c->inDaylightTime(), true), "\n";
}
$c = IntlCalendar::createInstance("Europe/Kyiv", "uk_UA");
$c->setTime(1711846800000.0);
$c->add(IntlCalendar::FIELD_HOUR_OF_DAY, 5);
echo $c->getTime(), " ", $c->get(IntlCalendar::FIELD_HOUR_OF_DAY), "\n";
$c->roll(IntlCalendar::FIELD_MONTH, 11); echo $c->get(IntlCalendar::FIELD_MONTH), " ", $c->get(IntlCalendar::FIELD_YEAR), "\n";
$c->add(IntlCalendar::FIELD_MONTH, -14); echo $c->get(IntlCalendar::FIELD_MONTH), " ", $c->get(IntlCalendar::FIELD_YEAR), "\n";
$c->setDate(2024, 1, 29); echo $c->getTime(), " "; $c->setDateTime(2024, 1, 29, 13, 45); echo $c->getTime(), " "; $c->setDateTime(2024, 1, 29, 13, 45, 7); echo $c->getTime(), "\n";
$c->set(IntlCalendar::FIELD_DATE, 31); echo $c->getTime(), " ", var_export($c->isSet(IntlCalendar::FIELD_DATE), true), "\n";
$c->clear(IntlCalendar::FIELD_MINUTE); echo var_export($c->isSet(IntlCalendar::FIELD_MINUTE), true), " ", $c->getTime(), "\n";
$c->clear(); echo $c->getTime(), "\n";
$c->setTime(1700000000000.0);
var_dump($c->fieldDifference(1800000000000.0, IntlCalendar::FIELD_MONTH), $c->getTime(), $c->fieldDifference(1600000000000.0, IntlCalendar::FIELD_DATE));
$c->setFirstDayOfWeek(IntlCalendar::DOW_SUNDAY); $c->setMinimalDaysInFirstWeek(4); $c->setLenient(false);
var_dump($c->getFirstDayOfWeek(), $c->getMinimalDaysInFirstWeek(), $c->isLenient());
$c->setRepeatedWallTimeOption(IntlCalendar::WALLTIME_LAST); $c->setSkippedWallTimeOption(IntlCalendar::WALLTIME_NEXT_VALID);
var_dump($c->getRepeatedWallTimeOption(), $c->getSkippedWallTimeOption());
$c->setLenient(true);
$c->setTime(1711846800000.0);
$d = clone $c; $d->add(IntlCalendar::FIELD_SECOND, 1);
var_dump($c->before($d), $c->after($d), $c->equals($d), $c->isEquivalentTo($d), $c->equals(clone $c));
$e = IntlCalendar::createInstance("Europe/Kyiv", "uk_UA"); $e->setTime(1711846800000.0);
var_dump($c->equals($e), $c->isEquivalentTo($e));
var_dump($c->setTimeZone("America/New_York"), $c->getTimeZone()->getID(), $c->get(IntlCalendar::FIELD_HOUR_OF_DAY), $c->setTimeZone(null),
    $c->setTimeZone(IntlTimeZone::createTimeZone("Asia/Kolkata")), $c->get(IntlCalendar::FIELD_MINUTE), $c->setTimeZone(new DateTimeZone("+03:00")), $c->getTimeZone()->getID());
$dt = $c->toDateTime(); echo get_class($dt), " ", $dt->format("Y-m-d H:i:s T P"), "\n";
$f = IntlCalendar::fromDateTime(new DateTime("2021-03-04 05:06:07", new DateTimeZone("America/Chicago")), "fr_FR");
echo get_class($f), " ", $f->getTime(), " ", $f->getTimeZone()->getID(), " ", $f->getLocale(Locale::VALID_LOCALE), " ", $f->get(IntlCalendar::FIELD_HOUR_OF_DAY), "\n";
$f = IntlCalendar::fromDateTime("2000-01-01 00:00:00"); echo $f->getTime(), " ", $f->getTimeZone()->getID(), "\n";
$g = IntlGregorianCalendar::createFromDate(2024, 1, 29); echo get_class($g), " ", $g->getTime(), " ", $g->getTimeZone()->getID(), "\n";
$g = IntlGregorianCalendar::createFromDateTime(2024, 1, 29, 10, 20); echo $g->getTime(), "\n";
$g = IntlGregorianCalendar::createFromDateTime(2024, 1, 29, 10, 20, 30); echo $g->getTime(), "\n";
$g = new IntlGregorianCalendar("Asia/Tokyo", "ja_JP"); echo $g->getType(), " ", $g->getTimeZone()->getID(), " ", $g->getLocale(Locale::VALID_LOCALE), "\n";
$g = new IntlGregorianCalendar("UTC", "en_GB"); echo get_class($g), " ", $g->getLocale(Locale::VALID_LOCALE), " ", in_array($g->getLocale(Locale::ACTUAL_LOCALE), ["en_001", "en_GB"], true) ? "actual" : "?", "\n";
var_dump($g->getGregorianChange(), $g->isLeapYear(1500), $g->isLeapYear(1700), $g->isLeapYear(2000), $g->isLeapYear(2023));
$g->setGregorianChange(-1e15); var_dump($g->getGregorianChange(), $g->isLeapYear(1500), $g->isLeapYear(1700));
$g->setGregorianChange(1e14); var_dump($g->isLeapYear(1700), $g->isLeapYear(2100));
var_dump(count(IntlCalendar::getAvailableLocales()) > 100, in_array("uk_UA", IntlCalendar::getAvailableLocales()));
foreach (IntlCalendar::getKeywordValuesForLocale("calendar", "ja_JP", true) as $k => $v) { echo "$k=$v "; } echo "\n";
echo implode(",", iterator_to_array(IntlCalendar::getKeywordValuesForLocale("calendar", "th_TH", false))) !== "" ? "ok" : "no", "\n";
foreach ([fn() => $c->get(99), fn() => intlcal_get($c, -1), fn() => $c->add(1, 1 << 40), fn() => $c->getDayOfWeekType(0), fn() => intlcal_get_weekend_transition($c, 8),
          fn() => $c->setMinimalDaysInFirstWeek(9), fn() => $c->setRepeatedWallTimeOption(2), fn() => $c->setSkippedWallTimeOption(5), fn() => $c->getLocale(5),
          fn() => IntlCalendar::createInstance("junk"), 
          fn() => IntlGregorianCalendar::createFromDate(1 << 40, 1, 1), fn() => $c->isSet(24)] as $bad) {
    try { var_dump($bad()); } catch (Throwable $ex) { echo get_class($ex), ": ", $ex->getMessage(), "\n"; }
}
$w = IntlCalendar::createInstance("UTC", "ar_SA@calendar=gregorian");
for ($d = 1; $d <= 7; $d++) { echo $w->getDayOfWeekType($d), ":"; try { echo $w->getWeekendTransition($d); } catch (Throwable $ex) { echo "x"; } echo " "; }
echo $w->getErrorCode(), " ", $w->getErrorMessage(), "\n";
var_dump(intlcal_get_time(intlcal_create_instance("UTC")) > 1.7e12, intlcal_get_now() > 1.7e12, intlcal_get_type($w), intlcal_is_lenient($w), intlcal_get_error_message($w));
