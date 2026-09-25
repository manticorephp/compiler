<?php

namespace Manticore\Attr;

/**
 * The class mirrors a php class whose objects are UNCOMPARABLE (`CurlHandle`,
 * `DeflateContext`, `Socket`, …): `==` is identity and `<=>` answers 1 for
 * two distinct instances, whatever state this implementation keeps.
 * Inherited, like php's compare handler. See {@see CompareKey}.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Uncomparable
{
}
