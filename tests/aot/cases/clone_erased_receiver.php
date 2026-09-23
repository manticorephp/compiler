<?php

final class Token
{
    /** @param array{int, string}|string $token */
    public function __construct(public array|string $token) {}
}

final class Pt { public function __construct(public int $x = 0) {} }

// `clone` over a value whose class is not static: an untyped array's element.
// It must be a real COPY, and the copy a proper value wherever it goes.
function copies(array $params): array
{
    $out = [new Token(',')];
    foreach ($params as $group) {
        foreach ($group as $param) {
            $out[] = clone $param;
        }
    }
    return $out;
}

$orig = new Token('a');
$res = copies([[$orig, new Token([1, 'b'])]]);
$res[1]->token = 'changed';
echo $orig->token, ' ', $res[1]->token, ' ', count($res), "\n";

function bump(mixed $p): mixed { $c = clone $p; $c->x = $c->x + 1; return $c; }
$p = new Pt(1);
$q = bump($p);
var_dump($p->x, $q->x, $p !== $q);
