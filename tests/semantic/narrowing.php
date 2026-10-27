<?php

require __DIR__ . '/bootstrap.php';

use Analyze\AstWalk;
use Analyze\FlowWalk;
use Analyze\Index;
use Analyze\ParsedFile;
use Analyze\Ty;
use Analyze\Units;
use Parser\Ast\Assign;
use Parser\Ast\Variable;
use Semantic\ExprTyper;
use Semantic\Flow;
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
    . "interface Shape {}\n"
    . "class Circle implements Shape {}\n"
    . "class Base {}\n"
    . "class Foo extends Base {\n"
    . "    public ?Bar \$nb = null;\n    public Bar \$bar;\n"
    . "    public function bar(): Bar { return new Bar; }\n"
    . "    public function me(): ?Foo { return null; }\n"
    . "}\n"
    . "final class Bar { public function id(): int { return 1; } public ?Foo \$foo = null; }\n"
    . "function mk(): ?Foo { return null; }\n"
    . "function nv(): never { exit; }\n"
    . "function refy(&\$o): void {}\n";

/**
 * Run Flow at the marker `$__T = EXPR;` inside method m() of N\Foo2 (a subclass of Foo).
 *
 * @return array{TypeEnv, string} env at EXPR and EXPR's type under it
 */
function at(string $code, string $params = '', string $where = 'method'): array
{
    if ($where === 'method') { $params = \trim($params) === '' ? 'array $arr' : $params . ', array $arr'; }
    $src = $where === 'method'
        ? "<?php\nnamespace N;\nclass Foo2 extends Foo {\n  /** @param \\N\\Foo[] \$arr */ public function m($params) {\n$code\n  }\n}\n"
        : "<?php\nnamespace N;\nfunction f($params) {\n$code\n}\n";
    $file = pf('u.php', $src);
    $idx = Index::build([pf('lib.php', $GLOBALS['LIB']), $file]);
    $w = new AstWalk();
    $w->stmts($file->program->statements);
    $target = null;
    foreach ($w->exprs as $x) {
        if ($x instanceof Assign && $x->target instanceof Variable && $x->target->name === '__T') { $target = $x->value; break; }
    }
    if ($target === null) { throw new \RuntimeException('no marker'); }
    $env = Flow::envAt($file->program, $idx, 'u.php', $target);
    $t = (new ExprTyper($idx, 'u.php'))->typeOf($target, $env);
    return [$env, $t === null ? 'null' : $t->toString()];
}

/** order-insensitive union comparison */
function ueq(string $a, string $b): bool
{
    $x = explode('|', $a); $y = explode('|', $b); sort($x); sort($y);
    return $x === $y;
}

/** type of variable (or property path) $name in env, 'unknown' when absent */
function v(array $r, string $name): string
{
    $t = $r[0]->vars[$name] ?? null;
    return $t === null ? 'unknown' : $t->toString();
}

// ── assignments ──
check('new then method', function () {
    $r = at('$x = new Foo; $__T = $x->bar();');
    return $r[1] === 'N\Bar' && v($r, 'x') === 'N\Foo' ? true : json_encode([$r[1], v($r, 'x')]);
});
check('strong update in order', fn() => v(at('$x = new Foo; $x = new Bar; $__T = 1;'), 'x') === 'N\Bar');
check('unknown rhs makes unknown', fn() => v(at('$x = new Foo; $x = zzz(); $__T = 1;'), 'x') === 'unknown');
check('chained assignment', function () {
    $r = at('$a = $b = new Foo; $__T = 1;');
    return v($r, 'a') === 'N\Foo' && v($r, 'b') === 'N\Foo' ? true : json_encode([v($r, 'a'), v($r, 'b')]);
});
check('compound assign', fn() => v(at('$n = 1; $n += 2.5; $s = "a"; $s .= "b"; $__T = 1;'), 'n') === 'float' && v(at('$s = "a"; $s .= "b"; $__T = 1;'), 's') === 'string');
check('destructuring is unknown', fn() => v(at('$x = new Foo; [$x, $y] = zzz(); $__T = 1;'), 'x') === 'unknown');
check('global / static are unknown', fn() => v(at('$x = new Foo; global $x; $__T = 1;'), 'x') === 'unknown' && v(at('$x = new Foo; static $x; $__T = 1;'), 'x') === 'unknown');
check('by-ref arg is unknown', fn() => v(at('$x = new Foo; refy($x); $__T = 1;'), 'x') === 'unknown' && v(at('$x = new Foo; $y = new Foo; refy($y); $__T = 1;'), 'x') === 'N\Foo');
check('nullsafe is nullable', function () {
    $r = at('$y = $x?->bar(); $__T = $y;', '?Foo $x');
    return $r[1] === '?N\Bar' ? true : $r[1];
});

