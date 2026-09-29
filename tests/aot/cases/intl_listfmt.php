<?php
// IntlListFormatter (php 8.5) and Normalizer::getRawDecomposition over ICU;
// extension_loaded() reports mbstring and intl.
var_dump(extension_loaded('mbstring'), extension_loaded('INTL'));
$name = 'intl';
var_dump(extension_loaded($name));
if (extension_loaded('mbstring')) { echo "mbstring folds true\n"; }

$f = new IntlListFormatter('en');
var_dump($f->format(['a', 'b', 'c']), $f->format([]), $f->format([1, 2.5, true, null]));
var_dump($f->format(["\xff"]), $f->getErrorCode(), $f->getErrorMessage(), intl_get_error_message());
var_dump((new IntlListFormatter('de', IntlListFormatter::TYPE_OR, IntlListFormatter::WIDTH_SHORT))->format(['x', 'y']));
var_dump((new IntlListFormatter('en', IntlListFormatter::TYPE_UNITS, IntlListFormatter::WIDTH_NARROW))->format(['3 ft', '7 in']));
foreach ([['xx_YY', 0, 0], ['en', 5, 0], ['en', 0, 9], [str_repeat('a', 200), 0, 0]] as $a) {
    try { new IntlListFormatter($a[0], $a[1], $a[2]); echo "ok\n"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
}
try { $f->__construct('en'); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
try { $g = clone $f; echo "cloned\n"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }

var_dump(normalizer_get_raw_decomposition("é"), normalizer_get_raw_decomposition("a"));
var_dump(normalizer_get_raw_decomposition("ﬁ", Normalizer::FORM_KC), normalizer_get_raw_decomposition("가"));
var_dump(normalizer_get_raw_decomposition("ab"), intl_get_error_message());
var_dump(normalizer_get_raw_decomposition("\xff"), intl_get_error_message());
var_dump(Normalizer::getRawDecomposition("é", 99), Normalizer::getRawDecomposition("\u{10FFFF}"), Normalizer::getRawDecomposition(""));
