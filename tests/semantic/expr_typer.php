<?php

require __DIR__ . '/bootstrap.php';

use Analyze\AstWalk;
use Analyze\Index;
use Analyze\ParsedFile;
use Analyze\Scope;
use Analyze\Ty;
use Analyze\Units;
use Semantic\ExprTyper;
use Semantic\SourceType;
use Semantic\TypeEnv;

function pf(string $path, string $src): ParsedFile
{
    return new ParsedFile($path, \Parser\Parser::parseTolerant($src, $path, true, true, 2)->program);
}

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

$LIB = "<?php\nnamespace N;\n"
    . "enum Suit: string { case Hearts = 'h'; }\n"
    . "interface Shape { public function area(): float; }\n"
    . "trait Fluent { public function me(): self { return \$this; } }\n"
    . "class Base { public function make(): static { return new static; } public function up(): ?Base { return null; } }\n"
    . "class Foo extends Base {\n"
    . "    use Fluent;\n"
    . "    public const NUM = 7;\n    public const TXT = 'a';\n    public const HINT = [1]; public const string TYPED = 'x';\n"
    . "    public Bar \$bar;\n    public static int \$count = 0;\n"
    . "    /** @var \\N\\Foo[] */ public array \$list = [];\n"
    . "    public function __construct(public string \$name = '') {}\n"
    . "    public function bar(): Bar { return new Bar; }\n"
    . "    public function self(): self { return \$this; }\n"
    . "    public function par(): parent { return new Base; }\n"
    . "    public function nul(): ?Bar { return null; }\n"
    . "    /** @return list<\\N\\Bar> */ public function bars(): array { return []; }\n"
    . "    public function noType() { return 1; }\n"
    . "    public static function create(): static { return new static; }\n"
    . "    public function __invoke(): int { return 1; }\n"
    . "}\n"
    . "final class Bar { public function id(): int { return 1; } public ?Foo \$foo = null; }\n"
    . "function helper(int \$a): string { return ''; }\n"
    . "/** @return \\N\\Foo|\\N\\Bar */ function dual() { return new Foo; }\n"
    . "function hasUnion(): Foo|Bar { return new Foo; }\n";
$idx = Index::build([pf('lib.php', $LIB)]);
$typer = new ExprTyper($idx, 'u.php');

/** Type of the last expression statement of $code, inside a method of N\Foo (or a function) with $params. */
function typeIn(ExprTyper $typer, Index $idx, string $code, string $params = '', string $where = 'class'): ?string
{
    $src = $where === 'class'
        ? "<?php\nnamespace N;\nclass Foo2 extends Foo {\n  /** @param \\N\\Foo[] \$arr */ public function m($params) {\n$code\n  }\n  public static function sm() {\n$code\n  }\n}\n"
        : "<?php\nnamespace N;\nfunction f($params) {\n$code\n}\n";
    $file = pf('u.php', $src);
    $idx2 = Index::build([pf('lib.php', $GLOBALS['LIB']), $file]);
    $u = new Units();
    $u->collect($file->program->statements);
    foreach ($u->units as $unit) {
        if ($where === 'class' && $unit->label !== 'N\Foo2::m()') { continue; }
        if ($where !== 'class' && $unit->label !== 'N\f()') { continue; }
        $stmts = $unit->stmts;
        $last = $stmts[\count($stmts) - 1];
        $t = (new ExprTyper($idx2, 'u.php'))->typeOf($last->expr, TypeEnv::forUnit($unit, $idx2));
        return $t === null ? 'null' : $t->toString();
    }
    return 'no unit';
}
$t = fn(string $code, string $params = '', string $where = 'class') => typeIn($typer, $idx, $code, $params, $where);

