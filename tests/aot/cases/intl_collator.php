<?php
// ext/intl Collator over ICU: compare, the three sort modes, sort keys, attributes, errors.
$c = new Collator("de_DE");
var_dump($c->compare("ä", "b"), $c->compare("a", "A"), $c->getLocale(ULOC_VALID_LOCALE), $c->getLocale(ULOC_ACTUAL_LOCALE),
    $c->getStrength(), $c->getAttribute(Collator::NUMERIC_COLLATION), $c->getErrorCode(), $c->getErrorMessage());
$a = ["b", "10", "9", "a", "Ä", 2, "é", 1.5, "Zebra", "apple", "Äpfel"];
$b = $a; $c->sort($b); echo implode(",", $b), "\n";
$b = $a; $c->sort($b, Collator::SORT_STRING); echo implode(",", $b), "\n";
$b = $a; $c->sort($b, Collator::SORT_NUMERIC); echo implode(",", $b), "\n";
$b = ["x" => "b", "y" => "a", "z" => "Ä"]; $c->asort($b); print_r($b);
$b = ["ö", "o", "p", "Ö"]; $c->sortWithSortKeys($b); echo implode(",", $b), "\n";
$c->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
$b = ["img12", "img10", "img2"]; $c->sort($b, Collator::SORT_STRING); echo implode(",", $b), "\n";
$c->setStrength(Collator::PRIMARY); var_dump($c->compare("résumé", "RESUME"), $c->getStrength());
$sk = new Collator("en"); echo strlen($sk->getSortKey("abc")), " ", substr(bin2hex($sk->getSortKey("abc")), -6), " ", $sk->getSortKey("abc") < $sk->getSortKey("abd") ? "lt" : "ge", "\n";
var_dump($c->compare("a", "\xFF"), $c->getErrorCode(), $c->getErrorMessage(), $c->setAttribute(99, 1), $c->getErrorMessage());
$s = collator_create("sv_SE"); $b = ["ö", "z", "a", "å"]; collator_sort($s, $b); echo implode(",", $b), " ", collator_compare($s, "å", "z"), "\n";
var_dump(intl_get_error_code(), intl_get_error_message(), intl_is_failure(10), intl_error_name(10));
