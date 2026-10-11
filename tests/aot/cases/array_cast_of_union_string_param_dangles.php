<?php
// (array) of a string|array parameter stores the argument without retaining it: a fresh string argument is freed and reused
class L
{
    protected array $paths;
    public function __construct(string|array $p = [])
    {
        $this->paths = (array) $p;
    }
    public function first(): string { return $this->paths[0]; }
}

function mk(string|array $p = []): L { return new L($p); }

$base = '/tmp/some/dir/' . strlen('abc');
$l = mk(\dirname($base . '/x'));
$noise = [];
for ($i = 0; $i < 5; $i++) { $noise[] = str_repeat('n', 12) . $i; }
echo $l->first(), "\n";
