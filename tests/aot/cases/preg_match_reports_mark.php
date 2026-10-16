<?php
// preg_match reports the last `(*MARK:name)` / `(*:name)` passed under 'MARK', as php does.
function ids(mixed $v): mixed { return $v; }
var_dump(preg_match(ids('{^(?|/_error/(\d+)(?:\.([^/]++))?(*:35)|/a/(\w+)(*:52))/?$}sD'), ids('/_error/404.json'), $m), $m);
var_dump(preg_match('/(*MARK:first)x|(*MARK:second)y/', 'y', $m), $m);
var_dump(preg_match('/a(b)/', 'ab', $m), $m);
