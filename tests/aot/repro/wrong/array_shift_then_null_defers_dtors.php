<?php
// after array_shift, $q = null does not destroy the remaining objects until exit
// issue: #315
class D { public function __construct(public string $n) {} public function __destruct() { echo "~", $this->n, " "; } }
function objs(): void
{
    $q = [new D('a'), new D('b')];
    array_shift($q);
    $q = null;
    echo "end\n";
}
objs();
