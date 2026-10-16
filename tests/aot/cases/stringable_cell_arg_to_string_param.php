<?php
class Ref { public function __construct(private string $id) {} public function __toString(): string { return $this->id; } }
class Def {
    private string|array|null $factory = null;
    public function setFactory(string|array|null $f): static { $this->factory = $f; return $this; }
    public function getFactory(): string|array|null { return $this->factory; }
}
class CB {
    public function getDefinition(string $id): string { return "def:" . $id; }
    public function maybe(?string $id): string { return $id === null ? 'null' : "str:" . $id; }
}
$c = new CB();
$d = (new Def())->setFactory([new Ref('svc1'), '__invoke']);
echo $c->getDefinition($d->getFactory()[0]), "\n";
$e = (new Def())->setFactory(['plain', 7, null]);
echo $c->getDefinition($e->getFactory()[0]), "\n";
echo $c->getDefinition($e->getFactory()[1]), "\n";
echo $c->maybe($e->getFactory()[0]), "\n";
echo $c->maybe($e->getFactory()[2]), "\n";
