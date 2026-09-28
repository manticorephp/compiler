<?php
// ext/intl ResourceBundle over ICU: the built-in locale data (fallback and direct), element
// reads by key and index, nested tables / arrays / int vectors, iteration, count, locales,
// and the errors (missing element, unknown bundle, no-fallback load, array writes).
$r = new ResourceBundle("de", null);
var_dump($r["Version"] !== null, $r->get("layout")["characters"], $r["layout"]["lines"]);
$d = $r->get("delimiters");
foreach ($d as $k => $v) { echo $k, "=", $v, "\n"; }
var_dump(count($d), resourcebundle_count($d), get_class($r->getIterator()));
$m = $r["calendar"]["gregorian"]["monthNames"]["format"]["wide"];
var_dump(get_class($m), count($m), $m[0], $m[11], $m->get(4));
$w = $r["calendar"]["gregorian"]["DateTimePatterns"];
var_dump(count($w) > 8);
var_dump($r->get("nope"), $r->getErrorCode(), $r->getErrorMessage(), $r->get(9999), $r->getErrorMessage(), resourcebundle_get($r, "nope2"), resourcebundle_get_error_message($r));
$root = new ResourceBundle("root", "ICUDATA-curr");
var_dump(get_class($root["Currencies"]), $root["Currencies"]["USD"][0] ?? null);
try { new ResourceBundle("xx", "nope/nope"); } catch (IntlException $e) { echo $e->getMessage(), "\n"; }
var_dump(resourcebundle_create("xx", "nope/nope"), intl_get_error_message(), ResourceBundle::create("de", null) instanceof ResourceBundle);
try { new ResourceBundle("de_AT", null, false); } catch (IntlException $e) { echo "no-fallback: ", substr($e->getMessage(), 0, 80), "\n"; }
$loc = ResourceBundle::getLocales("");
var_dump(is_array($loc), in_array("uk", $loc), in_array("de_AT", $loc));
foreach ([fn() => $r["x"] = 1, fn() => $r[""], fn() => $r->get("")] as $f) {
    try { $f(); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
}
