<?php

namespace App\Cache\Adapter;

// A trait adaptation's leading name is a TRAIT only when `::` follows it. Bare,
// it names a METHOD -- and it was being resolved against the current namespace
// and `use` aliases like a class name, so inside a namespace
// `doClear as private doClearCache;` recorded its method as
// `App\Cache\Adapter\doClear`. No trait ever matched that, the alias got no
// body, and the call to it was an undefined symbol at link time.
//
// The whole bug is invisible from the global namespace, which is exactly where
// the first attempt at this test lived. symfony/cache's FilesystemTagAwareAdapter
// is the real witness, and it is namespaced.

trait InnerTrait
{
    protected function doClear(string $ns): string { return 'cleared:' . $ns; }
}

trait OuterTrait
{
    use InnerTrait;

    protected function doSave(string $k): string { return 'saved:' . $k; }

    public function prune(): bool { return true; }
}

final class Adapter
{
    use OuterTrait {
        prune as private doPrune;
        doClear as private doClearCache;
        doSave as private doSaveCache;
    }

    public function run(): string
    {
        return $this->doClearCache('ns') . '|' . $this->doSaveCache('k')
             . '|' . ($this->doPrune() ? 'pruned' : 'no')
             . '|' . $this->doClear('orig');
    }
}

// The qualified form `Trait::method as alias` keeps working, and its TRAIT half
// still resolves against the namespace as a class name must.
final class Qualified
{
    use OuterTrait {
        OuterTrait::doSave as public savePublic;
    }

    public function run(): string { return $this->savePublic('q'); }
}

echo (new Adapter())->run(), "\n";
echo (new Qualified())->run(), "\n";
