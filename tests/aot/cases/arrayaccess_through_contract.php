<?php
// `$x[$k]` on a value typed by an interface that does not declare ArrayAccess, whose implementer does
interface Contract { public function name(): string; }
class Impl implements Contract, ArrayAccess {
    private array $d = ['k' => 'v'];
    public function name(): string { return 'impl'; }
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->d[$o] ?? null; }
    public function offsetSet(mixed $o, mixed $v): void { $this->d[$o] = $v; }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
}
class Holder {
    /** @var Contract */
    public $c;
    public function __construct(Contract $c) { $this->c = $c; }
    public function run(): void {
        var_dump($this->c['k'], isset($this->c['k']), isset($this->c['zz']));
        $this->c['n'] = 5;
        var_dump($this->c['n']);
        unset($this->c['n']);
        var_dump(isset($this->c['n']));
    }
}
(new Holder(new Impl))->run();
