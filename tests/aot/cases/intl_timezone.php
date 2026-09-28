<?php
// ext/intl IntlTimeZone over ICU: creation (system, custom offset, unknown), offsets at fixed
// instants, display names in every style, canonical/equivalent IDs, regions, Windows IDs,
// enumerations, DateTimeZone round trips, rules comparison and the error state.
ini_set("date.timezone", "UTC");
$ids = ["Europe/Kyiv", "America/New_York", "Asia/Kolkata", "Asia/Tokyo", "US/Pacific", "GMT+05:30", "GMT-3", "junk", "Etc/GMT+2"];
foreach ($ids as $id) {
    $tz = IntlTimeZone::createTimeZone($id);
    $tz->getOffset(1700000000000.0, false, $raw, $dst);
    $tz->getOffset(1690000000000.0, true, $raw2, $dst2);
    echo str_pad($id, 20), $tz->getID(), " raw=", $raw, " dst=", $dst, " local=", $raw2, "/", $dst2,
        " savings=", $tz->getDSTSavings(), " region=", var_export(IntlTimeZone::getRegion($id), true),
        " eq=", IntlTimeZone::countEquivalentIDs($id) > 0 ? "y" : "n", "\n";
    // Etc/Unknown's short GMT styles moved between ICU releases (GMT vs GMT+0).
    foreach ($tz->getID() === "Etc/Unknown" ? [5] : [1, 2, 3, 4, 5, 6, 7, 8] as $style) {
        echo "  ", $style, ": ", $tz->getDisplayName(false, $style, "en_US"), " | ", $tz->getDisplayName(true, $style, "en_US"), " | ", $tz->getDisplayName(false, $style, "uk"), "\n";
    }
}
var_dump(IntlTimeZone::getCanonicalID("US/Pacific", $sys), $sys, IntlTimeZone::getCanonicalID("GMT+5", $sys), $sys,
    IntlTimeZone::getCanonicalID("nope"), intl_get_error_message());
var_dump(IntlTimeZone::getEquivalentID("America/Los_Angeles", 0) !== "", IntlTimeZone::getEquivalentID("America/Los_Angeles", 999), IntlTimeZone::getEquivalentID("nope", 0));
var_dump(IntlTimeZone::getWindowsID("Europe/Kyiv"), IntlTimeZone::getIDForWindowsID("Pacific Standard Time"), IntlTimeZone::getIDForWindowsID("Pacific Standard Time", "CA"),
    IntlTimeZone::getWindowsID("nope"), intl_get_error_message(), IntlTimeZone::getIDForWindowsID("nope"), intl_get_error_message());
var_dump(intltz_get_region("xx"), intl_get_error_message(), IntlTimeZone::getRegion("Etc/Unknown"));
$gmt = IntlTimeZone::getGMT();
var_dump($gmt->getID(), $gmt->getDSTSavings(), IntlTimeZone::getUnknown()->getID(), $gmt->useDaylightTime());
var_dump($gmt->getDisplayName(false, 99), $gmt->getErrorCode(), $gmt->getErrorMessage(), intl_get_error_message());
$ny = IntlTimeZone::createTimeZone("America/New_York");
$tor = IntlTimeZone::createTimeZone("America/Toronto");
var_dump($ny->hasSameRules(IntlTimeZone::createTimeZone("US/Eastern")), $ny->hasSameRules($tor), $ny->hasSameRules($gmt),
    $gmt->hasSameRules(IntlTimeZone::createTimeZone("GMT+00:00")), $ny->useDaylightTime(), IntlTimeZone::createTimeZone("Asia/Tokyo")->useDaylightTime());
foreach ([new DateTimeZone("Europe/Paris"), new DateTimeZone("+05:30"), new DateTimeZone("-00:30")] as $d) {
    $t = IntlTimeZone::fromDateTimeZone($d);
    echo $d->getName(), " -> ", $t->getID(), " -> ", $t->toDateTimeZone()->getName(), "\n";
}
echo IntlTimeZone::createTimeZone("GMT+02:00")->toDateTimeZone()->getName(), " ", intltz_to_date_time_zone($gmt)->getName(), "\n";
$c = 0; foreach (IntlTimeZone::createEnumeration("UA") as $k => $v) { echo "$k=$v "; } echo "\n";
$it = IntlTimeZone::createTimeZoneIDEnumeration(IntlTimeZone::TYPE_CANONICAL_LOCATION, "JP", 32400000);
foreach ($it as $k => $v) { echo "$k=$v "; } echo "\n";
var_dump($it->valid());
$it->rewind(); var_dump($it->current()); $it->next(); var_dump($it->valid());
var_dump(count(iterator_to_array(IntlTimeZone::createEnumeration())) > 400, count(iterator_to_array(IntlTimeZone::createEnumeration(3600000))) > 10);
try { IntlTimeZone::createTimeZoneIDEnumeration(9); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
var_dump(intltz_get_offset($ny, 1700000000000.0, false, $r, $dd), $r, $dd, intltz_get_display_name($ny), intltz_get_id(intltz_create_time_zone("Europe/Kyiv")));
var_dump(preg_match('/^\d{4}[a-z]$/', IntlTimeZone::getTZDataVersion()));
$cl = clone $ny; var_dump($cl->getID());
