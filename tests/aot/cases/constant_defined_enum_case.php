<?php
namespace Mon;
enum Level: int { case Debug = 100; case Emergency = 600; const NAMES = ['x']; }
$level = 'emergency';
$upper = strtr(substr($level, 0, 1), 'dinweca', 'DINWECA') . strtolower(substr($level, 1));
var_dump($upper, \defined(Level::class.'::'.$upper), \defined('Mon\Level::Emergency'), \defined(Level::class.'::NAMES'));
var_dump(\constant(Level::class . '::' . $upper));
var_dump(\defined(Level::class . '::Nope'), \defined('Mon\Level::Debug'));
try { \constant(Level::class . '::Nope'); } catch (\Error $e) { echo $e->getMessage(), "\n"; }
