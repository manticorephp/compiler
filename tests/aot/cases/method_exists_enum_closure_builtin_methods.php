<?php
// method_exists() and get_class_methods() miss the built-in methods of enums (cases/from/tryFrom) and Closure.
enum Suit { case H; public function color() {} }
enum B: string { case H = 'h'; public function x() {} }
print_r(get_class_methods(Suit::H));
print_r(get_class_methods('B'));
print_r(get_class_methods(fn () => 1));
var_dump(method_exists('Closure', '__invoke'), method_exists('Suit', 'cases'), method_exists(fn () => 1, '__invoke'));
