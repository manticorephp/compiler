<?php
function tag(string $s): string { return "[" . $s . "]"; }
$t = tag(...);
echo $t("hi"), "/", $t("bye");
