<?php
class Meta { const IS_INSTANCEOF = 2; }
class Unused extends Missing\Base { const K = 1; }
function f(Meta $m): int { return $m::IS_INSTANCEOF; }
echo f(new Meta()), "\n";
