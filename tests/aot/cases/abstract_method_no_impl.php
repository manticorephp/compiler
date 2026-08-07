<?php

// An abstract class calling its OWN abstract method. `Base` has a compiled
// implementor and dispatches normally; `Orphan` has none, so no instance of it
// can exist and `$this->missing()` is unreachable — php never runs it.
//
// The emitter used to ask only whether SOMETHING declared the name, and an
// abstract declaration answered yes: the call went out direct, to a symbol
// nothing defines and nothing declares, and clang rejected the WHOLE module
// (`use of undefined value @manticore_Orphan__missing`). One unreachable
// method in one unused class killed the build.
//
// symfony/dependency-injection's AbstractKernel::initializeBundles() is this
// shape — every concrete Kernel lives in a package the audit tier excludes.

abstract class Base
{
    public function run(): void { echo "before\n"; $this->step(); echo "after\n"; }
    abstract protected function step(): void;
}

abstract class Orphan
{
    public function go(): void { $this->missing(); }
    abstract protected function missing(): void;
}

final class Impl extends Base
{
    protected function step(): void { echo "step\n"; }
}

(new Impl())->run();
echo "done\n";
