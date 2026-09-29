<?php
// ext/intl idn_to_ascii / idn_to_utf8 (UTS #46) and Spoofchecker over ICU.
foreach (["münchen.de", "пример.испытание", "xn--mnchen-3ya.de", "ÖBB.at", "faß.de", "a..b", "-bad-.com", "日本語.jp", "xn--zz", "ex ample.com", str_repeat("a", 70) . ".com", "Україна.укр"] as $d) {
    foreach ([IDNA_DEFAULT, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES, IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ] as $f) {
        $a = idn_to_ascii($d, $f, INTL_IDNA_VARIANT_UTS46, $info);
        $u = idn_to_utf8($d, $f, INTL_IDNA_VARIANT_UTS46, $info2);
        echo json_encode($d), " $f ", var_export($a, true), " ", json_encode($info), " | ", var_export($u, true), " ", json_encode($info2), "\n";
    }
}
var_dump(idn_to_ascii("bücher.example"), idn_to_utf8("xn--bcher-kva.example"));
try { idn_to_ascii(""); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
try { idn_to_utf8("a", 0, 0); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
$s = new Spoofchecker();
foreach (["paypal", "раураl", "Ｐaypal", "hello", "Һello", "аррӏе", "abc123", "a\u{200B}b", "ελληνικά", "日本語テキスト", "abcабв"] as $t) {
    echo json_encode($t), " ", var_export($s->isSuspicious($t, $code), true), " ", $code, "\n";
}
foreach ([["paypal", "раураl"], ["hello", "Һello"], ["scope", "ѕсоре"], ["abc", "xyz"], ["1l", "Il"]] as [$x, $y]) {
    echo json_encode($x), "~", json_encode($y), " ", var_export($s->areConfusable($x, $y, $c2), true), " ", $c2, "\n";
}
$s->setChecks(Spoofchecker::MIXED_SCRIPT_CONFUSABLE | Spoofchecker::INVISIBLE);
var_dump($s->isSuspicious("раураl"), $s->isSuspicious("a\u{0301}\u{0301}"));
$s->setRestrictionLevel(Spoofchecker::ASCII);
$s->setChecks(Spoofchecker::SINGLE_SCRIPT | Spoofchecker::CHAR_LIMIT);
$s->setAllowedLocales("en_US, uk_UA");
var_dump($s->isSuspicious("hello"), $s->isSuspicious("привіт"), $s->isSuspicious("日本"));
$s->setAllowedChars("[a-z]", Spoofchecker::IGNORE_SPACE);
var_dump($s->isSuspicious("abc"), $s->isSuspicious("ABC"));
$s->setAllowedChars("[a-z]", Spoofchecker::IGNORE_SPACE | Spoofchecker::CASE_INSENSITIVE);
var_dump($s->isSuspicious("ABC"));
foreach ([fn() => $s->setRestrictionLevel(5), fn() => $s->setAllowedChars("abc"), fn() => $s->setAllowedChars("[a-z]", 9), fn() => $s->setAllowedChars("[a-")] as $bad) {
    try { $bad(); echo "ok\n"; } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
}
$c = clone $s; var_dump($c->isSuspicious("abc"));
