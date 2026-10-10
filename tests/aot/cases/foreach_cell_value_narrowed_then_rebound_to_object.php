<?php
class D
{
    private array $args = [];

    public function __construct(public $p = null) {}

    public function setArguments(array $a): static
    {
        $this->args = $a;
        return $this;
    }

    public function getArguments(): array
    {
        return $this->args;
    }

    public function g(): string
    {
        return static::class;
    }
}

final class CD extends D {}

function pick(D $def): string
{
    $adapter = new D();
    $providers = $def->getArguments();
    $out = '';
    foreach ($providers[0] as $adapter) {
        if ($adapter instanceof CD) {
            $c = clone $adapter;
        } else {
            $c = $adapter = new CD($adapter);
        }
        $out .= $c->g() . ',';
    }
    return $out . $adapter->g();
}

echo pick((new D())->setArguments([[new D(), new CD(), 'str']])), "\n";
echo pick((new D())->setArguments([[new D(), 'str', new CD()]])), "\n";
echo pick((new D())->setArguments([[]])), "\n";