// ── null checks ──
check('=== null return removes null', fn() => v(at('if ($x === null) return; $__T = 1;', '?Foo $x'), 'x') === 'N\Foo');
check('null on the else path', fn() => v(at('if ($x !== null) { } else { $__T = 1; }', '?Foo $x'), 'x') === 'null');
check('!== null then', fn() => v(at('if ($x !== null) { $__T = 1; }', '?Foo $x'), 'x') === 'N\Foo');
check('reversed null literal', fn() => v(at('if (null === $x) return; $__T = 1;', '?Foo $x'), 'x') === 'N\Foo');
check('loose == null else', fn() => v(at('if ($x == null) { return; } $__T = 1;', '?Foo $x'), 'x') === 'N\Foo');
check('truthiness', function () {
    $a = v(at('if ($x) { $__T = 1; }', '?Foo $x'), 'x');
    $b = v(at('if (!$x) { $__T = 1; }', '?Foo $x'), 'x');
    return $a === 'N\Foo' && $b === 'null' ? true : "$a / $b";
});
check('isset and empty', fn() => v(at('if (isset($x)) { $__T = 1; }', '?Foo $x'), 'x') === 'N\Foo' && v(at('if (empty($x)) { return; } $__T = 1;', '?Foo $x'), 'x') === 'N\Foo');
check('assignment in condition', function () {
    $r = at('if (($y = mk()) !== null) { $__T = 1; }');
    return v($r, 'y') === 'N\Foo' ? true : v($r, 'y');
});

// ── instanceof ──
check('instanceof then', fn() => v(at('if ($x instanceof Foo) { $__T = 1; }', 'Foo|Bar $x'), 'x') === 'N\Foo');
check('instanceof else keeps remainder', fn() => v(at('if ($x instanceof Foo) { } else { $__T = 1; }', 'Foo|Bar $x'), 'x') === 'N\Bar');
check('instanceof on unknown', fn() => v(at('if ($x instanceof Foo) { $__T = 1; }', '$x'), 'x') === 'N\Foo');
check('guard-then-exit instanceof', fn() => v(at('if (!($x instanceof Foo)) { throw new \Exception; } $__T = 1;', 'Foo|Bar $x'), 'x') === 'N\Foo');
check('elseif remainder', function () {
    $a = v(at('if ($x instanceof Foo) { } elseif ($x instanceof Bar) { $__T = 1; }', 'Foo|Bar|Circle $x'), 'x');
    $b = v(at('if ($x instanceof Foo) { } elseif ($x instanceof Bar) { } else { $__T = 1; }', 'Foo|Bar|Circle $x'), 'x');
    return $a === 'N\Bar' && $b === 'N\Circle' ? true : "$a / $b";
});
check('instanceof subclass keeps the narrower member', fn() => v(at('if ($x instanceof Base) { $__T = 1; }', 'Foo|Bar $x'), 'x') === 'N\Foo');
check('instanceof self', fn() => v(at('if ($x instanceof self) { $__T = 1; }', '$x'), 'x') === 'N\Foo2');

