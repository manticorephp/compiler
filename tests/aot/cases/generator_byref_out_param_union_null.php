<?php
// by-ref out param assigned lazily by a generator method segfaults in caller
final class Res implements IteratorAggregate
{
    public function __construct(private string $prefix) {}
    public function getPrefix(): string { return $this->prefix; }
    public function getIterator(): Generator { yield '/a.php' => 1; yield '/b.php' => 2; }
}

final class Loader
{
    protected function glob(string $pattern, array|Res|null &$resource = null, bool $ignoreErrors = false): iterable
    {
        if ($pattern === 'missing') {
            $resource = [];
            return;
        }
        $resource = new Res($pattern);
        yield from $resource;
    }

    /** @return string[] */
    public function find(string $pattern): array
    {
        $out = [];
        foreach ($this->glob($pattern, $resource) as $path => $info) {
            $out[] = $resource->getPrefix() . $path;
        }
        return $out;
    }
}

echo implode(',', (new Loader())->find('src')), "\n";
