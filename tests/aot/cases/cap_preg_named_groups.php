<?php
// @epic: regex-surface
// @why: symfony/routing compiles every route to a regex with named groups and
//       matches with PREG_UNMATCHED_AS_NULL; the OutputFormatter uses
//       PREG_OFFSET_CAPTURE. A gap here breaks routing outright.

$re = '#^/(?P<_locale>[a-z]{2})/blog/(?P<page>\d+)?$#';
var_dump(preg_match($re, '/en/blog/3', $m));
var_dump($m['_locale'], $m['page']);

var_dump(preg_match($re, '/en/blog/', $m2, PREG_UNMATCHED_AS_NULL));
var_dump($m2['page']);

var_dump(preg_match('/(o)/', 'foo bar', $m3, PREG_OFFSET_CAPTURE));
var_dump($m3[1]);

var_dump(preg_match_all('/(\d+)/', 'a1b22c333', $all));
var_dump($all[1]);
var_dump(preg_replace_callback('/\d+/', fn ($x) => '[' . $x[0] . ']', 'a1b22'));
