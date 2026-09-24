<?php

// An undeclared property holding an array, read by getters of DIFFERENT
// return types. The getters used to type the property themselves.
#[\AllowDynamicProperties]
class WithAttr
{
    public function fill(): void { $this->data = ['visible' => true, 'cmd' => ['line' => 3], 'n' => [2, 15]]; }
    public function visible(): bool { return $this->data['visible']; }
    public function cmd(): array { if (\is_array($this->data['cmd'])) { return $this->data['cmd']; } return []; }
    public function sig(): array { return array_map(static fn (int $s): string => "s$s", $this->data['n']); }
}

class Plain
{
    public function fill(): void { $this->data = ['visible' => false, 'cmd' => ['line' => 9]]; }
    public function visible(): bool { return $this->data['visible']; }
    public function cmd(): array { return $this->data['cmd']; }
}

class Declared
{
    /** getters disagree on the element type, so it is mixed */
    private array $data = [];
    public function fill(): void { $this->data = ['on' => true, 'list' => [1, 2]]; }
    public function on(): bool { return $this->data['on']; }
    public function list(): array { return $this->data['list']; }
}

$a = new WithAttr(); $a->fill();
var_dump($a->visible(), $a->cmd(), $a->sig());
$p = new Plain(); $p->fill();
var_dump($p->visible(), $p->cmd());
$d = new Declared(); $d->fill();
var_dump($d->on(), $d->list());
