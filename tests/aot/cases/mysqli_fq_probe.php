<?php
// A fully qualified probe of a mysqli name links the extension (the only mention is a string literal).
var_dump(\class_exists('mysqli'), \function_exists('\\mysqli_connect'), \defined('MYSQLI_ASSOC'));
