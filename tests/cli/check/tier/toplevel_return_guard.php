<?php
$c = $argv[1] ?? "X";
if (!class_exists($c)) { return; }
new Missing\Q();
if (!extension_loaded('apcux')) { return; }
apcux_fetch('k');
