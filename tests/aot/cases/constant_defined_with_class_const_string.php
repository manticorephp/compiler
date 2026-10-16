<?php
// defined('Class::CONST') and constant('Class::CONST') do not see class constants (symfony's Kernel::preBoot builds such names)
class Cfg { public const LIMIT = 5; }
var_dump(defined('Cfg::LIMIT'));
var_dump(defined('Cfg::NOPE'));
var_dump(constant('Cfg::LIMIT'));
$name = 'Cfg' . '::' . 'LIMIT';
var_dump(\defined($name) ? \constant($name) : 'missing');
