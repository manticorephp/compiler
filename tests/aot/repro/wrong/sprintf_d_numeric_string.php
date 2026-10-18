<?php
// sprintf('%d', '42') with a numeric string argument prints a pointer instead of 42.
// issue: #320
var_dump(sprintf('%d', '42'));
