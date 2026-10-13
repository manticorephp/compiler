<?php
class Subj implements SplSubject {
    private array $obs = [];
    public int $state = 0;
    public function attach(SplObserver $o): void { $this->obs[spl_object_id($o)] = $o; }
    public function detach(SplObserver $o): void { unset($this->obs[spl_object_id($o)]); }
    public function notify(): void { foreach ($this->obs as $o) { $o->update($this); } }
}
class Obs implements SplObserver {
    public function __construct(private string $n) {}
    public function update(SplSubject $s): void { echo $this->n, " got ", $s->state, "\n"; }
}
$s = new Subj; $a = new Obs('a'); $b = new Obs('b');
$s->attach($a); $s->attach($b);
$s->state = 1; $s->notify();
$s->detach($a);
$s->state = 2; $s->notify();
var_dump($s instanceof SplSubject, $a instanceof SplObserver);
