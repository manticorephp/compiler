<?php
// range("1", "4") returns ints; php 8.5 keeps numeric-string endpoints as strings.
// issue: #284
var_dump(range("1", "3"));
