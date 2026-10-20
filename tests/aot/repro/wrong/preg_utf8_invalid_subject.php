<?php
// preg_match / preg_replace with /u on an invalid UTF-8 subject answer 0 / the subject with no error instead of false / null + PREG_BAD_UTF8_ERROR
// issue: #372
$s = "a\x85b";
$r = preg_match('/\R/u', $s);
var_dump($r, preg_last_error());
$r = preg_replace('/x/u', 'y', $s);
var_dump($r === null, preg_last_error());
