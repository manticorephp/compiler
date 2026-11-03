<?php
abstract class Uid {
    public static function fromString(string $s): static { return new static($s); }
    final public function __construct(protected string $v) {}
    public function toRfc4122(): string { return 'rfc:' . $this->v; }
    public function toBinary(): string { return 'bin:' . $this->v; }
}
final class U1 extends Uid {}
class Conv {
    protected function cls(): string { return U1::class; }
    public function run(string $v, bool $native): ?string {
        $to = $native ? 'toRfc4122' : 'toBinary';
        return $this->cls()::fromString($v)->$to();
    }
}
$c = new Conv();
echo $c->run('a', true), ' ', $c->run('b', false), "\n";
