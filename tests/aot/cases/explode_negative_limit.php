<?php
// explode's NEGATIVE limit drops the last -limit components and returns whole
// ones — it is not the positive form, whose final element carries the rest of
// the string. The split loop only ran while `limit > 1`, so a negative one fell
// straight through to the tail and answered [subject]. symfony's
// `explode(':', $name, -1)` (Application::extractNamespace) then put every
// command in a namespace of its own.

var_dump(explode(':', 'completion', -1));
var_dump(explode(':', 'config:set', -1));
var_dump(explode(':', 'a:b:c', -1));
var_dump(explode(':', 'a:b:c', -2));
var_dump(explode(':', 'a:b:c', -3));
var_dump(explode(':', 'a:b:c', -4));
var_dump(explode(':', 'a', -3));
var_dump(explode('::', 'a::b::c', -1));

// The positive and zero forms are unchanged.
var_dump(explode(':', 'a:b:c'));
var_dump(explode(':', 'a:b:c', 2));
var_dump(explode(':', 'a:b:c', 0));
var_dump(explode(':', 'a:b:c', 99));

$n = -1;
var_dump(explode(':', 'x:y', $n));
