<?php
// ext/intl IntlChar over ICU uchar.h: code point conversion + errors, properties, names, case, digits, enumeration.
// Only characters stable since Unicode 7, so the Linux images' older ICU answers the same.
$cps = [0x41, "a", "é", "Ж", "٣", "€", "😀", 0x1F600, 0x20, "\t", 0x5F, "Ⅻ", "ǅ", "ß", "(", "›", 0xD800, 0x3000];
foreach ($cps as $cp) {
    echo var_export($cp, true), ": ", IntlChar::ord($cp), " ", bin2hex(IntlChar::chr($cp)), " ", IntlChar::charName($cp), " | ",
        IntlChar::charName($cp, IntlChar::EXTENDED_CHAR_NAME), " t", IntlChar::charType($cp), " d", IntlChar::charDirection($cp),
        " b", IntlChar::getBlockCode($cp), " cc", IntlChar::getCombiningClass($cp), " dv", IntlChar::charDigitValue($cp),
        " nv", IntlChar::getNumericValue($cp), "\n  ";
    foreach (['isUAlphabetic','isULowercase','isUUppercase','isUWhiteSpace','islower','isupper','istitle','isdigit','isalpha','isalnum','isxdigit','ispunct','isgraph','isblank','isdefined','isspace','isJavaSpaceChar','isWhitespace','iscntrl','isISOControl','isprint','isbase','isMirrored','isIDStart','isIDPart','isIDIgnorable','isJavaIDStart','isJavaIDPart'] as $m) {
        echo IntlChar::$m($cp) ? 1 : 0;
    }
    echo " ", var_export(IntlChar::tolower($cp), true), " ", var_export(IntlChar::toupper($cp), true), " ", var_export(IntlChar::totitle($cp), true),
        " ", var_export(IntlChar::foldCase($cp), true), " ", var_export(IntlChar::charMirror($cp), true), " ", var_export(IntlChar::getBidiPairedBracket($cp), true),
        " ", var_export(IntlChar::charAge($cp), true) === "" ? "" : implode(".", IntlChar::charAge($cp)), " ", IntlChar::getFC_NFKC_Closure($cp) === "" ? "-" : bin2hex(IntlChar::getFC_NFKC_Closure($cp)), "\n";
}
var_dump(IntlChar::hasBinaryProperty("A", IntlChar::PROPERTY_ALPHABETIC), IntlChar::hasBinaryProperty("1", IntlChar::PROPERTY_ALPHABETIC),
    IntlChar::getIntPropertyValue("Ж", IntlChar::PROPERTY_SCRIPT), IntlChar::getIntPropertyValue("Ж", IntlChar::PROPERTY_EAST_ASIAN_WIDTH),
    IntlChar::getIntPropertyMinValue(IntlChar::PROPERTY_BIDI_CLASS), IntlChar::getIntPropertyMaxValue(IntlChar::PROPERTY_LINE_BREAK) > 30,
    IntlChar::getPropertyName(IntlChar::PROPERTY_ALPHABETIC), IntlChar::getPropertyName(IntlChar::PROPERTY_ALPHABETIC, IntlChar::SHORT_PROPERTY_NAME),
    IntlChar::getPropertyName(99999), intl_get_error_message(), IntlChar::getPropertyEnum("Alpha"), IntlChar::getPropertyEnum("nope"),
    IntlChar::getPropertyValueName(IntlChar::PROPERTY_SCRIPT, IntlChar::getIntPropertyValue("Ж", IntlChar::PROPERTY_SCRIPT)),
    IntlChar::getPropertyValueName(IntlChar::PROPERTY_GENERAL_CATEGORY, 5, IntlChar::SHORT_PROPERTY_NAME),
    IntlChar::getPropertyValueName(IntlChar::PROPERTY_SCRIPT, 99999), intl_get_error_code(),
    IntlChar::getPropertyValueEnum(IntlChar::PROPERTY_SCRIPT, "Cyrillic"), IntlChar::getPropertyValueEnum(IntlChar::PROPERTY_SCRIPT, "xx"));
var_dump(IntlChar::charFromName("LATIN CAPITAL LETTER A"), IntlChar::charFromName("NO SUCH"), intl_get_error_message(), intl_get_error_code(),
    IntlChar::charFromName("<control-0009>", IntlChar::EXTENDED_CHAR_NAME), IntlChar::charFromName("BYZANTINE MUSICAL SYMBOL FTHORA SKLIRON CHROMA VASIS", IntlChar::CHAR_NAME_ALIAS));
var_dump(IntlChar::digit("7"), IntlChar::digit("f", 16), IntlChar::digit("z", 36), IntlChar::digit("z"), intl_get_error_message(),
    IntlChar::forDigit(7), IntlChar::forDigit(11, 16), IntlChar::forDigit(40, 16), IntlChar::foldCase("İ", IntlChar::FOLD_CASE_EXCLUDE_SPECIAL_I), IntlChar::foldCase(0x130));
foreach (["ab", "", "\xff", "\xe2\x82", "\xed\xa0\x80", "\xf4\x90\x80\x80", -1, 0x110000] as $bad) {
    var_dump(IntlChar::chr($bad), intl_get_error_message(), intl_get_error_code(), IntlChar::isalpha($bad), IntlChar::tolower($bad));
}
var_dump(IntlChar::chr(0), IntlChar::ord("\0"), IntlChar::chr(0x10FFFF) === "\xf4\x8f\xbf\xbf", count(IntlChar::getUnicodeVersion()));
$n = 0;
IntlChar::enumCharTypes(function (int $start, int $end, int $type) use (&$n) { if ($start < 0x500) { echo "$start-$end:$type "; } $n++; });
echo "\n", $n > 1000 ? "many" : "few", "\n";
var_dump(IntlChar::enumCharNames(0x41, 0x45, function (int $cp, int $choice, string $name) { echo "$cp $choice $name\n"; }));
var_dump(IntlChar::enumCharNames("\x00", 0x3, function (int $cp, int $choice, string $name) { echo "$cp $choice $name\n"; }, IntlChar::EXTENDED_CHAR_NAME));
var_dump(IntlChar::enumCharNames(0x41, 0x42, fn() => 0, 7), intl_get_error_message(), IntlChar::enumCharNames("xx", 0x42, fn() => 0), intl_get_error_message());
var_dump(IntlChar::CHAR_CATEGORY_UPPERCASE_LETTER, IntlChar::PROPERTY_ALPHABETIC, IntlChar::BLOCK_CODE_CYRILLIC, IntlChar::NO_NUMERIC_VALUE, IntlChar::CODEPOINT_MAX);