// ── || && ──
check('|| both operands in the else branch', function () {
    $r = at('if ($x === null || $y === null) { return; } $__T = 1;', '?Foo $x, ?Bar $y');
    return v($r, 'x') === 'N\Foo' && v($r, 'y') === 'N\Bar' ? true : json_encode([v($r, 'x'), v($r, 'y')]);
});
check('|| right operand sees left false', fn() => v(at('$ok = $x === null || ($__T = 1);', '?Foo $x'), 'x') === 'N\Foo');
check('|| true branch is the join', function () {
    $r = at('if ($x === null || $y === null) { $__T = 1; }', '?Foo $x, ?Bar $y');
    return v($r, 'x') === '?N\Foo' && v($r, 'y') === '?N\Bar' ? true : json_encode([v($r, 'x'), v($r, 'y')]);
});
check('&& narrows the right operand and the then', function () {
    $r = at('if ($x !== null && $y !== null) { $__T = 1; }', '?Foo $x, ?Bar $y');
    $q = at('$ok = $x !== null && ($__T = 1);', '?Foo $x');
    return v($r, 'x') === 'N\Foo' && v($r, 'y') === 'N\Bar' && v($q, 'x') === 'N\Foo' ? true : 'then / right';
});
check('!(a && b) else', fn() => v(at('if (!($x !== null && $y)) { return; } $__T = 1;', '?Foo $x, ?Bar $y'), 'x') === 'N\Foo');

// ── ternary / match ──
check('ternary arms', function () {
    $a = v(at('$r = $x === null ? 0 : ($__T = 1);', '?Foo $x'), 'x');
    $b = v(at('$r = $x === null ? ($__T = 1) : 0;', '?Foo $x'), 'x');
    return $a === 'N\Foo' && $b === 'null' ? true : "$a / $b";
});
check('match(true) arms', function () {
    $a = v(at('$r = match (true) { $x === null => 0, default => ($__T = 1) };', '?Foo $x'), 'x');
    $b = v(at('$r = match (true) { $x instanceof Foo => ($__T = 1), default => 0 };', 'Foo|Bar $x'), 'x');
    return $a === 'N\Foo' && $b === 'N\Foo' ? true : "$a / $b";
});
check('null-coalesce / throw expression', fn() => v(at('$y = $x ?? throw new \Exception; $__T = 1;', '?Foo $x'), 'y') !== 'zzz');
check('throw expression arm is dead', function () {
    $r = at('$r = $x === null ? throw new \Exception : 0; $__T = 1;', '?Foo $x');
    return v($r, 'x') === 'N\Foo' ? true : v($r, 'x');
});

// ── is_* ──
check('is_* family', function () {
    $a = v(at('if (is_int($x)) { $__T = 1; }', 'int|string $x'), 'x');
    $b = v(at('if (is_int($x)) { } else { $__T = 1; }', 'int|string $x'), 'x');
    $c = v(at('if (is_string($x)) { $__T = 1; }', 'mixed $x'), 'x');
    $d = v(at('if (!is_array($x)) { return; } $__T = 1;', 'mixed $x'), 'x');
    $e = v(at('if (is_null($x)) { return; } $__T = 1;', '?Foo $x'), 'x');
    return $a === 'int' && $b === 'string' && $c === 'string' && $d === 'array' && $e === 'N\Foo' ? true : "$a $b $c $d $e";
});

// ── exits ──
check('any always-exiting branch', function () {
    $a = v(at('if ($x === null) { echo 1; throw new \Exception; } $__T = 1;', '?Foo $x'), 'x');
    $b = v(at('if ($x === null) { echo 1; } else { return; } $__T = 1;', '?Foo $x'), 'x');
    $c = v(at('if ($x === null) { if ($y) { return; } else { exit(1); } } $__T = 1;', '?Foo $x, $y'), 'x');
    return $a === 'N\Foo' && $b === 'null' && $c === 'N\Foo' ? true : "$a / $b / $c";
});
check('never call is dead', fn() => at('nv(); $__T = 1;')[0]->dead === true);
check('continue / break guards', function () {
    $a = v(at('foreach ($arr as $v) { if ($y === null) { continue; } $__T = 1; }', '?Foo $y'), 'y');
    $b = v(at('foreach ($arr as $v) { if ($y === null) { break; } $__T = 1; }', '?Foo $y'), 'y');
    return $a === 'N\Foo' && $b === 'N\Foo' ? true : "$a / $b";
});

