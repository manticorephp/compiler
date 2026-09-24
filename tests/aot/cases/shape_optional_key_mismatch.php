<?php

// Two declared shapes that disagree on an OPTIONAL key's name (a docblock
// mistake, as php-cs-fixer ships): a compile-time warning, never a refusal.
final class Imports
{
    /**
     * @var array{
     *     constant?: array<string, string>,
     *     class?: array<string, string>,
     * }
     */
    private array $symbols = [];

    public function add(string $kind, string $short, string $fqcn): void
    {
        $this->symbols[$kind][$short] = $fqcn;
    }

    public function flush(): string
    {
        return self::render($this->symbols);
    }

    /**
     * @param array{
     *     const?: array<string, string>,
     *     class?: array<string, string>,
     * } $imports
     */
    private static function render(array $imports): string
    {
        $out = [];
        foreach ($imports as $kind => $names) {
            foreach ($names as $short => $fqcn) { $out[] = $kind . ':' . $short . '=' . $fqcn; }
        }
        return implode(',', $out);
    }
}

$i = new Imports();
$i->add('class', 'Foo', 'App\\Foo');
$i->add('const', 'BAR', 'App\\BAR');
echo $i->flush(), "\n";
