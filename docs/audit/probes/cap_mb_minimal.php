<?php
// @epic: mbstring
// @why: symfony/string, twig and the console table width logic all reach for
//       mb_*. symfony ships polyfill-mbstring, which defines these globally
//       behind function_exists guards -- so the question this probe answers is
//       whether the POLYFILL suffices, which decides whether native mb_* is
//       even on the critical path.

$s = 'héllo wörld';
foreach (['mb_strlen', 'mb_substr', 'mb_strtolower', 'mb_strtoupper',
          'mb_strpos', 'mb_str_split', 'mb_convert_encoding', 'mb_detect_encoding',
          'mb_internal_encoding', 'mb_ord', 'mb_strwidth'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'present' : 'missing', "\n";
}
echo 'strlen=', strlen($s), "\n";
if (function_exists('mb_strlen')) {
    echo 'mb_strlen=', mb_strlen($s), "\n";
    echo 'mb_substr=', mb_substr($s, 0, 4), "\n";
    echo 'mb_strtoupper=', mb_strtoupper($s), "\n";
}
