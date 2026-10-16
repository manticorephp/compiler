<?php

// The global CASE_LOWER / CASE_UPPER constants (symfony's Request::getPreferredFormat path).

var_dump(CASE_LOWER, CASE_UPPER);
print_r(array_change_key_case(['Ab' => 1, 'cD' => 2, 3 => 'x'], CASE_UPPER));
print_r(array_change_key_case(['Ab' => 1], CASE_LOWER));
