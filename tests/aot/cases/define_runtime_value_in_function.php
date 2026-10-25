<?php
define('FIRST', $argv[1] ?? 'none');
define('UP', strtoupper('abc') . FIRST);
if (!defined('GUARDED')) { define('GUARDED', count($argv) + 41); }
function f(): string { return FIRST; }
class K { function g(): string { return UP . '/' . GUARDED . '/' . constant('FIRST'); } }
echo f(), "\n", (new K)->g(), "\n", defined('FIRST') ? 'y' : 'n', "\n";
