<?php
// getMethods/getProperties build members from the row they already hold; the doc table
// is built once per class. Output must stay what php prints.
interface Shape { public function area(): float; }

/** Base docs. */
abstract class Base implements Shape {
    /** The id. */
    protected int $id = 1;
    public static int $count = 0;
    private string $secret = 's';
    /** Base method doc. */
    public function id(): int { return $this->id; }
    abstract public function area(): float;
    protected static function helper(): void {}
    private function hidden(): int { return 1; }
}

/** Square docs. */
final class Square extends Base {
    /** Side doc. */
    public float $side = 2.0;
    public ?string $label = null;
    /** Area doc. */
    public function area(): float { return $this->side * $this->side; }
    public function label(): ?string { return $this->label; }
    public static function make(): static { return new static(); }
}

function dump(array $ms): string {
    $o = [];
    foreach ($ms as $m) {
        $o[] = $m->class . '::' . $m->name . ':' . ($m->isStatic() ? 's' : '') . ($m->isPublic() ? 'u' : '')
            . ($m->isProtected() ? 'o' : '') . ($m->isPrivate() ? 'v' : '') . ($m->isAbstract() ? 'a' : '') . ($m->isFinal() ? 'f' : '');
    }
    sort($o);
    return implode(' ', $o);
}

$rc = new ReflectionClass('Square');
echo dump($rc->getMethods()), "\n";
echo dump($rc->getMethods(ReflectionMethod::IS_PUBLIC)), "\n";
echo dump($rc->getMethods(ReflectionMethod::IS_STATIC)), "\n";
$p = [];
foreach ($rc->getProperties() as $x) {
    $p[] = $x->class . '::' . $x->name . ':' . ($x->isStatic() ? 's' : '') . ($x->isPublic() ? 'u' : ($x->isProtected() ? 'o' : 'v'));
}
sort($p);
echo implode(' ', $p), "\n";

// the same doc comments, asked repeatedly (cached after the first)
for ($i = 0; $i < 3; $i++) {
    echo var_export($rc->getDocComment(), true), ' ',
        var_export((new ReflectionMethod('Square', 'area'))->getDocComment(), true), ' ',
        var_export((new ReflectionMethod('Square', 'id'))->getDocComment(), true), ' ',
        var_export((new ReflectionMethod('Square', 'label'))->getDocComment(), true), ' ',
        var_export((new ReflectionProperty('Square', 'side'))->getDocComment(), true), ' ',
        var_export((new ReflectionProperty('Square', 'id'))->getDocComment(), true), "\n";
}
// a member made by name still works after members made from rows
$m = new ReflectionMethod('Square', 'make');
echo $m->isStatic() ? 'static ' : 'inst ', $m->class, ' ', (new ReflectionProperty('Square', 'id'))->class, "\n";
try { new ReflectionMethod('Square', 'nope'); } catch (ReflectionException $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { new ReflectionProperty('Square', 'nope'); } catch (ReflectionException $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { new ReflectionMethod('NoSuchClass', 'x'); } catch (ReflectionException $e) { echo $e->getMessage(), "\n"; }
// a parent's private member is not listed on the child, but is on the parent
echo count((new ReflectionClass('Base'))->getMethods()) - count((new ReflectionClass('Square'))->getMethods()), ' ', count((new ReflectionClass('Base'))->getProperties()) - count((new ReflectionClass('Square'))->getProperties()), "\n";
echo (new ReflectionMethod('Square', 'area'))->isFinal() ? 'f' : 'nf', "\n";

// members fetched by name through the class
$sq = new ReflectionClass('Square');
echo $sq->getMethod('area')->class, ' ', $sq->getMethod('id')->class, ' ', $sq->getProperty('side')->class, ' ', $sq->getProperty('id')->class, "\n";
echo $sq->getConstructor() === null ? 'no ctor' : 'ctor', ' ', $sq->getParentClass()->getName(), ' ', $sq->getParentClass()->getParentClass() === false ? 'root' : 'deeper', "\n";
try { $sq->getMethod('nope'); } catch (ReflectionException $e) { echo $e->getMessage(), "\n"; }
try { $sq->getProperty('nope'); } catch (ReflectionException $e) { echo $e->getMessage(), "\n"; }
echo (new ReflectionClass(new Square))->getShortName(), ' ', (new ReflectionClass(Closure::fromCallable('strlen')))->getName(), "\n";
