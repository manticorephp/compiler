<?php
// ReflectionMethod::getEndLine names the line of the closing brace: symfony/demo's "Show code" cut the controller to one line.
abstract class A
{
    public function one(): int
    {
        $x = 1;

        return $x;
    }

    abstract protected function abs(): void;

    public static function inline(): int { return 2; }
}
foreach (['one', 'abs', 'inline'] as $m) {
    $r = new ReflectionMethod(A::class, $m);
    echo $m, ' ', $r->getStartLine(), '-', $r->getEndLine(), "\n";
}
