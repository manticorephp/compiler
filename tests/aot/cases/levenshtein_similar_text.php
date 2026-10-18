<?php
// levenshtein / similar_text: empties, identical, one-sided, multibyte bytes, repeated runs, ties, percent
$pairs = [['', ''], ['', 'abc'], ['abc', ''], ['a', 'a'], ['a', 'b'], ['kitten', 'sitting'], ['flaw', 'lawn'], ['saturday', 'sunday'],
          ['abc', 'abc'], ['abc', 'cba'], ['abcabc', 'abc'], ['aaaa', 'aa'], ['aaaa', 'bbbb'], ["caf\u{e9}", 'cafe'], ["\u{4e2d}\u{6587}", "\u{4e2d}\u{6587}x"],
          ["\u{1F600}a", "a\u{1F600}"], ['World', 'Word'], ['Hello World', 'Hallo Welt'], ['PHP IS GREAT', 'WITH MYSQL'], ['WITH MYSQL', 'PHP IS GREAT'],
          ["a\0b", "a\0c"], [str_repeat('ab', 40), str_repeat('ba', 40)], [str_repeat('x', 100), str_repeat('x', 99) . 'y'], ['abcdefghij', 'jihgfedcba'],
          ['the quick brown fox', 'the quick brown fax'], ['xyz', 'abc'], ['ab', 'ba'], ['abab', 'baba']];
foreach ($pairs as [$a, $b]) {
    $p = 0.0;
    $s = similar_text($a, $b, $p);
    echo bin2hex(substr($a, 0, 6)), ' ', bin2hex(substr($b, 0, 6)), ' lev=', levenshtein($a, $b), ' sim=', $s, ' pct=', round($p, 6), "\n";
}
