<?php
// a get hook result stored in a local is released once; the hook reads its own backing slot unowned
final class A {
    private string $_v = 'ab';
    public string $v { get => $this->_v . 'c'; set => $this->_v = $value; }
}
$a = new A();
$a->v = 'q';
for ($i = 0; $i < 3; $i++) { $x = $a->v; $y = $x . $a->v; echo $y, "\n"; }
