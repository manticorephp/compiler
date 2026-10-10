<?php
// getDocComment / getStartLine of a class, its methods and its properties.

/**
 * A documented class.
 *
 * @template T
 */
class Doc
{
    /** @var int the counter */
    public int $count = 0;

    public string $plain = '';

    /**
     * Adds one.
     * @return int
     */
    public function bump(): int { return ++$this->count; }

    public function undocumented(): void {}

    /** Static doc. */
    protected static function helper(): void {}
}

class Child extends Doc {}

final class NoDoc
{
    public function m() {}
}

$c = new ReflectionClass(Doc::class);
var_dump($c->getDocComment());
var_dump($c->getStartLine());
var_dump((new ReflectionClass(NoDoc::class))->getDocComment());
var_dump((new ReflectionProperty(Doc::class, 'count'))->getDocComment());
var_dump((new ReflectionProperty(Doc::class, 'plain'))->getDocComment());
var_dump((new ReflectionMethod(Doc::class, 'bump'))->getDocComment());
var_dump((new ReflectionMethod(Doc::class, 'undocumented'))->getDocComment());
var_dump((new ReflectionMethod(Doc::class, 'helper'))->getDocComment());
var_dump((new ReflectionMethod(Doc::class, 'bump'))->getStartLine());
var_dump((new ReflectionMethod(Doc::class, 'undocumented'))->getStartLine());
var_dump((new ReflectionMethod(Child::class, 'bump'))->getDocComment());
var_dump((new ReflectionMethod(Child::class, 'bump'))->getStartLine());
var_dump((new ReflectionClass(Child::class))->getDocComment());
var_dump((new ReflectionClass(Child::class))->getStartLine());