check('int literal', fn() => $t('1;') === 'int');
check('float literal', fn() => $t('1.5;') === 'float');
check('string literal', fn() => $t('"s";') === 'string');
check('bool literal', fn() => $t('true;') === 'bool');
check('null literal', fn() => $t('null;') === 'null');
check('magic const', fn() => $t('__CLASS__;') === 'string' && $t('__LINE__;') === 'int');
check('param variable', fn() => $t('$a;', 'int $a') === 'int');
check('nullable param', fn() => $t('$a;', '?Foo $a') === '?N\Foo');
check('untyped param unknown', fn() => $t('$a;', '$a') === 'null');
check('variadic param is array', fn() => $t('$a;', 'int ...$a') === 'array');
check('doc @param refines array', fn() => $t('$arr;', 'array $arr') === 'array<\N\Foo>');
check('promoted ctor property', fn() => $t('(new Foo)->name;') === 'string');
check('$this', fn() => $t('$this;') === 'N\Foo2');
check('$this in static method unknown', function () use ($LIB) {
    $file = pf('u.php', "<?php\nnamespace N;\nclass S extends Foo { public static function sm() { \$this; self::create(); } }\n");
    $idx = Index::build([pf('lib.php', $LIB), $file]);
    $u = new Units(); $u->collect($file->program->statements);
    foreach ($u->units as $unit) {
        if ($unit->label !== 'N\S::sm()') { continue; }
        $env = TypeEnv::forUnit($unit, $idx);
        return $env->isStatic && !isset($env->vars['this']) && $env->thisClass === 'N\S' && $env->parentClass === 'N\Foo';
    }
    return 'no unit';
});
check('new', fn() => $t('new Foo;') === 'N\Foo');
check('new self/static/parent', fn() => $t('new self;') === 'N\Foo2' && $t('new static;') === 'N\Foo2' && $t('new parent;') === 'N\Foo');
check('new self in function unknown', fn() => $t('new self;', '', 'fn') === 'null');
check('property', fn() => $t('(new Foo)->bar;') === 'N\Bar');
check('property doc @var', fn() => $t('(new Foo)->list;') === 'array<\N\Foo>');
check('nullsafe property', fn() => $t('$x?->bar;', '?Foo $x') === '?N\Bar');
check('nullsafe method', fn() => $t('$x?->bar();', '?Foo $x') === '?N\Bar');
check('plain method', fn() => $t('$x->bar();', 'Foo $x') === 'N\Bar');
check('method on nullable strips null', fn() => $t('$x->bar();', '?Foo $x') === 'N\Bar');
check('inherited method', fn() => $t('$x->up();', 'Foo $x') === '?N\Base');
check('method @return doc', fn() => $t('$x->bars();', 'Foo $x') === 'list<\N\Bar>');
check('static return is receiver', fn() => $t('$x->make();', 'Foo $x') === 'N\Foo' && $t('$x->make();', 'Bar|Foo $x') === 'null');
check('static return via subclass receiver', fn() => $t('$x->make();', 'Foo2 $x') === 'N\Foo2');
check('self return', fn() => $t('$x->self();', 'Foo $x') === 'N\Foo');
check('trait self is using class', fn() => $t('$x->me();', 'Foo $x') === 'N\Foo');
check('parent return', fn() => $t('$x->par();', 'Foo $x') === 'N\Base');
check('union receiver', fn() => $t('$x->id();', 'Bar $x') === 'int');
check('union receiver all resolve', fn() => $t('$x->bar();', 'Foo|Foo2 $x') === 'N\Bar');
check('union receiver one fails', fn() => $t('$x->id();', 'Foo|Bar $x') === 'null');
check('unknown receiver', fn() => $t('$x->bar();', '$x') === 'null' && $t('$x->bar();', 'int $x') === 'null');
check('unknown method', fn() => $t('$x->nope();', 'Foo $x') === 'null');
check('method without type', fn() => $t('$x->noType();', 'Foo $x') === 'null');
check('static call', fn() => $t('Foo::create();') === 'N\Foo');
check('static call self/parent keep late static', fn() => $t('self::create();') === 'N\Foo2' && $t('parent::create();') === 'N\Foo2');
check('enum from', fn() => $t('Suit::from("h");') === 'N\Suit');
check('static property', fn() => $t('Foo::$count;') === 'int');
check('function call', fn() => $t('\N\helper(1);') === 'string');
check('function @return doc', fn() => $t('\N\dual();') === '\N\Foo|\N\Bar');
check('function union return', fn() => $t('\N\hasUnion();') === 'N\Foo|N\Bar');
check('invoke object', fn() => $t('$x();', 'Foo $x') === 'int');
check('const int by value', fn() => $t('Foo::NUM;') === 'int');
check('const string by value', fn() => $t('Foo::TXT;') === 'string');
check('const hinted', fn() => $t('Foo::TYPED;') === 'string');
check('inherited const', fn() => $t('Foo2::NUM;') === 'int');
check('enum case', fn() => $t('Suit::Hearts;') === 'N\Suit');
check('::class', fn() => $t('Foo::class;') === 'class-string');
check('ternary union', fn() => $t('$c ? 1 : "s";', 'bool $c') === 'int|string');
check('ternary same', fn() => $t('$c ? 1 : 2;', 'bool $c') === 'int');
check('ternary unknown arm', fn() => $t('$c ? 1 : $u;', 'bool $c, $u') === 'null');
check('short ternary', fn() => $t('$n ?: "d";', '?int $n') === 'int|string');
check('coalesce', fn() => $t('$n ?? "d";', '?int $n') === 'int|string');
check('coalesce non-null left', fn() => $t('$n ?? "d";', 'int $n') === 'int');
check('coalesce nullable right', fn() => $t('$n ?? $m;', '?int $n, ?string $m') === 'int|string|null');
check('match', fn() => $t('match($a) { 1 => "a", 2 => 5, default => 1.5 };', 'int $a') === 'string|int|float');
check('match unknown arm', fn() => $t('match($a) { 1 => "a", default => $z };', 'int $a, $z') === 'null');
check('match throw arm ignored', fn() => $t('match($a) { 1 => "a", default => throw new \Exception };', 'int $a') === 'string');
check('clone', fn() => $t('clone $x;', 'Foo $x') === 'N\Foo');
check('closure', fn() => $t('$f = function () {};') === 'Closure' && $t('fn() => 1;') === 'Closure');
check('casts', fn() => $t('(int)$a;', '$a') === 'int' && $t('(string)$a;', '$a') === 'string' && $t('(array)$a;', '$a') === 'array' && $t('(bool)$a;', '$a') === 'bool' && $t('(object)$a;', '$a') === 'stdClass');
check('arith', fn() => $t('$a + $b;', 'int $a, int $b') === 'int' && $t('$a + $b;', 'int $a, float $b') === 'float' && $t('$a . $b;', '$a, $b') === 'string');
check('division', fn() => $t('$a / $b;', 'int $a, int $b') === 'int|float' && $t('$a / $b;', 'int $a, float $b') === 'float');
check('array union operator', fn() => $t('$a + $b;', 'array $a, array $b') === 'array');
check('unknown arith', fn() => $t('$a + $b;', '$a, int $b') === 'null');
check('comparison/logic', fn() => $t('$a < $b;', '$a, $b') === 'bool' && $t('!$a;', '$a') === 'bool' && $t('$a && $b;', '$a, $b') === 'bool');
check('spaceship/modulo', fn() => $t('$a <=> $b;', '$a, $b') === 'int' && $t('$a % $b;', '$a, $b') === 'int');
check('unary minus', fn() => $t('-$a;', 'float $a') === 'float' && $t('-$a;', '$a') === 'null');
check('assign expr', fn() => $t('$x = 5;') === 'int' && $t('$x = $a->bar();', 'Foo $a') === 'N\Bar');
check('compound assign', fn() => $t('$s .= "x";', 'string $s') === 'string' && $t('$n += 1;', 'int $n') === 'int' && $t('$n ??= "d";', '?int $n') === 'int|string');
check('incdec', fn() => $t('$i++;', 'int $i') === 'int' && $t('$i++;', '$i') === 'null');
check('instanceof', fn() => $t('$a instanceof Foo;', '$a') === 'bool');
check('isset/empty', fn() => $t('isset($a);', '$a') === 'bool' && $t('empty($a);', '$a') === 'bool');
check('array literal plain', fn() => $t('[];') === 'array');
check('array literal list', fn() => $t('[1, 2];') === 'list<int>' && $t('[1, "a"];') === 'list<int|string>');
check('array literal keyed', fn() => $t('["a" => 1, "b" => 2.5];') === 'array<string,int|float>');
check('array literal unknown elem', fn() => $t('[1, $u];', '$u') === 'array');
check('array access', fn() => $t('$arr[0];', 'array $arr') === '\N\Foo' && $t('$l[0];', 'int $l') === 'null' && $t('[1, 2][0];') === 'int');
check('unknown variable', fn() => $t('$nope;') === 'null');
check('identifier unknown', fn() => $t('PHP_EOL;') === 'null');
check('depth cap', function () use ($idx) {
    $src = '$a'; for ($i = 0; $i < 200; $i = $i + 1) { $src = '(' . $src . ' + 1)'; }
    $file = pf('d.php', "<?php\n\$z = $src;\n");
    $stmt = $file->program->statements[0];
    $r = (new ExprTyper($idx, 'd.php'))->typeOf($stmt->expr, new TypeEnv(['a' => new SourceType(SourceType::SCALAR, 'int')]));
    return $r === null ? true : 'deep expression typed: ' . $r->toString();
});

