<?php
// A nullsafe call with a variable method name on null (`$n?->$h(1)`) does not yield null.
$n = null;
$h = "m";
var_dump($n?->$h(1));