// ── loops ──
check('while negation after the loop', fn() => v(at('while ($x === null) { $x = mk(); } $__T = 1;', '?Foo $x'), 'x') === 'N\Foo');
check('while condition holds in the body', fn() => v(at('while ($x !== null) { $__T = 1; $x = $x->me(); }', '?Foo $x'), 'x') === 'N\Foo');
check('reassign in loop widens to the union', function () {
    $in = v(at('$x = new Foo; while (zzz()) { $__T = 1; $x = new Bar; }'), 'x');
    $after = v(at('$x = new Foo; while (zzz()) { $x = new Bar; } $__T = 1;'), 'x');
    $fe = v(at('$x = new Foo; foreach ($arr as $v) { $__T = 1; $x = new Bar; }'), 'x');
    $fo = v(at('$x = new Foo; for ($i = 0; $i < 3; $i++) { $__T = 1; $x = new Bar; }'), 'x');
    $dw = v(at('$x = new Foo; do { $__T = 1; $x = new Bar; } while (zzz());'), 'x');
    return $in === 'N\Foo|N\Bar' && $after === 'N\Foo|N\Bar' && $fe === 'N\Foo|N\Bar' && $fo === 'N\Foo|N\Bar' && $dw === 'N\Foo|N\Bar' ? true : "$in / $after / $fe / $fo / $dw";
});
check('two-step chain converges within the limit', function () {
    $in = v(at('$a = new Foo; $b = new Bar; while (zzz()) { $__T = 1; $t = $a; $a = $b; $b = $t; }'), 'a');
    return $in === 'N\Foo|N\Bar' ? true : $in;
});
check('foreach binds key and value', function () {
    $r = at('foreach ($arr as $k => $v) { $__T = 1; }');
    $s = at('foreach ($arr as $v) { } $__T = 1;');
    return v($r, 'v') === 'N\Foo' && v($s, 'v') === 'unknown' ? true : json_encode([v($r, 'v'), v($s, 'v')]);
});
check('break env joins after loop', function () {
    $r = at('$x = new Foo; while (zzz()) { if (zzz()) { $x = new Bar; break; } } $__T = 1;');
    return v($r, 'x') === 'N\Foo|N\Bar' ? true : v($r, 'x');
});
check('nested loops do not explode', function () {
    $code = '$x = new Foo;';
    for ($i = 0; $i < 6; $i++) { $code .= " while (zzz()) { foreach (\$arr as \$v) {"; }
    $code .= ' $__T = 1; $x = new Bar;';
    for ($i = 0; $i < 6; $i++) { $code .= ' } }'; }
    $t0 = microtime(true);
    $r = at($code);
    return microtime(true) - $t0 < 5.0 && ueq(v($r, 'x'), 'N\Foo|N\Bar') ? true : 'slow or wrong: ' . v($r, 'x') . ' ' . (microtime(true) - $t0);
});

// ── try / switch ──
check('catch type and union', function () {
    $r = at('try { zzz(); } catch (\InvalidArgumentException | \RuntimeException $e) { $__T = 1; }');
    return v($r, 'e') === 'InvalidArgumentException|RuntimeException' ? true : v($r, 'e');
});
check('catch env is the union of pre-try and inside', function () {
    $r = at('$x = new Foo; try { $x = new Bar; zzz(); } catch (\Exception $e) { $__T = 1; }');
    $s = at('$x = new Foo; try { $x = new Bar; zzz(); } catch (\Exception $e) { } $__T = 1;');
    return ueq(v($r, 'x'), 'N\Foo|N\Bar') && ueq(v($s, 'x'), 'N\Foo|N\Bar') ? true : v($r, 'x') . ' / ' . v($s, 'x');
});
check('finally sees the wide env', function () {
    $r = at('$x = new Foo; try { $x = new Bar; } finally { $__T = 1; }');
    return ueq(v($r, 'x'), 'N\Foo|N\Bar') ? true : v($r, 'x');
});
check('switch joins cases', function () {
    $a = v(at('switch ($k) { case 1: $x = new Foo; break; default: $x = new Bar; } $__T = 1;', '$k'), 'x');
    $b = v(at('switch ($k) { case 1: $x = new Foo; break; case 2: $x = new Bar; break; } $__T = 1;', '$k'), 'x');
    $c = v(at('switch ($k) { case 1: $x = new Foo; case 2: $__T = 1; }', '$k'), 'x');
    return ueq($a, 'N\Foo|N\Bar') && $b === 'unknown' && $c === 'unknown' ? true : "$a / $b / $c";
});

