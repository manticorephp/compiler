<?php
$r = foo($a + $b * 2, [1, 'k' => $c]) ? function ($q) { return $q; } : match ($z) { 1 => 'a', default => 'b' };
