<?php
// A destructor runs ONCE. One that stores `$this` somewhere (resurrection) keeps the object
// alive with its properties; one that stores and drops it again must not re-enter itself (it
// recursed until the stack overflowed); a resurrected object freed later is not destructed
// a second time.

final class Reg
{
    /** @var array<int, D> */
    public static array $keep = [];
}

class D
{
    public function __construct(public string $n, public array $data = [1, 2]) {}

    public function __destruct()
    {
        echo "destruct ", $this->n, "\n";
        if ($this->n === "keep") {
            Reg::$keep[] = $this;
        } else {
            Reg::$keep[99] = $this;
            unset(Reg::$keep[99]);
        }
    }
}

function scope(): void
{
    $a = new D("tmp");
    $b = new D("keep", [3, 4, 5]);
}

scope();
$k = array_values(Reg::$keep)[0];
echo "alive: ", count(Reg::$keep), " ", $k->n, " ", array_sum($k->data), "\n";
$k = null;
Reg::$keep = [];
echo "cleared\n";
$x = new D("again");
$x = null;
echo "end\n";
