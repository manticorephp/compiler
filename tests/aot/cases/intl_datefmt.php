<?php
// ext/intl IntlDateFormatter / datefmt_* / IntlDatePatternGenerator over ICU: styles and patterns
// across locales and calendars, every format() argument kind, parse/localtime with offsets,
// setters, formatObject, and the error paths (constructor exceptions, create() nulls).
date_default_timezone_set("Europe/Kyiv");
Locale::setDefault("en_US");
$t = 1700000000;
foreach (["en_US", "uk_UA", "de_DE", "ja_JP@calendar=japanese", "ar_EG", "fr_FR"] as $loc) {
    foreach ([[IntlDateFormatter::FULL, IntlDateFormatter::FULL], [IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT],
              [IntlDateFormatter::SHORT, IntlDateFormatter::NONE], [IntlDateFormatter::NONE, IntlDateFormatter::LONG],
              [IntlDateFormatter::RELATIVE_MEDIUM, IntlDateFormatter::NONE]] as [$d, $tm]) {
        $f = new IntlDateFormatter($loc, $d, $tm, "America/New_York", IntlDateFormatter::TRADITIONAL);
        echo $loc, " ", $d, "/", $tm, ": ", $f->format($t), " | ", $f->getPattern(), "\n";
    }
}
$f = new IntlDateFormatter("en_US", IntlDateFormatter::PATTERN, IntlDateFormatter::PATTERN, "Asia/Tokyo", null, "yyyy-MM-dd HH:mm:ss zzzz (EEE) QQQ w D a");
foreach ([$t, 1700000000.789, "1700000000", " 1700000000 ", "1.7e9", new DateTime("@1700000000"), new DateTimeImmutable("2020-02-29 12:34:56.789", new DateTimeZone("UTC")),
          IntlCalendar::fromDateTime(new DateTime("2001-02-03 04:05:06", new DateTimeZone("UTC"))),
          ["tm_year" => 120, "tm_mon" => 1, "tm_mday" => 29, "tm_hour" => 13, "tm_min" => 14, "tm_sec" => 15], ["tm_year" => 99], [],
          "abc", new stdClass, null, true, ["tm_year" => "x"], ["tm_mon" => 1 << 40]] as $v) {
    var_dump($f->format($v)); if ($f->getErrorCode()) { echo "  ", $f->getErrorMessage(), "\n"; }
}
var_dump((new IntlDateFormatter("en_US", -2, -2, "UTC", null, "HH:mm:ss.SSS"))->format(1700000000.789));
var_dump($f->getDateType(), $f->getTimeType(), $f->getCalendar(), $f->getTimeZoneId(), $f->getLocale(), $f->getLocale(Locale::VALID_LOCALE), $f->isLenient(), get_class($f->getCalendarObject()), $f->getTimeZone()->getID());
$f->setPattern("dd.MM.yyyy HH:mm"); var_dump($f->getPattern(), $f->format($t));
$f->setTimeZone("Europe/Kyiv"); var_dump($f->getTimeZoneId(), $f->format($t));
$f->setTimeZone(new DateTimeZone("+05:45")); var_dump($f->getTimeZoneId(), $f->format($t));
$f->setTimeZone(IntlTimeZone::createTimeZone("America/Los_Angeles")); var_dump($f->format($t));
$f->setCalendar(IntlDateFormatter::TRADITIONAL); var_dump($f->getCalendar(), $f->format($t), $f->getTimeZoneId());
$cal = IntlCalendar::createInstance("Asia/Kolkata", "en_US@calendar=buddhist");
$f->setCalendar($cal); var_dump($f->getCalendar(), $f->format($t), $f->getTimeZoneId(), $f->getCalendarObject()->getType());
$f->setLenient(false); var_dump($f->isLenient());
$p = new IntlDateFormatter("en_US", IntlDateFormatter::PATTERN, IntlDateFormatter::PATTERN, "UTC", IntlDateFormatter::GREGORIAN, "yyyy-MM-dd HH:mm");
var_dump($p->parse("2023-11-14 22:13"), $p->parse("2023-11-14 22:13 trailing", $pos), $pos, $p->parse("xx 2023-11-14 22:13", $pos2), $pos2);
$off = 3; var_dump($p->parse("-- 2020-01-02 03:04", $off), $off);
$off = 99; var_dump($p->parse("2020-01-02 03:04", $off), $off);
var_dump($p->parse("nope"), $p->getErrorCode(), $p->getErrorMessage(), intl_get_error_message());
$ps = new IntlDateFormatter("en_US", IntlDateFormatter::PATTERN, IntlDateFormatter::PATTERN, "UTC", IntlDateFormatter::GREGORIAN, "yyyy-MM-dd HH:mm:ss");
var_dump($ps->localtime("2023-11-14 22:13:14"), $ps->parseToCalendar("2024-02-29 01:02:03"), $ps->getCalendarObject()->get(IntlCalendar::FIELD_YEAR), $ps->parseToCalendar("x 2024-02-29 01:02:03", $tp), $tp);
$l = new IntlDateFormatter("en_US", IntlDateFormatter::PATTERN, IntlDateFormatter::PATTERN, "America/New_York", null, "MMMM d, yyyy h:mm:ss a");
var_dump($l->localtime("July 4, 2023 3:30:15 PM", $lp), $lp);
var_dump(IntlDateFormatter::formatObject(new DateTime("2020-01-02 03:04:05", new DateTimeZone("America/Chicago"))),
    IntlDateFormatter::formatObject(new DateTime("2020-01-02 03:04:05", new DateTimeZone("UTC")), IntlDateFormatter::FULL, "uk_UA"),
    IntlDateFormatter::formatObject(new DateTime("2020-01-02 03:04:05", new DateTimeZone("+03:00")), [IntlDateFormatter::SHORT, IntlDateFormatter::NONE], "de_DE"),
    IntlDateFormatter::formatObject(new DateTime("2020-01-02 03:04:05", new DateTimeZone("UTC")), "EEEE, d MMMM y 'o' HH:mm", "uk"),
    IntlDateFormatter::formatObject(IntlCalendar::fromDateTime(new DateTime("2020-01-02 03:04:05", new DateTimeZone("Asia/Tokyo")), "ja_JP@calendar=japanese"), IntlDateFormatter::LONG, "ja_JP@calendar=japanese"),
    IntlDateFormatter::formatObject(new DateTime("@0"), [1]), intl_get_error_message(), IntlDateFormatter::formatObject(new DateTime("@0"), 99), intl_get_error_message(),
    IntlDateFormatter::formatObject(new DateTime("@0"), [0, 99]), intl_get_error_message(), IntlDateFormatter::formatObject(new DateTime("@0"), ""), intl_get_error_message(),
    IntlDateFormatter::formatObject(new stdClass), intl_get_error_message());
