<?php
class Col { public function __construct(public array $m = []) {} }
class M {
    protected static $resolved = [];
    protected static $collectionClass = Col::class;
    public function res() { return null; }
    public function nc(array $models = []) {
        static::$resolved[static::class] ??= ($this->res() ?? static::$collectionClass);
        $c = new static::$resolved[static::class]($models);
        return $c;
    }
}
class N extends M {}
$c = (new N)->nc([1, 2]);
echo get_class($c), count($c->m), "\n";
