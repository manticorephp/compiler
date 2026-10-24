<?php
interface I { const ?int X = null; final const string Y = 'y'; }
enum E: string { case A = 'a'; const ?string N = null; const int|string M = 3; }
class A implements I {
    const ?int X2 = null; const string|int Y2 = 5;
    public const ?string P = 'x', Q = null;
    final public const ?float F = 1.5;
    public const int|null Z = null;
    const array|null ARR = [1];
}
var_dump(A::X2, A::Y2, A::P, A::Q, A::F, A::Z, A::ARR, A::X, A::Y, E::N, E::M);
