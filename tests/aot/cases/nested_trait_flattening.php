<?php

// A trait may `use` another, and php flattens the whole chain into the using
// class: methods, properties and static properties all arrive as if the class
// had listed them itself. Walking only the class's own `use` list dropped the
// nested level everywhere.
//
// The METHOD half is loud -- an undefined symbol, which is what stopped tier 2
// on symfony/cache's FilesystemTagAwareAdapter (uses FilesystemTrait, which uses
// FilesystemCommonTrait, where `doClear` actually lives). The PROPERTY half is
// the quiet one: a nested trait's field got no slot, so reading it inside a
// trait method lands on a wrong offset.

trait InnerTrait
{
    public string $innerProp = 'inner-default';
    public static int $innerCount = 7;

    protected function doClear(string $ns): string { return 'cleared:' . $ns; }

    public function readInner(): string { return $this->innerProp; }

    public function shared(): string { return 'inner-loses'; }
}

trait MiddleTrait
{
    use InnerTrait;

    public int $middleProp = 42;

    protected function doSave(string $k): string { return 'saved:' . $k; }

    // A trait's OWN member overrides the one it mixes in, so this wins over
    // InnerTrait::shared and only ONE definition may reach the class.
    // symfony/cache's ContractsTrait does exactly this to CacheTrait's `doGet`;
    // emitting both put two definitions of one symbol in the module.
    public function shared(): string { return 'middle-wins'; }
}

final class Adapter
{
    use MiddleTrait {
        doClear as private doClearCache;
        doSave as private doSaveCache;
    }

    public string $own = 'own-value';

    public function run(): string
    {
        // The alias of a NESTED trait's method, and the original name too.
        return $this->doClearCache('ns') . '|' . $this->doSaveCache('k')
             . '|' . $this->doClear('direct') . '|' . $this->doSave('d2')
             . '|' . $this->shared();
    }
}

$a = new Adapter();
echo $a->run(), "\n";

// Properties from both levels get their own slot, and the class's own field is
// not displaced by them.
echo $a->innerProp, '|', $a->middleProp, '|', $a->own, "\n";
$a->innerProp = 'written';
$a->middleProp = 43;
echo $a->innerProp, '|', $a->middleProp, '|', $a->own, "\n";
echo $a->readInner(), "\n";

// The nested trait's STATIC property gets a slot on the using class.
echo Adapter::$innerCount, "\n";
Adapter::$innerCount = 9;
echo Adapter::$innerCount, "\n";

var_dump($a);
