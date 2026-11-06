<?php
class H { public array $nodes;
  public function __construct(array $n) { $this->nodes = $n; }
  public function rm(string $name): void { unset($this->nodes[$name]); } }
$h = new H(['a', 'b', 'c', 'd']);
$h->rm('1'); echo json_encode($h->nodes), "\n";
$h->rm('2'); echo json_encode($h->nodes), "\n";
$h->rm('0'); echo json_encode($h->nodes), count($h->nodes), "\n";
class El { public function __construct(public string $n) {} public function __destruct() { echo "[free {$this->n}]"; } }
class H2 { private array $nodes;
  public function __construct(array $n) { $this->nodes = $n; }
  public function removeNode(string $name): void { unset($this->nodes[$name]); }
  public function names(): string { $o = ''; foreach ($this->nodes as $k => $n) { $o .= "$k:{$n->n} "; } return $o; } }
function run(): void {
  $h = new H2([new El('keep'), new El('x1'), new El('x2'), new El('keep')]);
  $h->removeNode('1'); $h->removeNode((string)2);
  echo "\nafter: ", $h->names(), "\n";
}
run(); echo "\ndone\n";