// ── property paths ──
check('property path narrowing', function () {
    $in = at('if ($this->nb !== null) { $__T = $this->nb; }');
    $out = at('$__T = $this->nb;');
    return $in[1] === 'N\Bar' && $out[1] === '?N\Bar' ? true : $in[1] . ' / ' . $out[1];
});
check('property path on a variable root', fn() => at('if ($a->nb !== null) { $__T = $a->nb; }', 'Foo $a')[1] === 'N\Bar');
check('property path dies on assignment to it', fn() => at('if ($this->nb !== null) { $this->nb = null; $__T = $this->nb; }')[1] === 'null');
check('property path dies on root reassignment', fn() => at('if ($a->nb !== null) { $a = new Foo; $__T = $a->nb; }', 'Foo $a')[1] === '?N\Bar');
check('property path dies on a method call on the root', function () {
    $a = at('if ($this->nb !== null) { $this->me(); $__T = $this->nb; }')[1];
    $b = at('if ($a->nb !== null) { $a->me(); $__T = $a->nb; }', 'Foo $a')[1];
    $c = at('if ($a->nb !== null) { $this->me(); $__T = $a->nb; }', 'Foo $a')[1];
    return $a === '?N\Bar' && $b === '?N\Bar' && $c === 'N\Bar' ? true : "$a / $b / $c";
});
check('instanceof on a property path', fn() => at('if ($this->bar instanceof Bar) { $__T = $this->bar; }')[1] === 'N\Bar');
check('nested property path', fn() => at('if ($a->bar->foo !== null) { $__T = $a->bar->foo; }', 'Foo $a')[1] === 'N\Foo');

// ── closures ──
check('closure $this', function () {
    $r = at('$f = function () { $__T = 1; };');
    return v($r, 'this') === 'N\Foo2' && $r[0]->thisClass === 'N\Foo2' && !$r[0]->isStatic ? true : v($r, 'this');
});
check('static closure and static fn have no $this', function () {
    $a = at('$f = static function () { $__T = 1; };');
    $b = at('$f = static fn() => ($__T = 1);');
    return v($a, 'this') === 'unknown' && $a[0]->isStatic && v($b, 'this') === 'unknown' && $b[0]->isStatic && $b[0]->thisClass === 'N\Foo2' ? true : 'this leaked';
});
check('arrow fn has $this', fn() => v(at('$f = fn() => ($__T = 1);'), 'this') === 'N\Foo2');
check('closure use copies the type at the closure', function () {
    $r = at('$x = new Foo; $f = function () use ($x) { $__T = 1; }; $x = new Bar;');
    return v($r, 'x') === 'N\Foo' ? true : v($r, 'x');
});
check('closure use by ref is unknown, outside too', function () {
    $r = at('$x = new Foo; $f = function () use (&$x) { $__T = 1; };');
    $s = at('$x = new Foo; $f = function () use (&$x) { }; $__T = 1;');
    return v($r, 'x') === 'unknown' && v($s, 'x') === 'unknown' ? true : v($r, 'x') . ' / ' . v($s, 'x');
});
check('closure does not capture implicitly', fn() => v(at('$x = new Foo; $f = function () { $__T = 1; };'), 'x') === 'unknown');
check('arrow fn captures the enclosing env by value', function () {
    $r = at('$x = new Foo; $f = fn() => ($__T = $x);');
    $p = at('$x = new Foo; $f = fn($x) => ($__T = 1);');
    return $r[1] === 'N\Foo' && v($p, 'x') === 'unknown' ? true : $r[1] . ' / ' . v($p, 'x');
});
check('closure params typed', fn() => v(at('$f = function (Foo $p) { $__T = 1; };'), 'p') === 'N\Foo');
check('narrowing inside a closure', fn() => v(at('$f = function (?Foo $p) { if ($p === null) { return; } $__T = 1; };'), 'p') === 'N\Foo');
check('closure inside a loop sees the widened env', function () {
    $r = at('$x = new Foo; while (zzz()) { $f = function () use ($x) { $__T = 1; }; $x = new Bar; }');
    return v($r, 'x') === 'N\Foo|N\Bar' ? true : v($r, 'x');
});
check('function unit (no class)', function () {
    $r = at('if ($x === null) return; $__T = 1;', '?Foo $x', 'function');
    return v($r, 'x') === 'N\Foo' && !isset($r[0]->vars['this']) ? true : v($r, 'x');
});
check('main unit', function () {
    $file = pf('u.php', "<?php\nnamespace N;\n\$x = mk();\nif (\$x !== null) { \$__T = \$x; }\n");
    $idx = Index::build([pf('lib.php', $GLOBALS['LIB']), $file]);
    $w = new AstWalk();
    $w->stmts($file->program->statements);
    foreach ($w->exprs as $x) {
        if ($x instanceof Assign && $x->target instanceof Variable && $x->target->name === '__T') {
            $env = Flow::envAt($file->program, $idx, 'u.php', $x->value);
            return ($env->vars['x'] ?? null) !== null && $env->vars['x']->toString() === 'N\Foo' ? true : 'x';
        }
    }
    return 'no marker';
});

