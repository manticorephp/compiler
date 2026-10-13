<?php
class Cfg { public const LIMIT = 5; }
var_dump(defined('Cfg::LIMIT'));
var_dump(defined('Cfg::NOPE'));
var_dump(constant('Cfg::LIMIT'));
$name = 'Cfg' . '::' . 'LIMIT';
var_dump(\defined($name) ? \constant($name) : 'missing');
