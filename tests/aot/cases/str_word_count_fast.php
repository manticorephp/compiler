<?php
foreach (["", "a", "ab cd", "it's well-known", "x1y2z", "  lead and trail  ", "Zz@[`{", "don't--stop", str_repeat("word ", 33)] as $s) {
    echo str_word_count($s), ' ', json_encode(str_word_count($s, 1)), ' ', json_encode(str_word_count($s, 2)), "\n";
}
echo str_word_count(str_repeat("The quick brown fox, jumps over the lazy dog.\n", 3000)), "\n";
