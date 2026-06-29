<?php
class Node {
    public int $val = 7;
    public array $kids = [10, 20, 30];
    public array $names = ['ann', 'bo'];
    public function dump(): string {
        return "val=$this->val first={$this->kids[0]} name={$this->names[1]}";
    }
}
$n = new Node();
echo $n->dump(), "\n";
echo $n->kids[2], "\n";
echo "elem {$n->names[0]} end\n";
