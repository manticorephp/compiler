<?php
// An array of enum cases passed to a bare `array` parameter reads back as a garbage float.
// issue: #513
enum Level: int { case Debug = 100; case Info = 200; }
function a(array $x) { var_dump($x['d']); }
a(['d' => Level::Info]);
