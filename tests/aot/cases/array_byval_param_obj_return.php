<?php

// A read out of an element-type-ERASED array (bare `array` param) returned as a
// declared object type: the callee must still take the +1, because the caller
// owns the result per the DECLARED return type and will release it. Deciding the
// retain from the (erased) expression type skipped it → the array's element was
// freed while the array still owned it → SIGTRAP.

final class Item
{
    public function __construct(public readonly string $tag, public readonly int $n) {}
}

final class Box
{
    /** @var Item[] */
    private array $objs = [];
    /** @var string[] */
    private array $strs = [];

    public function seed(): void
    {
        $this->objs = [new Item('a', 1), new Item('b', 2)];
        $this->strs = ['outer', 'inner'];
    }

    // bare `array` — the element type is erased at the param boundary
    private function pickObj(array $a, int $i): Item { return $a[$i]; }

    private function pickStr(array $a, int $i): string { return $a[$i]; }

    /** the outward-index shape: last element, counting from the end */
    private function outward(array $a, int $level): string
    {
        $n = \count($a);
        if ($n === 0) { return 'EMPTY'; }
        $idx = $n - $level;
        if ($idx < 0) { $idx = 0; }
        return $a[$idx];
    }

    public function run(): void
    {
        $first = $this->pickObj($this->objs, 0);
        $second = $this->pickObj($this->objs, 1);
        echo $first->tag, $first->n, "\n";
        echo $second->tag, $second->n, "\n";
        // the array still owns its elements — reading again must be safe
        echo $this->pickObj($this->objs, 1)->tag, "\n";
        echo $this->pickStr($this->strs, 0), "\n";
        echo $this->outward($this->strs, 1), "\n";
        echo $this->outward($this->strs, 2), "\n";
        echo $this->outward($this->strs, 9), "\n";
    }
}

$b = new Box();
$b->seed();
$b->run();
