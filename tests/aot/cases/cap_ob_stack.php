<?php
// @epic: sapi-layer
// @why: twig renders through output buffering, and symfony's error pages and
//       ErrorHandler both nest ob_* levels. No ob_* function exists.

var_dump(ob_get_level());

ob_start();
echo 'outer';
ob_start();
echo 'inner';
$in = ob_get_clean();
$out = ob_get_clean();

var_dump($in);
var_dump($out);
var_dump(ob_get_level());
