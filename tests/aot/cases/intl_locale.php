<?php
// ext/intl Locale over ICU: subtags, display names, keywords, compose/parse, matching, lookup, likely subtags.
Locale::setDefault("en_US");
foreach (["sr_Latn_RS", "zh-Hant-TW", "de_DE@currency=EUR;collation=phonebook", "en", "i-klingon", "x-private", "sl-rozaj-biske-1994", "", "uk_UA"] as $l) {
    echo "[$l] ", var_export(Locale::getPrimaryLanguage($l), true), " ", var_export(Locale::getScript($l), true), " ",
        var_export(Locale::getRegion($l), true), " ", var_export(Locale::canonicalize($l), true), " | ",
        var_export(Locale::getDisplayName($l), true), " | ", var_export(Locale::getDisplayLanguage($l, "fr"), true), " | ",
        var_export(Locale::getDisplayRegion($l, "uk"), true), " | ", var_export(Locale::getDisplayScript($l, "de"), true), "\n";
    var_export(Locale::parseLocale($l)); echo " "; var_export(Locale::getAllVariants($l)); echo " "; var_export(Locale::getKeywords($l)); echo "\n";
}
var_dump(Locale::composeLocale(["language" => "sr", "script" => "Latn", "region" => "RS", "variant" => ["a1", "b2"], "private" => "pv"]),
    Locale::composeLocale(["language" => "en", "variant0" => "x1", "variant2" => "x3", "extlang0" => "yue"]),
    Locale::composeLocale(["grandfathered" => "i-klingon"]), Locale::composeLocale(["language" => 5]), intl_get_error_message());
var_dump(Locale::filterMatches("de-DEVA", "de-DE", false), Locale::filterMatches("de-DE-1996", "de-DE"), Locale::filterMatches("DE_de", "de-DE", true),
    Locale::filterMatches("en", "*"));
var_dump(Locale::lookup(["de-DEVA", "de-DE-1996", "de", "de-De"], "de-DE-1996-x-prv1-prv2", true, "en_US"),
    Locale::lookup(["fr", "es"], "de-DE", false, "en"), Locale::lookup(["en-US", "fr"], "en-US-x-y"), Locale::lookup([], "en"));
var_dump(Locale::acceptFromHttp("en-US,en;q=0.9,uk;q=0.8"), Locale::acceptFromHttp("xx-YY"), Locale::isRightToLeft("ar"), Locale::isRightToLeft("en"),
    Locale::addLikelySubtags("zh"), Locale::minimizeSubtags("zh_Hans_CN"), Locale::getDefault(), locale_get_default());
var_dump(Locale::getPrimaryLanguage(str_repeat("a", 200)), intl_get_error_message());
try { Locale::composeLocale(["script" => "Latn"]); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
