<?php
// A class declared inside a FOLDABLE `if` was registered nowhere.
// flattenConstantIfs hoists the live branch to the top level, but that runs
// after both class-registration passes, and only FUNCTIONS were registered on
// the way through — so lowerClassMethods read a classTable key that was never
// added and handed the null to a ClassDef-typed parameter. A warning plus a
// TypeError under Zend; a SIGSEGV once self-built, several frames from the
// cause, presenting as a bare rc=139 out of a whole-program build.
//
// The shape is the ordinary way a package ships one class two ways —
// symfony/cache guards four exception classes exactly like this:
//
//     if (interface_exists(SimpleCacheInterface::class)) {
//         class CacheException extends \Exception implements A, B {}
//     } else {
//         class CacheException extends \Exception implements A {}
//     }

interface Marker {}

if (interface_exists('Totally\Missing\Iface')) {
    class Guarded extends \LogicException implements Marker
    {
        public function which(): string { return 'then-arm'; }
    }
} else {
    class Guarded extends \LogicException implements Marker
    {
        public function which(): string { return 'else-arm'; }
    }
}

$g = new Guarded('boom');
echo get_class($g), "\n";
echo $g->which(), "\n";
echo $g->getMessage(), "\n";
var_dump($g instanceof \LogicException);
var_dump($g instanceof Marker);

// The TRUE arm is taken when the guard holds, and the class is a first-class
// one: properties, inheritance and a constructor all work. The guard names an
// ABSENT class on purpose — `class_exists` of a name this build DOES have is
// deliberately left unfolded, so only an absent name decides a branch.
if (!class_exists('Totally\Absent\Thing')) {
    class AlsoHoisted
    {
        public string $tag = 'default';

        public function __construct(string $tag = 'ctor')
        {
            $this->tag = $tag;
        }

        public function tag(): string { return $this->tag; }
    }
}

$a = new AlsoHoisted();
echo $a->tag(), "\n";
$b = new AlsoHoisted('given');
echo $b->tag(), "\n";

// A hoisted class may extend another hoisted one — every declaration is
// registered before any definition is built.
if (!interface_exists('Another\Missing')) {
    class HoistedBase
    {
        public function name(): string { return 'base'; }
    }
}
if (!interface_exists('Another\Missing')) {
    class HoistedChild extends HoistedBase
    {
        public function name(): string { return 'child of ' . parent::name(); }
    }
}

echo (new HoistedChild())->name(), "\n";

// A hoisted FUNCTION still works — the case that was already handled.
if (!function_exists('hoisted_fn')) {
    function hoisted_fn(): string { return 'fn ok'; }
}
echo hoisted_fn(), "\n";
