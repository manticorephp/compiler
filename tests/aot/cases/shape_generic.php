<?php
final class Node { public function __construct(public int $kind) {} }

/**
 * @template T
 */
final class Pairs
{
    /** @var array<int, array{0:T,1:int}> */
    private array $items = [];

    /** @param T $v */
    public function add(mixed $v, int $w): void { $this->items[] = [$v, $w]; }

    /** @return array{0:T,1:int} */
    public function at(int $i): array { return $this->items[$i]; }

    public function weight(int $i): int { $p = $this->items[$i]; return $p[1]; }
}

/**
 * @template T
 * @param array{0:T,1:int} $pair
 * @return T
 */
function left(array $pair): mixed { return $pair[0]; }

/** @var Pairs<Node> $ps */
$ps = new Pairs();
$ps->add(new Node(3), 10);
$ps->add(new Node(5), 20);
$p = $ps->at(1);
echo $p[0]->kind, ' ', $p[1], ' ', $ps->weight(0), "\n";
echo left([new Node(8), 1])->kind, "\n";
echo left(['s', 2]), "\n";
