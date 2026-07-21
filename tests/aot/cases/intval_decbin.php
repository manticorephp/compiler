<?php

// intval of a mixed/cell value must DECODE the tag, not read the raw carrier.
function iv(mixed $v): int { return intval($v); }
var_dump(iv("99"));
var_dump(iv(7));
var_dump(iv(3.9));
var_dump(iv(true));
var_dump(iv("0x1A"));   // no base -> parses leading digits -> 0

// static-typed intval paths still work
var_dump(intval("42abc"));
var_dump(intval("0x1A", 16));
var_dump(intval("077", 8));
var_dump(intval(-4.7));

// decbin: non-negative unchanged; negative is full 64-bit two's complement
var_dump(decbin(0));
var_dump(decbin(5));
var_dump(decbin(255));
var_dump(decbin(-1));
var_dump(decbin(-5));
var_dump(decbin(-256));
