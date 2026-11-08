<?php
interface MC { function s(): void; }
class M implements MC { function s(): void {} public $p = 4; }
class N implements MC { function s(): void {} }
class J {
    public MC $m;
    function __construct(MC $m) { $this->m = $m; }
    function g() { return $this->m->p ?? 3; }
}
echo (new J(new M))->g(), (new J(new N))->g(), "\n";
