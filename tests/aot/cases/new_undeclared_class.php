<?php
function mk(bool $go): object { if ($go) { return new NoSuchClassAnywhere(); } return new stdClass(); }
echo get_class(mk(false)), "\n";
try { mk(true); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
