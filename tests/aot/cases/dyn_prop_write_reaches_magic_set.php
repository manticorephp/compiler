<?php
class M {
    protected $attributes = [];
    public function col() { return 'updated_at'; }
    public function __set($k, $v) { $this->attributes[$k] = $v; }
    public function __get($k) { return $this->attributes[$k] ?? null; }
    public function all() { return $this->attributes; }
    public function setUpd($v) { $this->{$this->col()} = $v; return $this; }
}
class N extends M {}
$m = new N;
$m->literal = 1;
$n = 'viaVar'; $m->$n = 2;
$m->{'viaBrace'} = 3;
$m->{$m->col()} = 4;
$m->setUpd(5);
echo json_encode($m->all()), "\n";
