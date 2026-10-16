<?php
// (string) $reflectionParameter prints php's form; symfony's ProxyHelper::exportType() reads the tail after "$name = " for NULL.
namespace N;

class K {}
class S
{
    public function __construct(int $a, ?K $b = null, string|int $c = 'x', array $d = [], $e = [1, 2], float $f = 1.5, bool $g = true, ...$rest) {}
    public function m(&$r, K $k, $s = 'text', int|null $n = null) {}
}

foreach ([new \ReflectionMethod('N\S', '__construct'), new \ReflectionMethod('N\S', 'm')] as $fn) {
    foreach ($fn->getParameters() as $p) {
        echo (string) $p, "\n";
        $isNull = 'NULL' === rtrim(substr(explode('$' . $p->name . ' = ', (string) $p, 2)[1] ?? '', 0, -2));
        echo '  default is NULL: ', var_export($isNull, true), "\n";
    }
}
