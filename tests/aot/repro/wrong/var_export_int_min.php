<?php
// var_export(PHP_INT_MIN) prints the literal -9223372036854775808 instead of -9223372036854775807-1
// issue: #330
var_export(PHP_INT_MIN);
echo "\n";
var_export([PHP_INT_MIN]);
echo "\n";
