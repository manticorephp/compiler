<?php
// `clone` of an object whose array property holds an array under a STRING key
// (stored through a `mixed` param — symfony OptionsResolver::$defaults): the
// cell copy wrote each separated inner array back by POSITION-as-INT-KEY and
// appended forever.
final class R
{
    private array $defaults = [];
    public function setDefault(string $option, mixed $value): static { $this->defaults[$option] = $value; return $this; }
    public function resolve(array $options = []): array
    {
        $clone = clone $this;
        foreach ($options as $option => $value) { $clone->defaults[$option] = $value; }
        $clone->defaults['include'][] = 'added';
        return $clone->defaults;
    }
    public function raw(): array { return $this->defaults; }
}
$r = new R();
$r->setDefault('include', ['internal'])->setDefault('flag', false)->setDefault('list', [1, [2, 3]]);
echo json_encode($r->resolve()), "\n";
echo json_encode($r->resolve(['include' => ['x']])), "\n";
echo json_encode($r->raw()), "\n";
