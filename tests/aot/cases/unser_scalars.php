<?php

var_dump(unserialize('N;'));
var_dump(unserialize('b:1;'));
var_dump(unserialize('b:0;'));
var_dump(unserialize('i:0;'));
var_dump(unserialize('i:42;'));
var_dump(unserialize('i:-7;'));
var_dump(unserialize('i:9223372036854775807;'));
var_dump(unserialize('d:0;'));
var_dump(unserialize('d:1;'));
var_dump(unserialize('d:0.1;'));
var_dump(unserialize('d:0.30000000000000004;'));
var_dump(unserialize('d:-2.5;'));
var_dump(unserialize('d:1.0E+100;'));
var_dump(unserialize('d:INF;'));
var_dump(unserialize('d:-INF;'));
var_dump(unserialize('d:NAN;'));
var_dump(unserialize('s:0:"";'));
var_dump(unserialize('s:5:"hello";'));
var_dump(unserialize('s:12:"привіт";'));
// A quote and a semicolon INSIDE the body: the length header is what decides
// where the string ends, never a scan for the closing quote.
var_dump(unserialize('s:4:"a";b";'));
var_dump(unserialize(serialize('a";b')) === 'a";b');