foreach ([fn() => new IntlDateFormatter("en", 99), fn() => new IntlDateFormatter("!!", 0, 0), fn() => new IntlDateFormatter("en", 0, 0, null, 5),
          fn() => new IntlDateFormatter("en", -2, 0), fn() => new IntlDateFormatter("en", 0, 0, "junk"), fn() => datefmt_create("en", 99),
          fn() => datefmt_create("en", 0, 0, null, 7), fn() => IntlDateFormatter::create("en_US", 1, 1, "UTC")] as $mk) {
    try { $r = $mk(); echo $r === null ? "NULL" : get_class($r), " | ", intl_get_error_message(), "\n"; }
    catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), " | ", intl_get_error_message(), "\n"; }
}
$d = datefmt_create("uk_UA", IntlDateFormatter::LONG, IntlDateFormatter::SHORT, "Europe/Kyiv");
var_dump(datefmt_format($d, $t), datefmt_get_pattern($d), datefmt_get_locale($d), datefmt_get_datetype($d), datefmt_get_timetype($d), datefmt_get_calendar($d),
    datefmt_get_timezone_id($d), datefmt_is_lenient($d), datefmt_set_pattern($d, "yyyy"), datefmt_format($d, 0), datefmt_parse($d, "2020"),
    datefmt_get_error_code($d), datefmt_get_error_message($d), datefmt_set_timezone($d, "UTC"), datefmt_set_calendar($d, 1), datefmt_get_calendar($d),
    datefmt_format($d, "x"), datefmt_get_error_message($d), datefmt_format_object(new DateTime("@86400"), "yyyy-MM-dd", "en"));
$c = clone $d; datefmt_set_pattern($c, "MM"); var_dump(datefmt_get_pattern($d), datefmt_get_pattern($c));
$g = new IntlDatePatternGenerator("en_US");
foreach (["yMMMd", "jmm", "MMMMEEEEd", "yQQQ", "Hmsv", "dMMM"] as $sk) { echo $sk, " => ", $g->getBestPattern($sk), " | ", IntlDatePatternGenerator::create("uk_UA")->getBestPattern($sk), "\n"; }
var_dump(IntlDatePatternGenerator::create("ja")->getBestPattern("yMMMMd"), (new IntlDatePatternGenerator())->getBestPattern("yMd"));
