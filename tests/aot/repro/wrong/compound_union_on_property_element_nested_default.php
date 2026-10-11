<?php
// `$this->m[$k] += $arr` on a property whose default is a nested array literal reads the element as int and prints an address
// issue: #194
class B
{
    public array $m = ['F' => ['a' => 1]];

    public function t(string $e, array $p): void
    {
        $this->m[$e] += $p;
        echo json_encode($this->m), "\n";
    }
}
(new B())->t('F', ['c' => 3]);
