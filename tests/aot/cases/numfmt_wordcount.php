<?php

// number_format pre-rounds at $decimals (php_round) — cancels binary float error.
var_dump(number_format(1.005, 2));   // "1.01"
var_dump(number_format(2.675, 2));   // "2.68"
var_dump(number_format(0.5));        // "1"
var_dump(number_format(1234567.891, 2));
var_dump(number_format(-1234.5, 0, ".", " "));
var_dump(number_format(1000));

// str_word_count: 0 = count (int), 1 = list, 2 = keyed by start offset.
var_dump(str_word_count("one two three"));
var_dump(str_word_count("one two three", 1));
var_dump(str_word_count("hello world", 2));
var_dump(str_word_count("it's a test-case"));
var_dump(str_word_count("it's a test-case", 1));
var_dump(str_word_count(""));
// the mode-0 int result stays a usable int (union return does not erase it)
$c = str_word_count("a b c d");
echo $c + 10, "\n";
var_dump($c * 2);
