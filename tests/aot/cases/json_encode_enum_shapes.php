<?php
namespace App;
enum E: string { case A = 'a'; case B = 'b'; }
enum N: int { case One = 1; case Two = 2; public function twice(): int { return $this->value * 2; } }
final class Box { public function __construct(public E $e, public N $n, public ?E $none = null) {} }
echo json_encode(E::A), json_encode([E::B, N::Two]), json_encode(['k' => E::A, 'n' => N::One]), "\n";
echo json_encode(new Box(E::B, N::Two)), "\n";
echo json_encode(['x' => N::One], JSON_PRETTY_PRINT), "\n";
function enc(mixed $v): string { return json_encode($v); }
echo enc(N::Two), enc(E::A), "\n";
$b = new Box(E::A, N::One);
var_dump(get_object_vars($b)['e'] === E::A, ((array)$b)['n'] === N::One);
