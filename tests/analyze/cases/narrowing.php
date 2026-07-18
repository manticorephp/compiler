<?php

interface Shape {}
class Circle implements Shape { public function r(): float { return 1.0; } }
class Square implements Shape {}

function needsCircle(Circle $c): void {}
function needsSquare(Square $s): void {}

function useInstanceof(Shape $s): void
{
    if ($s instanceof Circle) {
        needsCircle($s);       // ok (narrowed to Circle)
        needsSquare($s);       // Circle given, Square expected (unrelated) -> flagged
    }
}

function guard(Shape $s): void
{
    if (!($s instanceof Circle)) { return; }
    needsCircle($s);           // ok (type-guard narrowed)
}

function nullable(?Circle $c): void
{
    if ($c !== null) {
        needsCircle($c);       // ok (non-null narrowed)
    }
}

function isIntNarrow(mixed $x): void
{
    if (is_string($x)) {
        echo $x - 1, "\n";     // string arithmetic (narrowed to string) -> flagged
    }
}
