<?php
// Reflection Ф2: instance trampolines. newInstance (no ctor args), invoke on an
// instance method (0-arg and with args), a static method, and getConstructor.
// Oracle is the `php` interpreter (difftest).

class Point {
    public function __construct(public int $x = 0, public int $y = 0) {}
    public function sum(): int { return $this->x + $this->y; }
    public function label(string $p): string { return $p . ':' . $this->sum(); }
    public static function origin(): string { return "origin"; }
}

$r = new ReflectionClass('Point');

// newInstance with no constructor arguments (both params default).
$p = $r->newInstance();
echo $p->sum(), "\n";                                  // 0

// invoke a 0-arg instance method through a trampoline.
$q = new Point(3, 4);
echo $r->getMethod('sum')->invoke($q), "\n";           // 7

// invoke with an argument (variadic -> vec[cell], unboxed per param).
echo $r->getMethod('label')->invoke($q, 'pt'), "\n";   // pt:7

// a static method: receiver ignored (php passes null).
echo $r->getMethod('origin')->invoke(null), "\n";      // origin

// getConstructor + its name.
echo $r->getConstructor()->getName(), "\n";            // __construct

// ReflectionMethod flag queries.
var_dump($r->getMethod('sum')->isStatic());            // false
var_dump($r->getMethod('origin')->isStatic());         // true
var_dump($r->getMethod('sum')->isPublic());            // true

// a class with no explicit constructor still constructs.
class Bare { public function greet(): string { return "hi"; } }
$rb = new ReflectionClass('Bare');
echo $rb->newInstance()->greet(), "\n";                // hi
var_dump($rb->getConstructor());                        // NULL

// invokeArgs / newInstanceArgs: a runtime ARRAY of arguments, boxed at the call
// site from its concrete element repr to the vec[cell] the trampoline wants.
class Adder {
    public function add(int $a, int $b): int { return $a + $b; }
    public function join(string $s, string $t): string { return $s . $t; }
}
$ra = new ReflectionClass('Adder');
echo $ra->getMethod('add')->invokeArgs(new Adder, [3, 4]), "\n";        // 7 (vec[int])
echo $ra->getMethod('join')->invokeArgs(new Adder, ['a', 'b']), "\n";   // ab (vec[string])
echo (new ReflectionClass('Point'))->newInstanceArgs([5, 6])->sum(), "\n"; // 11
echo (new ReflectionClass('Point'))->newInstanceArgs([])->sum(), "\n";     // 0
