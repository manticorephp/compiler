<?php
// A call through an interface whose implementers return different classes is typed by what they share, not by the first implementer.
namespace D { interface St { public function ex(): string; } interface Co { public function prep(string $s): St; }
  interface Dr { public function con(): Co; } }
namespace I { use D\St as DS; final class Statement implements DS { public function __construct(private $h) {} public function ex(): string { return 'ibm'; } }
  final class Connection implements \D\Co { public function prep(string $s): Statement { return new Statement(1); } }
  final class Driver implements \D\Dr { public function con(): Connection { return new Connection; } } }
namespace P { use D\St as DS; final class Statement implements DS { public function __construct(private $h) {} public function ex(): string { return 'pdo'; } }
  final class Connection implements \D\Co { public function prep(string $s): Statement { return new Statement(1); } }
  final class Driver implements \D\Dr { public function con(): Connection { return new Connection; } } }
namespace M { abstract class AbsSt implements \D\St { public function __construct(private \D\St $w) {} public function ex(): string { return 'w' . $this->w->ex(); } }
  final class St2 extends AbsSt {} }
namespace X { use D\Co as DC;
 class Conn { protected ?DC $_c = null; public function __construct(private \D\Dr $d) {}
  protected function connect(): DC { if ($this->_c !== null) { return $this->_c; } $c = $this->_c = $this->d->con(); return $c; }
  public function q(array $p): string { $c = $this->connect(); if (count($p) > 0) { $s = $c->prep('x'); $r = $s->ex(); } else { $r = '-'; } return $r; } }
 echo (new Conn(new \P\Driver))->q([1]), (new Conn(new \I\Driver))->q([1]), "\n"; }
