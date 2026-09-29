<?php
// ext/intl Transliterator over ICU: ids, compound ids, rules, inverse, ranges, errors.
$t = Transliterator::create("Any-Latin; Latin-ASCII; Lower()");
var_dump($t->id, $t->transliterate("Привіт, Світе! Ελληνικά 日本語 Straße Ünïcödé"));
echo transliterator_transliterate("Any-Latin; Latin-ASCII", "Київ — Харків"), "\n";
echo Transliterator::create("Latin-Cyrillic")->transliterate("Kyiv Kharkiv"), "\n";
$r = Transliterator::createFromRules("a > b; c > d;", Transliterator::FORWARD);
var_dump($r->id, $r->transliterate("abcabc"), $r->transliterate("abcabc", 2, 4));
$inv = Transliterator::create("Latin-Greek")->createInverse(); var_dump($inv->id, $inv->transliterate("Ελλάδα"));
$ids = Transliterator::listIDs(); var_dump(count($ids) > 100, in_array("Latin-ASCII", $ids, true));
var_dump(Transliterator::create("No-Such-Thing"), intl_get_error_code(), intl_get_error_message());
var_dump(transliterator_create_from_rules("a > ;;; (("), intl_get_error_message());
var_dump($r->transliterate("abc", 1, 9), $r->getErrorCode(), $r->getErrorMessage());
foreach ([fn() => $r->transliterate("x", -1), fn() => $r->transliterate("x", 3, 1), fn() => Transliterator::create("Latin-Greek", 5)] as $f) {
    try { var_dump($f()); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
}
