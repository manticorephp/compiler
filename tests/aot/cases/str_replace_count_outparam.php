<?php

// str_replace()'s fourth parameter is by REFERENCE, and declaring it is what
// lets the call compile: an argument in a by-ref position is a DEFINITION, so a
// caller writing `str_replace($s, $r, $subj, $count)` against a three-parameter
// declaration left $count dangling and the whole program was refused.
//
// The corpus witness is symfony/framework-bundle CacheClearCommand::__invoke,
// which is the ARRAY-search arm and then branches on `if ($count)`. php applies
// the pairs sequentially and counts against the subject as it stands when each
// pair runs, so the count accumulates over an already-partially-replaced string.

$out = str_replace("l", "L", "hello world", $count);
var_dump($out, $count);

// array search, scalar replace — the CacheClearCommand shape
$arr = str_replace(["a", "b"], "b", "a", $c2);
var_dump($arr, $c2);

// array search, array replace (positional)
$pos = str_replace(["a", "b"], ["1", "2"], "aabbb", $c3);
var_dump($pos, $c3);

// no match at all, and an empty search: both count zero
$miss = str_replace("zz", "x", "abc", $c4);
var_dump($miss, $c4);
$empty = str_replace("", "x", "abc", $c5);
var_dump($empty, $c5);

// an explicitly initialised out-var takes the ordinary store path, not the
// vivify-a-dangling-local one, and must still be overwritten
$c6 = 99;
$again = str_replace("o", "0", "foo boo", $c6);
var_dump($again, $c6);

// the argument omitted entirely — the throwaway-slot path
var_dump(str_replace("o", "0", "foo"));
