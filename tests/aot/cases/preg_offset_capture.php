<?php
// PREG_OFFSET_CAPTURE was silently IGNORED: preg_match_all returned plain
// strings, so symfony's OutputFormatter — which reads $match[0] as the text and
// $match[1] as the byte offset — indexed the matched STRING instead, getting a
// one-character text and a LETTER for a position. Every style tag survived into
// the output and `<` doubled around it.
$msg = '<info>probe</info> <comment>1.0.0</comment>';
$n = \preg_match_all('#<((\w+) | /(\w+)?)>#ix', $msg, $m, \PREG_OFFSET_CAPTURE);
echo 'n=', $n, "\n";
foreach ($m[0] as $i => $match) {
    echo '  [', $i, '] text=', \var_export($match[0], true), ' pos=', \var_export($match[1], true), "\n";
}
// Rebuild the way the formatter does — this is what was corrupted.
$offset = 0;
$out = '';
foreach ($m[0] as $match) {
    $out .= \substr($msg, $offset, $match[1] - $offset);
    $offset = $match[1] + \strlen($match[0]);
}
$out .= \substr($msg, $offset);
echo 'stripped=', \var_export($out, true), "\n";

// SET_ORDER combined with the flag, and a non-participating group (offset -1).
$n2 = \preg_match_all('/(a)(b)?/', 'ab a', $m2, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
echo 'n2=', $n2, "\n";
foreach ($m2 as $set) {
    foreach ($set as $g) { echo '  g=', \var_export($g[0], true), '@', \var_export($g[1], true), "\n"; }
}
// Without the flag the shape must stay plain strings.
$n3 = \preg_match_all('/\d+/', 'a1 b22 c333', $m3);
echo 'n3=', $n3, ' [', \implode(',', $m3[0]), "]\n";
