<?php
// ReflectionClass members derivable from the class table: instance / cloneable / modifiers / interfaces / static props.
interface Shape {}
interface Named {}
abstract class Base implements Shape { public static int $count = 3; protected static string $tag = 'b'; }
final class Circle extends Base implements Named, Countable, IteratorAggregate
{
    public static array $list = [1, 2];
    public function count(): int { return 0; }
    public function getIterator(): Iterator { return new ArrayIterator([]); }
}
class Locked { private function __clone() {} }
trait Greets { public function hi() {} }
enum Suit { case Hearts; }

$c = new ReflectionClass(Circle::class);
var_dump($c->isInstance(new Circle()), $c->isInstance(new stdClass()));
var_dump($c->isIterable(), $c->isIterateable(), (new ReflectionClass(Base::class))->isIterable());
var_dump($c->isCloneable(), (new ReflectionClass(Locked::class))->isCloneable(), (new ReflectionClass(Base::class))->isCloneable());
var_dump((new ReflectionClass(Shape::class))->isCloneable(), (new ReflectionClass(Suit::class))->isCloneable());
var_dump($c->isAnonymous(), (new ReflectionClass(new class {}))->isAnonymous());
var_dump($c->getModifiers(), (new ReflectionClass(Base::class))->getModifiers(), (new ReflectionClass(Locked::class))->getModifiers());
$ik = array_keys($c->getInterfaces()); sort($ik); var_dump($ik);
var_dump($c->getInterfaces()['Named'] instanceof ReflectionClass);
var_dump((new ReflectionClass(Greets::class))->isTrait(), $c->isTrait());
var_dump($c->getExtension(), $c->getExtensionName());
var_dump($c->getStaticProperties());
var_dump((new ReflectionClass(Base::class))->getStaticProperties());
var_dump($c->getStaticPropertyValue('list'), (new ReflectionClass(Base::class))->getStaticPropertyValue('count'));
(new ReflectionClass(Base::class))->setStaticPropertyValue('count', 9);
var_dump(Base::$count);
var_dump($c->getStaticPropertyValue('nope', 'dflt'));
try { $c->getStaticPropertyValue('nope'); } catch (ReflectionException $e) { echo $e->getMessage(), "\n"; }
