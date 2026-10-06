<?php
class Base
{
    private int $secret = 1;
    protected string $kept = 'k';
    public float $open = 1.5;

    /** @return array<int, mixed> */
    public function __serialize(): array { return [[], ['flags' => 7, 'els' => [4, 5]], 'named' => true]; }

    /** @param array<int|string, mixed> $data */
    public function __unserialize(array $data): void
    {
        var_dump(array_keys($data), isset($data[1]), $data[1]['flags'], $data['named']);
        $this->secret = (int)$data[1]['flags'];
    }

    public function secret(): int { return $this->secret; }
}
final class Child extends Base
{
    private int $own = 2;
}
var_dump(new Base());
var_dump(new Child());
$c = unserialize(serialize(new Child()));
echo get_class($c), ' ', $c->secret(), "\n";
