<?php
// ext/intl NumberFormatter over ICU: styles, types, currency, parse with offsets, attributes, symbols, errors.
foreach ([["en_US", NumberFormatter::DECIMAL], ["de_DE", NumberFormatter::DECIMAL], ["uk_UA", NumberFormatter::CURRENCY],
    ["en_US", NumberFormatter::PERCENT], ["fr_FR", NumberFormatter::SCIENTIFIC], ["en", NumberFormatter::SPELLOUT],
    ["en", NumberFormatter::ORDINAL], ["en", NumberFormatter::DECIMAL_COMPACT_SHORT], ["ja_JP", NumberFormatter::CURRENCY]] as [$loc, $style]) {
    $f = new NumberFormatter($loc, $style);
    echo "$loc/$style: ", $f->format(1234567.891), " | ", $f->format(-42), " | ", $f->format(0.5, NumberFormatter::TYPE_DOUBLE),
        " | ", \in_array($style, [NumberFormatter::SPELLOUT, NumberFormatter::ORDINAL, NumberFormatter::DURATION], true) ? \strtok($f->getPattern(), "\n") : $f->getPattern(), " | ", $f->getLocale() === "" ? "root" : $f->getLocale(), "\n";
}
$f = new NumberFormatter("de_DE", NumberFormatter::CURRENCY);
var_dump($f->formatCurrency(1234.5, "USD"), $f->formatCurrency(-0.5, "JPY"), $f->format(PHP_INT_MAX), $f->format(7, NumberFormatter::TYPE_INT32));
$d = new NumberFormatter("en_US", NumberFormatter::DECIMAL);
$off = 1;
var_dump($d->parse("1,234.5"), $d->parse("1,234", NumberFormatter::TYPE_INT64), $d->parse("x12.5y", NumberFormatter::TYPE_DOUBLE, $off), $off,
    $d->parse("abc"), $d->getErrorCode(), $d->getErrorMessage());
$c = new NumberFormatter("en_US", NumberFormatter::CURRENCY); $cur = null; $pos = 0;
var_dump($c->parseCurrency("\$1,234.56", $cur, $pos), $cur, $pos);
$d->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1); $d->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_DOWN);
$d->setSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, "'"); $d->setTextAttribute(NumberFormatter::NEGATIVE_PREFIX, "neg ");
var_dump($d->format(-1234.99), $d->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS), $d->getAttribute(NumberFormatter::ROUNDING_INCREMENT),
    $d->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL), $d->getTextAttribute(NumberFormatter::NEGATIVE_PREFIX),
    $d->setPattern("#,##0.000"), $d->format(3.14159), $d->getAttribute(99), $d->getErrorMessage(), $d->getSymbol(99), intl_get_error_message());
$p = numfmt_create("en_US", NumberFormatter::PATTERN_DECIMAL, "00.##");
var_dump(numfmt_format($p, 3.456), numfmt_get_pattern($p), numfmt_create("en", 99), intl_get_error_code());
foreach ([fn() => new NumberFormatter("en", 99), fn() => new NumberFormatter("xx_invalid!!", 1), fn() => $d->format(1, 4),
    fn() => numfmt_format($d, 1, 9)] as $fn) {
    try { var_dump($fn()); } catch (\Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
}
