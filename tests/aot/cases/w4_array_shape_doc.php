<?php
// A docblock array SHAPE (`array{0:Node,1:bool}`) names heterogeneous
// elements: the pair is an ARRAY, never a string, so `$pair[0]` is an element
// read and not a char offset. (ApplyMemoryMode::demote compared a 1-char
// string against a Node for a year and never matched.)
final class Node
{
    /** @param Node[] $children */
    public function __construct(public int $kind, public array $children = []) {}
}

final class Demoter
{
    /** @param array<int, array{0:Node,1:bool}> $loops */
    private function demote(Node $n, array $loops, bool $reclaimed): void
    {
        if ($n->kind === 1) {
            $reclaimed = false;
            foreach ($loops as $pair) {
                if ($pair[0] === $n) { $reclaimed = $pair[1]; break; }
            }
        }
        if (!$reclaimed) { $n->kind = 7; }
        foreach ($n->children as $c) { $this->demote($c, $loops, $reclaimed); }
    }

    /** @param array<int, array{0:Node,1:bool}> $out */
    private function collect(Node $n, array &$out): void
    {
        if ($n->kind === 1) { $out[] = [$n, $n->children !== []]; }
        foreach ($n->children as $c) { $this->collect($c, $out); }
    }

    public function run(Node $root): void
    {
        $loops = [];
        $this->collect($root, $loops);
        echo count($loops), " loops\n";
        $this->demote($root, $loops, true);
    }

    /** @param array{name: string, tags: string[], hits: int} $rec */
    public function describe(array $rec): string
    {
        return $rec['name'] . ':' . implode('|', $rec['tags']) . ':' . $rec['hits'];
    }
}

$a = new Node(1, [new Node(3)]);
$b = new Node(2);
$c = new Node(1);
$root = new Node(0, [$a, $b, $c, new Node(4, [new Node(1, [new Node(5)])])]);
$d = new Demoter();
$d->run($root);
$out = [];
$walk = function (Node $n) use (&$walk, &$out): void {
    $out[] = $n->kind;
    foreach ($n->children as $c) { $walk($c); }
};
$walk($root);
echo implode(' ', $out), "\n";
echo $d->describe(['name' => 'x', 'tags' => ['a', 'b'], 'hits' => 3]), "\n";
