<?php
// preg_* with /u on invalid UTF-8 returns 0 / the subject instead of false / null, and preg_last_error() is stale.
// issue: #367
$r = preg_match('/a/u', "a\xff");
echo var_export($r, true), ' ', preg_last_error(), "\n";
$r = preg_replace('/a/u', 'b', "a\xff");
echo var_export($r, true), ' ', preg_last_error(), "\n";
$r = preg_split('/a/u', "a\xff");
echo var_export($r, true), ' ', preg_last_error(), "\n";
$r = preg_match('/a/u', 'a');
echo var_export($r, true), ' ', preg_last_error(), "\n";
