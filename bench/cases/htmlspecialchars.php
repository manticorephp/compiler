<?php
// htmlspecialchars — the CMS page-render profile (issue #3): a mix of short
// attribute values with quotes, a markup-heavy template chunk, and plain prose
// with nothing to escape (the fast path). The subject is picked data-dependently
// so the escape isn't hoisted; the accumulator folds the output lengths.
$short = ["Hello <b>world</b> & \"friends\"", "it's a 'test'", "plain title", "a<b>c&d"];
$markup = str_repeat("<p class=\"x\">Hello & 'world' — plain text runs here 1234567890</p>\n", 20);
$prose = str_repeat("plain text without any specials at all, lorem ipsum dolor sit amet ", 30);
$m = count($short);
$acc = 1;
$n = 200000 * $argc;
for ($i = 0; $i < $n; $i++) {
    $acc += strlen(htmlspecialchars($short[($i + $acc) % $m], ENT_QUOTES, 'UTF-8'));
    if (($i & 15) === 0) {
        $acc += strlen(htmlspecialchars($markup));
        $acc += strlen(htmlspecialchars($prose, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false));
    }
}
echo $acc, "\n";
