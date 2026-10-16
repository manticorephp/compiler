<?php
// An untyped property documented `@var resource|null` that is assigned false crashes the next `$this->os ?? $this->ld` read
// issue: #240
class A
{
    /** @var callable|null */
    protected $ld;
    /** @var resource|null */
    protected $os;

    public function set($o)
    {
        $prev = $this->os ?? $this->ld;
        if (\is_callable($o)) {
            $this->os = null;
            $this->ld = $o;
        } else {
            $this->os = $o;
            $this->ld = $this->m(...);
        }
        return $prev;
    }

    protected function m(string $l): void {}
}
$a = new A();
var_dump($a->set(false));
var_dump($a->set(fopen('php://memory', 'r+')));
var_dump(is_resource($a->set(false)));