// ── Parity with Analyze\Infer ───────────────────────────────────────────

/** @return ?string comparable kind label for a SourceType */
function stKind(SourceType $s): string
{
    if ($s->kind === SourceType::NULLABLE) { return stKind($s->args[0]); }
    switch ($s->kind) {
        case SourceType::SCALAR:
            return $s->name === 'true' || $s->name === 'false' ? 'bool' : $s->name;
        case SourceType::GENERIC:
            return $s->name === 'array' || $s->name === 'list' ? 'array' : 'object';
        case SourceType::ITERABLE:
            return 'opaque';
        case SourceType::CLASS_:
            return 'object';
        default:
            return $s->kind;
    }
}

function tyToSource(Ty $ty): ?SourceType
{
    $inner = null;
    switch ($ty->kind) {
        case Ty::KIND_INT: $inner = new SourceType(SourceType::SCALAR, 'int'); break;
        case Ty::KIND_FLOAT: $inner = new SourceType(SourceType::SCALAR, 'float'); break;
        case Ty::KIND_STRING: $inner = new SourceType(SourceType::SCALAR, 'string'); break;
        case Ty::KIND_BOOL: $inner = new SourceType(SourceType::SCALAR, 'bool'); break;
        case Ty::KIND_ARRAY_: $inner = new SourceType(SourceType::SCALAR, 'array'); break;
        case Ty::KIND_OBJECT: $inner = new SourceType(SourceType::CLASS_, $ty->className); break;
        default: return null;
    }
    return $ty->nullable ? new SourceType(SourceType::NULLABLE, '', [$inner]) : $inner;
}

