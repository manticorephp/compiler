<?php
// CONFIRMED self-host bug. A bare `public array $d` property whose string VALUES
// are read back (foreach / direct index without a typed coercion) yields raw
// pointer ints instead of the strings. A `/** @var array<string,string> */`
// annotation on the property pins the value type and fixes it.
//
//   Expected (php):  A__run=A->run  A__stop=A->stop  B__go=B->go
//   Actual   (mc):   A__run=4340177744  A__stop=...  B__go=...   (pointer ints)
//
// Note: a LOCAL assoc with the same shape works — only the PROPERTY erases.
// Note: `function get(string $k): string { return $this->d[$k]; }` works too —
//       the `: string` return coerces. Only the untyped read (foreach $v) erases.
class M {
    public array $d = [];   // FIX: /** @var array<string,string> */ public array $d = [];
    function add(string $cls, string $m): void { $this->d[$cls."__".$m] = $cls."->".$m; }
    function all(): array { return $this->d; }
}
$m = new M();
$m->add("A","run"); $m->add("A","stop"); $m->add("B","go");
foreach ($m->all() as $k => $v) { echo $k, "=", $v, "\n"; }
