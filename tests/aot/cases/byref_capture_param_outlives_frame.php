<?php
// A closure capturing a by-value PARAMETER by reference keeps it alive after the function returns.
function arr(array $a): \Closure { return static function () use (&$a): string { return implode(',', $a) . count($a); }; }
function str(string $s): \Closure { return static function () use (&$s): string { $s .= '!'; return $s; }; }
function packed(string ...$parts): \Closure { return static function () use (&$parts): string { return implode('+', $parts); }; }
function once(array $a): \Closure
{
    return static function () use (&$a): string {
        if ($a === null) { return 'gone'; }
        $r = implode(',', $a);
        $a = null;
        return $r;
    };
}
final class Lazy
{
    private \Closure $value;
    public static function of(callable|array $callback, mixed ...$arguments): static
    {
        $l = new static();
        $l->value = static function () use (&$callback, &$arguments): string {
            static $value;
            if (null !== $arguments) {
                $value = $callback(...$arguments);
                $callback = 'callable';
                $arguments = null;
            }
            return $value ?? '';
        };
        return $l;
    }
    public function get(): string { return ($this->value)(); }
}

echo arr([str_repeat('a', 2), 'b'])(), "\n";
$f = str(str_repeat('x', 3));
echo $f(), ' ', $f(), "\n";
echo packed('p', str_repeat('q', 2))(), "\n";
$g = once([str_repeat('m', 2), 'n']);
echo $g(), ' ', $g(), "\n";
$l = Lazy::of(fn (string $a, string $b): string => $a . '-' . $b, str_repeat('L', 2), 'R');
echo $l->get(), ' ', $l->get(), "\n";
