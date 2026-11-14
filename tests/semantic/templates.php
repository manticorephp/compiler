<?php

require __DIR__ . '/bootstrap.php';

use Analyze\Analyzer;
use Analyze\Index;
use Analyze\ParsedFile;
use Analyze\Units;
use Semantic\ExprTyper;
use Semantic\LibrarySymbols;
use Semantic\TypeEnv;
use Semantic\Workspace;

$fail = 0;
function check(string $name, callable $fn): void
{
    global $fail;
    try {
        $r = $fn();
        if ($r === true) { echo "PASS $name\n"; return; }
        echo "FAIL $name: " . (is_string($r) ? $r : 'false') . "\n";
    } catch (\Throwable $e) {
        echo "FAIL $name: " . get_class($e) . ' ' . $e->getMessage() . "\n";
    }
    $fail = 1;
}

function pf(string $path, string $src): ParsedFile
{
    return new ParsedFile($path, \Parser\Parser::parseTolerant($src, $path, true, true, 2)->program);
}

const LIB = <<<'PHP'
<?php
namespace App;
use Other\Q as Z;
class Foo { public function hi(): int { return 1; } }
class Bar extends Foo { public function bye(): int { return 1; } }
interface Marker {}
class Container {
    /**
     * @template T
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object { return new \stdClass; }
    /**
     * @template T of Foo
     * @param class-string<T> $id
     * @return T
     */
    public static function make(string $id): Foo { return new Foo; }
    /**
     * @template T of Foo
     * @param T $x
     * @return array<T>
     */
    public function wrap($x): array { return [$x]; }
    /**
     * @template T
     * @param class-string<T> $id
     * @return list<T>
     */
    public function many(string $id): array { return []; }
    /**
     * @template T
     * @param class-string<T> $id
     * @return T[]
     */
    public function arr(string $id): array { return []; }
    /**
     * @template T
     * @param class-string<T> $id
     * @return ?T
     */
    public function maybe(string $id): ?object { return null; }
    /**
     * @template T
     * @param class-string<T> $id
     * @return iterable<int, T>
     */
    public function iter(string $id): iterable { return []; }
    /**
     * @template T of Foo
     * @return T
     */
    public function bounded(): object { return new Foo; }
    /**
     * @template T
     * @return T
     */
    public function unbound(): object { return new \stdClass; }
    /**
     * @template T
     * @param class-string<T> $id
     * @return Foo
     * @phpstan-return T
     */
    public function pref(string $id): object { return new Foo; }
    /**
     * @template T
     * @param class-string<Foo> $id
     * @psalm-param class-string<T> $id
     * @psalm-return T
     */
    public function psalmPref(string $id): object { return new Foo; }
    /**
     * @param class-string $c
     * @param class-string<Foo> $d
     */
    public function takes(string $c, string $d = ''): void {}
}
/**
 * @template T
 * @param class-string<T> $c
 * @return T
 */
function make(string $c): object { return new \stdClass; }
class Tbl {
    public const MAP = ['a' => 1];
    /** @var array<string, Foo> */
    public const OBJS = [];
    /** @return value-of<self::OBJS> */
    public function v() { return new Foo; }
    /** @return key-of<self::OBJS> */
    public function k() { return ''; }
    /** @return value-of<array{x: int, y: string}> */
    public function sv() { return 1; }
    /** @return key-of<array{x: int, y: string}> */
    public function sk() { return 'x'; }
    /** @return value-of<list<Bar>> */
    public function lv() { return new Bar; }
    /** @return key-of<list<Bar>> */
    public function lk() { return 0; }
    /** @return value-of<Unknown> */
    public function unk() { return 0; }
    /**
     * @template T of array
     * @param T $a
     * @return value-of<T>
     */
    public function fromArg($a) { return 0; }
}
PHP;

/** Type of the last expression statement of $body inside `function f($params)` of namespace App. */
function ty(string $body, string $params = '', string $doc = ''): string
{
    $file = pf('u.php', "<?php\nnamespace App;\nuse Other\\Q as Z;\n$doc\nfunction f($params) {\n$body\n}\n");
    $idx = Index::build([pf('lib.php', LIB), $file]);
    $u = new Units();
    $u->collect($file->program->statements, $file->program->useAliases);
    foreach ($u->units as $unit) {
        if ($unit->label !== 'App\f()') { continue; }
        $last = $unit->stmts[\count($unit->stmts) - 1];
        $t = (new ExprTyper($idx, 'u.php'))->typeOf($last->expr, TypeEnv::forUnit($unit, $idx));
        return $t === null ? 'null' : $t->toString();
    }
    return 'no unit';
}

