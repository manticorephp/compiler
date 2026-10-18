<?php
// A bare `array` param read as (float) $o[2] and (string) $o[1002] crashes (an int read as a string pointer) once a caller also passes [].
function open(array $options): string {
    $t = isset($options[2]) ? (float) $options[2] : 30.0;
    $i = '';
    if (isset($options[1002])) { $i = (string) $options[1002]; }
    return $t . '|' . $i;
}
function call_open(?array $options = null): void { echo open($options === null ? [] : $options), "\n"; }
call_open([2 => 3]);
call_open();