// ── performance ──
check('early exit is cheap on a big unit', function () {
    $code = '';
    for ($i = 0; $i < 3000; $i++) { $code .= "\$v$i = new Foo; "; }
    $code .= '$__T = 1;';
    for ($i = 0; $i < 3000; $i++) { $code .= "\$w$i = new Bar; "; }
    $t0 = microtime(true);
    $r = at($code);
    $dt = microtime(true) - $t0;
    return $dt < 8.0 && v($r, 'v2999') === 'N\Foo' ? true : "dt=$dt";
});

// ── agreement with Analyze\FlowWalk ──
check('agrees with FlowWalk where it narrows', function () {
    $total = 0;
    foreach (['narrowing.php', 'flow_depth.php'] as $name) {
        $src = (string)file_get_contents(dirname(__DIR__) . '/analyze/cases/' . $name);
        $file = pf($name, $src);
        $idx = Index::build([$file]);
        $units = new Units();
        $units->collect($file->program->statements, $file->program->useAliases);
        foreach ($units->units as $u) {
            if ($u->label === '{closure}' || $u->label === '{main}') { continue; }
            $fw = new FlowWalk($idx);
            $fw->walkUnit($u);
            foreach ($fw->exprs as $i => $x) {
                if (!($x instanceof Variable)) { continue; }
                $ty = $fw->scopes[$i]->typeOf($x->name);
                $want = $ty->kind === Ty::KIND_OBJECT ? ($ty->nullable ? null : \ltrim((string)$ty->className, '\\')) : ($ty->nullable ? null : $ty->kind);
                if ($want === null || $ty->kind === Ty::KIND_UNKNOWN || $ty->kind === Ty::KIND_OPAQUE || $ty->kind === Ty::KIND_MIXED) { continue; }
                $env = Flow::envAt($file->program, $idx, $name, $x);
                $ours = $env->vars[$x->name] ?? null;
                if ($ours === null) { continue; }
                $total++;
                $got = $ours->kind === SourceType::CLASS_ ? \ltrim($ours->name, '\\') : $ours->toString();
                if (\strcasecmp($got, $want) !== 0) { return "$name {$u->label} \${$x->name}: FlowWalk=$want Flow=" . $ours->toString(); }
            }
        }
    }
    return $total >= 5 ? true : "only $total comparisons";
});

exit($fail);
