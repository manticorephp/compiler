<?php
namespace App;
final class B { public static function make(): object { return new class { public function name(): string { return 'B'; } }; } }
