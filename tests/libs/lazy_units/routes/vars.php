<?php
// Top-level variables of a lazily required file live in the global scope.
$cfg = new \stdClass();
$cfg->path = "/srv";
$count = 3;
function lazy_cfg_path(): string { global $cfg; return $cfg->path; }
function lazy_count(): int { return $GLOBALS["count"]; }
