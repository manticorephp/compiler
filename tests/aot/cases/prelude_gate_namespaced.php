<?php
// A program may own `requestBegin()`; that must not veto the SAPI prelude,
// whose own requestBegin lives in Manticore\Sapi.
function requestBegin(): string { return 'mine'; }
header('X-A: 1');
echo requestBegin(), ' ', count(headers_list()), ' ', function_exists('header') ? 'y' : 'n', "\n";
