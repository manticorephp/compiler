<?php
// `$r = &$this->p; unset($r); $r = [99];` traps instead of rebinding $r to a fresh local
final class B
{
    public array $a = [1 => [1]];

    public function g(): void
    {
        $r = &$this->a;
        $r[3] = [7];
        unset($r);
        $r = [99];
    }
}
$o = new B();
$o->g();
echo json_encode($o->a), "\n";
