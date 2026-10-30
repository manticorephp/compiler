<?php
namespace App;
final class A { public static function make(): object { return new class { public function name(): string { return 'A'; } }; } }
