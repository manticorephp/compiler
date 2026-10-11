<?php
// `$this->arr += $other` followed by `$this->arr[$k] += $v` on a property array segfaults (symfony's EnvPlaceholderParameterBag::mergeEnvPlaceholders)
class Bag
{
    private array $m = [];

    public function add(string $env, string $p): void
    {
        $this->m[$env][$p] = $p;
    }

    public function all(): array
    {
        return $this->m;
    }

    public function merge(self $bag): void
    {
        if ($new = $bag->all()) {
            $this->m += $new;

            foreach ($new as $env => $placeholders) {
                $this->m[$env] += $placeholders;
            }
        }
    }
}

$a = new Bag();
$a->add('FOO', 'p1');
$b = new Bag();
$b->add('FOO', 'p2');
$b->add('BAZ', 'p3');
$a->merge($b);
echo json_encode($a->all()), "\n";

function f(mixed $a, mixed $b) { return $a + $b; }
echo json_encode(f(['a' => 1], ['a' => 9, 'b' => 2])), json_encode(f([1, 2], [5, 6, 7])), json_encode(f(1, 2)), json_encode(f(1.5, 2)), "\n";
