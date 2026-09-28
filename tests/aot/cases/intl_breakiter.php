<?php
// ext/intl IntlBreakIterator over ICU: word/line/sentence/character/title and code-point
// iterators (UTF-8 byte offsets), navigation, rule statuses, parts iterators by key type,
// the boundary iterator, custom and compiled rules, clones and errors.
$text = "Hello, world! Ще раз: 42 кота. 日本語のテキスト。 e\u{301}clair 👍🏽 done.";
foreach (["createWordInstance", "createLineInstance", "createSentenceInstance", "createCharacterInstance", "createTitleInstance"] as $m) {
    $b = IntlBreakIterator::$m("en_US");
    echo $m, " ", get_class($b), " ", var_export($b->getText(), true), " ";
    $b->setText($text);
    $bounds = [];
    foreach ($b as $k => $pos) { $bounds[] = "$k:$pos"; }
    echo implode(",", $bounds), "\n";
    $st = [];
    $b->first();
    while (($p = $b->next()) !== IntlBreakIterator::DONE) { $st[] = $b->getRuleStatus(); }
    echo "  status ", implode(",", $st), "\n";
    echo "  first=", $b->first(), " last=", $b->last(), " prev=", $b->previous(), " cur=", $b->current(), " following(5)=", $b->following(5),
        " preceding(20)=", $b->preceding(20), " next(3)=", $b->next(3), " next(-2)=", $b->next(-2), " next(0)=", $b->next(0),
        " isBoundary(7)=", var_export($b->isBoundary(7), true), " isBoundary(8)=", var_export($b->isBoundary(8), true), "\n";
    echo "  ", $b->getLocale(Locale::VALID_LOCALE), " ", $b->getLocale(Locale::ACTUAL_LOCALE), "\n";
}
$w = IntlBreakIterator::createWordInstance("en");
$w->setText("The 3 quick-brown foxes.");
foreach ([IntlPartsIterator::KEY_SEQUENTIAL, IntlPartsIterator::KEY_LEFT, IntlPartsIterator::KEY_RIGHT] as $kt) {
    $parts = $w->getPartsIterator($kt);
    echo get_class($parts), ":";
    foreach ($parts as $k => $v) { echo " [$k]", json_encode($v), "/", $parts->getRuleStatus(); }
    echo "\n";
}
var_dump($w->getRuleStatusVec(), get_class($w->getPartsIterator()->getBreakIterator()));
$cp = IntlBreakIterator::createCodePointInstance();
echo get_class($cp), " ", var_export($cp->getText(), true), " ", $cp->first(), " ", $cp->next(), " ", $cp->getLastCodePoint(), "\n";
$cp->setText("aé日👍\xff");
$out = [];
while (($p = $cp->next()) !== IntlBreakIterator::DONE) { $out[] = $p . "=" . dechex($cp->getLastCodePoint()); }
echo implode(" ", $out), " | ", $cp->getLastCodePoint(), " ", $cp->last(), " ", $cp->previous(), " ", dechex($cp->getLastCodePoint()),
    " ", $cp->following(1), " ", $cp->preceding(6), " ", $cp->next(2), " ", $cp->next(-3), " ", var_export($cp->isBoundary(2), true), " ", $cp->current(), "\n";
foreach ($cp->getPartsIterator() as $k => $v) { echo $k, "=", bin2hex($v), " "; } echo "\n";
$r = new IntlRuleBasedBreakIterator('$d = [0-9]; $l = [a-z]; $d+ {100}; $l+ {200}; [^$d$l];');
$r->setText("ab12 c3");
foreach ($r->getPartsIterator(IntlPartsIterator::KEY_LEFT) as $k => $v) { echo "$k:", json_encode($v), ":", $r->getRuleStatus(), " "; } echo "\n";
echo $r->getRules(), "\n";
$bin = $r->getBinaryRules();
$r2 = new IntlRuleBasedBreakIterator($bin, true);
$r2->setText("zz99 y");
foreach ($r2 as $pos) { echo $pos, ","; } echo " ", $r2->getRules() === $r->getRules() ? "same rules" : "differ", "\n";
$c = clone $w; $c->setText("x y"); echo $w->getText(), " | ", $c->getText(), " ", $c->last(), " ", $w->last(), "\n";
foreach (['[a', '$x = ;', 'abc'] as $bad) {
    try { new IntlRuleBasedBreakIterator($bad); echo "ok\n"; } catch (IntlException $e) { echo $e->getMessage(), "\n"; }
}
try { new IntlRuleBasedBreakIterator("junk", true); } catch (IntlException $e) { echo $e->getMessage(), "\n"; }
var_dump($w->getLocale(5), intl_get_error_message(), $w->getErrorCode(), $w->getErrorMessage());
try { $w->following(1 << 40); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
try { $w->getPartsIterator(7); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
$e = IntlBreakIterator::createWordInstance("en"); var_dump($e->first(), $e->next(), $e->last(), $e->current());
