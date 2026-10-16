<?php
// A static callable array (`['C', 'm']`) held in an erased slot runs the static method.
class Ctx
{
    public function __construct(public string $u) {}
    public static function fromUri(string $uri, string $host = 'localhost'): self { return new self($uri . '@' . $host); }
    public static function log(string $m): void { echo "log: $m\n"; }
    public static function join(string $sep, string ...$parts): string { return implode($sep, $parts); }
}
class SubCtx extends Ctx {}

/** @param mixed[] $arguments */
function make(mixed $factory, array $arguments): mixed
{
    return $factory(...$arguments);
}
function ids(mixed $v): mixed { return $v; }

echo make([Ctx::class, 'fromUri'], ['/a', 'h'])->u, "\n";
echo make(['Ctx', 'fromUri'], ['/b'])->u, "\n";
echo make(['\SubCtx', 'FROMURI'], ['/c', 'x'])->u, "\n";
echo get_class(make([SubCtx::class, 'fromUri'], ['/d'])), "\n";
$f = ids([Ctx::class, 'log']);
var_dump($f('direct'));
echo call_user_func(ids([Ctx::class, 'fromUri']), '/e', 'y')->u, "\n";
echo call_user_func_array(ids([Ctx::class, 'fromUri']), ['/f'])->u, "\n";
echo make([Ctx::class, 'join'], ['-', 'a', 'b', 'c']), "\n";
try {
    make([Ctx::class, 'nope'], []);
} catch (\Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
