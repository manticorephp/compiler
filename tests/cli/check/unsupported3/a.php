<?php
$a = [1];
$o = new stdClass;
foreach ($a as &$o->p) {}
