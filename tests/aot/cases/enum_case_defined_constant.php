<?php
namespace M;
enum Level: int { case Debug = 100; case Info = 200; const NAMES = ['x']; }
$lv = 'debug';
$upper = strtr(substr($lv, 0, 1), 'dinweca', 'DINWECA') . strtolower(substr($lv, 1));
var_dump($upper, defined(Level::class.'::'.$upper), defined('M\Level::Debug'), defined(Level::class.'::NAMES'));
var_dump(constant(Level::class . '::' . $upper));
var_dump(constant('M\Level::Info'));
