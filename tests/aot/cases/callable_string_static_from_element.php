<?php
// A 'Class::method' string in a callable param, read out of a heterogeneous array, is jumped to as a closure.
class Svc { public function index(): string { return "ok"; } public static function st(): string { return "st"; } }
function p(callable $c) { return $c(); }
foreach ([[new Svc, 'index'], 'Svc::st'] as $c) { echo p($c), "\n"; }