$tyKind = [Ty::KIND_NULL => 'null', Ty::KIND_BOOL => 'bool', Ty::KIND_INT => 'int', Ty::KIND_FLOAT => 'float',
    Ty::KIND_STRING => 'string', Ty::KIND_ARRAY_ => 'array', Ty::KIND_OBJECT => 'object', Ty::KIND_MIXED => 'mixed',
    Ty::KIND_VOID => 'void', Ty::KIND_NEVER => 'never'];

$files = [];
$cases = \glob(__DIR__ . '/../analyze/cases/*.php');
foreach ($cases as $c) { $files[] = pf($c, (string)\file_get_contents($c)); }
foreach (\glob(__DIR__ . '/../analyze/cases/*', GLOB_ONLYDIR) as $dir) {
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if (\str_ends_with((string)$f, '.php')) { $files[] = pf((string)$f, (string)\file_get_contents((string)$f)); } }
}
$pidx = Index::build($files);
$ptyper = new ExprTyper($pidx, 'x');
$compared = 0;
$bad = [];
foreach ($files as $file) {
    $units = new Units();
    $units->collect($file->program->statements);
    foreach ($units->units as $unit) {
        $scope = Scope::build($unit, $pidx);
        $env = TypeEnv::forUnit($unit, $pidx);
        foreach ($scope->vars as $n => $ty) {
            if (!isset($env->vars[$n])) { $s = tyToSource($ty); if ($s !== null) { $env->vars[$n] = $s; } }
        }
        $infer = new \Analyze\Infer($pidx, $scope);
        $w = new AstWalk(true);
        if ($unit->exprBody !== null) { $w->exprTree($unit->exprBody); } else { $w->stmts($unit->stmts); }
        foreach ($w->exprs as $e) {
            $ty = $infer->of($e);
            if (!isset($tyKind[$ty->kind])) { continue; }
            $got = $ptyper->typeOf($e, $env);
            $compared = $compared + 1;
            $want = $tyKind[$ty->kind];
            if ($got === null || stKind($got) !== $want) {
                $bad[] = \basename($file->path) . ':' . $e->span->startLine() . ' ' . \get_class($e) . ' infer=' . $want . ' typer=' . ($got === null ? 'null' : $got->toString());
            }
        }
    }
}
echo "parity: compared $compared, disagreements " . \count($bad) . "\n";
foreach (\array_slice($bad, 0, 30) as $b) { echo "  $b\n"; }
check('parity with Infer', fn() => $compared > 100 && \count($bad) === 0);

exit($fail);
