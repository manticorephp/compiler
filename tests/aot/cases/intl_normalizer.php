<?php
// ext/intl over the host ICU (linked dynamically), first consumer: Normalizer.
var_dump(normalizer_normalize("e\u{301}") === "\u{e9}");
echo bin2hex(Normalizer::normalize("\u{e9}", Normalizer::FORM_D)), " ", normalizer_normalize("ﬁ①", Normalizer::FORM_KC), " ",
    normalizer_normalize("ÄÖÜ ß", Normalizer::NFKC_CF), " ", normalizer_normalize("가", Normalizer::FORM_D) === "\u{1100}\u{1161}" ? "hangul" : "?", "\n";
var_dump(normalizer_normalize("\xFF"), normalizer_normalize(""), normalizer_is_normalized("e\u{301}"),
    Normalizer::isNormalized("abc"), normalizer_is_normalized("\u{e9}", Normalizer::FORM_D), normalizer_is_normalized("\xC3"));
echo strlen(normalizer_normalize(str_repeat("e\u{301}", 5000))), "\n";
try { normalizer_normalize("x", 99); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
