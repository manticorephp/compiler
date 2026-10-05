<?php
// Locals read only inside an array literal yielded by a generator: DeadStore
// did not walk `yield`, dropped the stores, the consumer saw null for $e.
final class T
{
    /** @var string[] */
    public array $a = ['int', '|', '\Foo', '|', 'null', ';'];

    public function next(int $i): int { return $i + 1; }

    public function over(int $s, int $e, string $r): void
    {
        array_splice($this->a, $s, $e - $s + 1, [$r]);
    }
}

/** @return iterable<int, array{int, int}> */
function types(T $t, int $index, int $endIndex): iterable
{
    $skip = false;
    $ts = $te = null;
    while (true) {
        if ($t->a[$index] === '|' || $index > $endIndex) {
            if (!$skip && null !== $ts) {
                $orig = count($t->a);
                yield [$ts, $te];
                $endIndex += count($t->a) - $orig;
                $skip = true;
                $index = $te = $ts;
            } else {
                $skip = false;
                $index = $t->next($index);
                $ts = $te = null;
            }
            if ($index > $endIndex) {
                break;
            }
            continue;
        }
        if (null === $ts) {
            $ts = $index;
        }
        $te = $index;
        $index = $t->next($index);
    }
}

$t = new T();
foreach (types($t, 0, 4) as [$s, $e]) {
    echo "$s $e\n";
    if ($t->a[$s] === '\Foo') {
        $t->over($s, $e, 'Foo');
    }
}
echo implode('', $t->a), "\n";
