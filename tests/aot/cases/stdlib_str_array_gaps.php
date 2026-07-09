<?php
echo nl2br("a\nb\r\nc"), "\n";
echo nl2br("x\ny", false), "\n";
echo str_word_count("the quick brown fox"), "\n";
echo str_word_count("don't stop-now hey"), "\n";
echo str_word_count(""), "\n";
$recs = [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']];
print_r(array_column($recs, 'name'));
print_r(array_column($recs, 'name', 'id'));
print_r(array_column($recs, null, 'id'));
