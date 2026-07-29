<?php

// php also emits a warning for each of these; the RETURN VALUE is what is
// compared, so the warnings are suppressed (php's CLI writes them to stdout).
var_dump(@unserialize(''));
var_dump(@unserialize('x'));
var_dump(@unserialize('i:'));
var_dump(@unserialize('i:1'));
var_dump(@unserialize('s:5:"ab";'));
var_dump(@unserialize('s:2:ab";'));
var_dump(@unserialize('a:2:{i:0;i:1;}'));
var_dump(@unserialize('a:1:{i:0;'));
var_dump(@unserialize('a:1:'));
var_dump(@unserialize('r:9;'));
var_dump(@unserialize('N'));
var_dump(@unserialize('Q:1;'));
// A complete value followed by junk is accepted, and the junk ignored.
var_dump(@unserialize('i:1;garbage'));
var_dump(@unserialize('N;rest'));