check('method binds T from Foo::class', fn() => ty('$c->get(Foo::class);', 'Container $c') === 'App\Foo');
check('method binds T from use alias', fn() => ty('$c->get(Z::class);', 'Container $c') === 'Other\Q');
check('class-string<Bar> value binds', fn() => ty('$c->get($k);', 'Container $c, string $k', '/** @param class-string<Bar> $k */') === 'App\Bar');
check('Foo::class is class-string<Foo>', fn() => ty('Foo::class;') === 'class-string<App\Foo>');
check('static call binds with bound', fn() => ty('Container::make(Bar::class);') === 'App\Bar');
check('function binds', fn() => ty('make(Foo::class);') === 'App\Foo');
check('named argument binds', fn() => ty('$c->get(id: Bar::class);', 'Container $c') === 'App\Bar');
check('T-typed argument binds', fn() => ty('$c->wrap(new Bar);', 'Container $c') === 'array<App\Bar>');
check('list<T> substitution', fn() => ty('$c->many(Foo::class);', 'Container $c') === 'list<App\Foo>');
check('T[] substitution', fn() => ty('$c->arr(Foo::class);', 'Container $c') === 'array<App\Foo>');
check('?T substitution', fn() => ty('$c->maybe(Foo::class);', 'Container $c') === '?App\Foo');
check('iterable<K,T> substitution', fn() => ty('$c->iter(Foo::class);', 'Container $c') === 'iterable<int,App\Foo>');
check('unbound T falls back to its bound', fn() => ty('$c->bounded();', 'Container $c') === 'App\Foo');
check('unbound T without bound stays native', fn() => ty('$c->unbound();', 'Container $c') === 'object');
check('non-class-string argument leaves bound', fn() => ty('$c->get($s);', 'Container $c, string $s') === 'object');
check('@phpstan-return beats @return', fn() => ty('$c->pref(Bar::class);', 'Container $c') === 'App\Bar');
check('@psalm-param and @psalm-return beat the plain tags', fn() => ty('$c->psalmPref(Bar::class);', 'Container $c') === 'App\Bar');
check('value-of constant array', fn() => ty('$t->v();', 'Tbl $t') === 'App\Foo');
check('key-of constant array', fn() => ty('$t->k();', 'Tbl $t') === 'string');
check('value-of shape', fn() => ty('$t->sv();', 'Tbl $t') === 'int|string');
check('key-of shape', fn() => ty('$t->sk();', 'Tbl $t') === "'x'|'y'");
check('value-of list', fn() => ty('$t->lv();', 'Tbl $t') === 'App\Bar');
check('key-of list', fn() => ty('$t->lk();', 'Tbl $t') === 'int');
check('value-of unknown stays unknown', fn() => ty('$t->unk();', 'Tbl $t') === 'null');
check('value-of template binds first', fn() => ty('$t->fromArg([1, 2]);', 'Tbl $t') === 'int');

// ── Workspace: hover + completion ───────────────────────────────────

function ws(string $a, string $b): Workspace
{
    $prelude = [];
    foreach (['core_interfaces.php', 'exceptions.php'] as $f) {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/' . $f);
        $prelude[] = new ParsedFile('lib:prelude/' . $f, \Parser\Parser::parseTolerant($src, $f)->program);
    }
    $w = new Workspace(LibrarySymbols::fromParts($prelude, ['strlen']));
    $w->open('lib.php', $a);
    $w->open('use.php', $b);
    return $w;
}

$USE = <<<'PHP'
<?php
namespace App;
function go(Container $c) {
    $x = $c->get(Foo::class);
    $x->hi();
    $c->get(Bar::class)->
}
PHP;

check('hover on variable shows bound class', function () use ($USE): bool|string {
    $w = ws(LIB, $USE);
    $h = $w->hover('use.php', 4, 6);
    return $h !== null && \str_contains($h->text, 'App\Foo') ? true : ($h === null ? 'null' : $h->text);
});
check('typeAt the call is Foo', function () use ($USE): bool|string {
    $w = ws(LIB, $USE);
    $t = $w->typeAt('use.php', 4, 15);
    return $t !== null && $t->toString() === 'App\Foo' ? true : ($t === null ? 'null' : $t->toString());
});
check('completion after ->get(Bar::class)-> lists Bar members', function () use ($USE): bool|string {
    $w = ws(LIB, $USE);
    $labels = \array_map(fn($c) => $c->label, $w->completionsAt('use.php', 6, 26));
    return \in_array('bye', $labels, true) && \in_array('hi', $labels, true) ? true : \implode(',', $labels);
});

// ── diagnostic ──────────────────────────────────────────────────────

function diag(string $body): string
{
    $lib = pf('lib.php', LIB);
    $file = pf('u.php', "<?php\nnamespace App;\nfunction g(Container \$c) {\n$body\n}\n");
    $idx = Index::build([$lib, $file]);
    $out = [];
    foreach ((new Analyzer())->runFile($file, $idx, true) as $d) {
        if ($d->code === 'type.class-string') { $out[] = $d->code; }
    }
    return \implode(',', $out);
}
check('literal naming no class is flagged', fn() => diag('$c->takes("Nope\\\\Missing");') === 'type.class-string');
check('literal naming a known class is fine', fn() => diag('$c->takes("App\\\\Foo");') === '');
check('dynamic string is not flagged', fn() => diag('$s = "x"; $c->takes($s);') === '');
check('templated class-string parameter is flagged too', fn() => diag('$c->get("Nope");') === 'type.class-string');
check('plain string parameter is not flagged', fn() => diag('\\strlen("Nope");') === '');

exit($fail);
