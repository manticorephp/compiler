<?php

namespace Manticore\Attr;

/**
 * The class mirrors a php class with NO declared properties (`HashContext`,
 * `Fiber`): two instances of it are `==` and `<=>` 0 whatever hidden state
 * this implementation keeps in its own private properties. Inherited, like
 * php's compare handler. See {@see CompareKey}.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CompareNone
{
}
