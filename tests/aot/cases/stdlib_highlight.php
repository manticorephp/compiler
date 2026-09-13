<?php

// highlight_string / highlight_file. Two rules carry the whole format and both
// are asserted below: WHITESPACE has no colour of its own (it rides the open
// span, so php only closes one when the colour actually CHANGES), and inline
// HTML closes the span and emits none of its own.

$src = "<?php\n// hi\n\$x = \"a\" . 1;\nfunction f() { return 2; }\n?>\ntail\n";
echo highlight_string($src, true), "\n";

// Every colour class in one line: comment, string, keyword, default, and the
// `"` delimiter of an interpolated string, which is the one single-character
// token that paints as a STRING.
$rich = "<?php\n\$s = \"a \$v b\";\nclass C extends P { const K = 1.5; }\n\$n = \\A\\B::f();\n/** doc */\n";
echo highlight_string($rich, true), "\n";

// Escaping is &, < and > only — a quote inside a literal stays a quote.
echo highlight_string("<?php\n\$a = 1 & 2;\n\$b = \"q\\\"q\";\n?>\ninline <b>&amp;</b> \"q\" & <\n", true), "\n";

// Inline HTML before the open tag, and a file that is nothing but HTML.
echo highlight_string("lead <i>x</i>\n<?php echo 1; ?>\ntrail\n", true), "\n";
echo highlight_string("no php here at all\n", true), "\n";
echo highlight_string('', true), "\n";

// The non-return form prints and answers true.
$r = highlight_string("<?php \$q = 1;\n", false);
echo "\n";
var_dump($r);

// The file form, its alias, and a file that is not there.
$p = tempnam(sys_get_temp_dir(), 'hl');
file_put_contents($p, "<?php\n\$z = 3;\n");
echo highlight_file($p, true), "\n";
var_dump(highlight_file($p, true) === show_source($p, true));
unlink($p);
var_dump(highlight_file($p, true));
