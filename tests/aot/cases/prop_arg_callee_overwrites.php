<?php

// A property handed to a callee that OVERWRITES that same property while it
// still reads its argument: the slot's release of the old value frees what the
// callee was handed, unless the call counts as a borrow of the slot. The
// escape summary records which property slots a callee writes (here through
// `$this`, one call deep, and through a sibling argument evaluated after the
// operand); a callee writing a same-named slot of an UNRELATED class must not
// be confused with it. A foreach whose body overwrites its subject is the same
// hazard without a call.

final class Other
{
    public array $lt = [];
    /** @param array<string, int> $a */
    public function __construct(array $a) { $this->lt = ['x' => \count($a)]; }
}

final class S
{
    /** @var array<string, int> */
    public array $lt = [];

    /** @param array<string, int> $a */
    private function f(array $a): int
    {
        $this->lt = ['x' . \count($a) => 7];
        return $this->sum($a);
    }

    /** @param array<string, int> $a */
    private function viaHelper(array $a): int
    {
        $this->reset();
        return $this->sum($a);
    }

    private function reset(): void { $this->lt = ['r' => 1]; }

    private function resetAndCount(): int { $this->lt = ['q' => 2]; return 1; }

    /** @param array<string, int> $a */
    private function sum(array $a): int
    {
        $n = 0;
        foreach ($a as $k => $v) { $n = $n + \strlen($k) + $v; }
        return $n;
    }

    /** @param array<string, int> $a */
    private function sumPlus(array $a, int $x): int { return $this->sum($a) + $x; }

    /** A foreach whose body overwrites its own subject walks the ORIGINAL. */
    public function loop(int $i): int
    {
        $this->lt = ['a' . $i => 1, 'b' . $i => 2];
        $n = 0;
        foreach ($this->lt as $k => $v) {
            $this->lt = ['z' => 9];
            $n = $n + \strlen($k) + $v;
        }
        return $n;
    }

    public function run(int $i): int
    {
        $this->lt = ['key' . $i => $i, 'other' . $i => 2 * $i];
        $t = $this->f($this->lt);
        $this->lt = ['key' . $i => $i, 'other' . $i => 3 * $i];
        $t = $t + $this->viaHelper($this->lt);
        $this->lt = ['key' . $i => $i, 'other' . $i => 4 * $i];
        $t = $t + $this->sumPlus($this->lt, $this->resetAndCount());
        $this->lt = ['key' . $i => $i];
        $o = new Other($this->lt);
        return $t + $o->lt['x'] + $this->sum($this->lt);
    }
}

$s = new S();
$t = 0;
for ($i = 0; $i < 20000; $i++) { $t = $t + $s->run($i) + $s->loop($i); }
echo $t, "\n";
