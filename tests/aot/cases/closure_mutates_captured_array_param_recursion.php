<?php
// A closure that mutates its by-value captured array (use ($resolving)) and recurses into the method that created it traps (symfony ParameterBag::resolveString)
final class Bag
{
    /** @var array<string, mixed> */
    private array $p = ['a' => 'A-%b%', 'b' => 'B-%c%', 'c' => 'C', 'n' => 5, 'loop' => '%loop%'];

    public function get(string $k): mixed
    {
        if (!array_key_exists($k, $this->p)) { throw new RuntimeException("missing $k"); }
        return $this->p[$k];
    }

    public function resolveString(string $value, array $resolving = []): mixed
    {
        if (preg_match('/^%([^%\s]+)%$/', $value, $match)) {
            $key = $match[1];
            if (isset($resolving[$key])) { throw new LogicException('circular ' . implode(',', array_keys($resolving))); }
            $resolving[$key] = true;
            return $this->resolveString((string) $this->get($key), $resolving);
        }

        return preg_replace_callback('/%%|%([^%\s]+)%/', function ($match) use ($resolving, $value) {
            if (!isset($match[1])) { return '%%'; }
            $key = $match[1];
            if (isset($resolving[$key])) { throw new LogicException('circular ' . implode(',', array_keys($resolving))); }
            $resolved = $this->get($key);
            if (!is_string($resolved) && !is_numeric($resolved)) { throw new RuntimeException('bad ' . $key); }
            $resolved = (string) $resolved;
            $resolving[$key] = true;

            return $this->resolveString($resolved, $resolving);
        }, $value);
    }
}

$b = new Bag();
foreach (['x %a% y', '%a%', 'p %a% q %c% r %n% s %%', 'plain', 'x %loop% y'] as $v) {
    try { echo $v, ' => ', $b->resolveString($v), "\n"; }
    catch (Throwable $e) { echo $v, ' => ', get_class($e), ': ', $e->getMessage(), "\n"; }
}
for ($i = 0; $i < 2000; $i++) { $b->resolveString('p %a% q %c% r %n% s %%'); }
echo "done\n";
