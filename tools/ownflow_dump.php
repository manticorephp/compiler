<?php

/**
 * Zend-hosted driver for {@see \Compile\Mir\Flow\Forward}: lowers ONE file
 * through the pipeline up to SpillFreshBases (the point where ownership
 * analysis will run), then runs a "definitely defined" lattice over one
 * function and prints the state before every statement.
 *
 *   php -d xdebug.mode=off tools/ownflow_dump.php <file.php> <fn>
 *
 * MC_SRC / MC_SIG / MANTICORE_PRELUDE default to this checkout.
 */

use Compile\Mir\Block;
use Compile\Mir\Flow\Forward;
use Compile\Mir\Flow\Lattice;
use Compile\Mir\Foreach_;
use Compile\Mir\IncDec;
use Compile\Mir\MirCatch;
use Compile\Mir\Node;
use Compile\Mir\RefAddr_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RefBind_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\StoreLocal;
use Compile\Mir\Unset_;

$root = \dirname(__DIR__);
$srcBase = \getenv('MC_SRC');
if (!\is_string($srcBase) || $srcBase === '') { $srcBase = $root . '/src'; }
if (\getenv('MANTICORE_PRELUDE') === false) { \putenv('MANTICORE_PRELUDE=' . $root . '/prelude'); }

\spl_autoload_register(function ($class) use ($srcBase) {
    $path = $srcBase . '/' . \str_replace('\\', '/', $class) . '.php';
    if (\file_exists($path)) {
        require $path;
        return;
    }
    if (\str_starts_with($class, 'Parser\\Ast\\')) {
        foreach (['Stmt.php', 'Expr.php'] as $umbrella) {
            $u = $srcBase . '/Parser/Ast/' . $umbrella;
            if (\is_file($u)) { require_once $u; }
        }
    }
    if (\str_starts_with($class, 'Compile\\Mir\\')) {
        require_once $srcBase . '/Compile/Mir/Nodes.php';
    }
});

require_once $srcBase . '/Manticore/Main.php';
if (!\function_exists('str_bytes')) {
    function str_bytes(string $s): int { return $s === '' ? 0 : 1; }
    function manticore_raw_str_bytes(string $s): int { return $s === '' ? 0 : 1; }
}

\Compile\Debug::initFromEnvironment();

if ($argc < 3) {
    \fwrite(STDERR, "usage: ownflow_dump.php <file.php> <fn>\n");
    exit(64);
}
$file = $argv[1];
$fnName = $argv[2];
if (!\is_file($file)) {
    \fwrite(STDERR, "not a file: $file\n");
    exit(66);
}

$sig = \getenv('MC_SIG');
if (!\is_string($sig) || $sig === '') { $sig = $root . '/lib/manticore_stdlib.o.sig'; }
if (\is_file($sig)) {
    $json = \file_get_contents($sig);
    if ($json !== false) {
        \Manticore\CompileArgs::$externDecls = \Manticore\Sig::declsFromJson($json);
        \Manticore\import_stdlib_types($json);
    }
}

\Manticore\CompileArgs::$files = [$file];
\Manticore\CompileArgs::$stopBeforeMemoryOps = true;
$src = [\file_get_contents($file)];
$module = \Manticore\lower_module($src);
if ($module === null) {
    \fwrite(STDERR, "compile error (MIR)\n");
    exit(70);
}

$fn = null;
foreach ($module->functions as $f) {
    if (\strtolower($f->name) === \strtolower($fnName)) { $fn = $f; break; }
}
if ($fn === null) {
    \fwrite(STDERR, "no function $fnName\n");
    exit(65);
}

/** "Definitely defined": name → 1 once stored on every path; join = intersection. */
final class DefinedLattice implements Lattice
{
    /** @param array<string,int> $params */
    public function __construct(private array $params) {}

    public function entry(): array { return $this->params; }

    public function join(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if (isset($b[$k])) { $out[$k] = 1; }
        }
        return $out;
    }

    public function equal(array $a, array $b): bool
    {
        if (\count($a) !== \count($b)) { return false; }
        foreach ($a as $k => $v) {
            if (!isset($b[$k])) { return false; }
        }
        return true;
    }

    public function transfer(Node $n, array $in): array
    {
        if ($n instanceof StoreLocal) { $in[$n->name] = 1; }
        elseif ($n instanceof IncDec) { $in[$n->name] = 1; }
        elseif ($n instanceof RefAlias_) { $in[$n->target] = 1; }
        elseif ($n instanceof RefBind_) { $in[$n->target] = 1; }
        elseif ($n instanceof RefAddr_) { $in[$n->target] = 1; }
        elseif ($n instanceof StaticLocalDecl_) { $in[$n->name] = 1; }
        elseif ($n instanceof Foreach_) {
            $in[$n->valueVar] = 1;
            if ($n->keyVar !== null) { $in[$n->keyVar] = 1; }
        } elseif ($n instanceof Unset_) {
            foreach ($n->targets as $t) {
                if ($t->kind === Node::KIND_LOAD_LOCAL) { unset($in[$t->name]); }
            }
        }
        return $in;
    }

    public function catchEntry(MirCatch $c, array $in): array
    {
        if ($c->var !== null) { $in[$c->var] = 1; }
        return $in;
    }

    /** @var string[] */
    public array $edges = [];

    public function onEdge(string $kind, Node $at, array $out, array $joined): void
    {
        $this->edges[] = $kind . ' @' . ($at->line > 0 ? 'L' . $at->line : '?') . ' ' . $at->kind
            . ': ' . self::fmt($out) . ' -> ' . self::fmt($joined);
    }

    /** @param array<string,int> $s */
    public static function fmt(array $s): string
    {
        $k = \array_keys($s);
        \sort($k);
        return '{' . \implode(',', $k) . '}';
    }
}

$params = [];
foreach ($fn->params as $p) { $params[$p->name] = 1; }
$lat = new DefinedLattice($params);
$flow = new Forward($lat);
$flow->run($fn->body);
$before = $flow->stateBefore();

/** @param Node[] $stmts */
function dumpStmts(array $stmts, array $before, int $depth): void
{
    foreach ($stmts as $s) {
        $id = \spl_object_id($s);
        $st = isset($before[$id]) ? DefinedLattice::fmt($before[$id]) : 'unreachable';
        echo \str_repeat('  ', $depth) . 'L' . $s->line . ' ' . $s->kind . ': ' . $st . "\n";
        foreach (nestedBodies($s) as $label => $body) {
            echo \str_repeat('  ', $depth + 1) . $label . "\n";
            dumpStmts($body, $before, $depth + 2);
        }
    }
}

/** @return array<string, Node[]> */
function nestedBodies(Node $s): array
{
    $out = [];
    if ($s instanceof \Compile\Mir\If_) {
        $out['then'] = $s->then->stmts;
        if ($s->else !== null) { $out['else'] = $s->else->stmts; }
    } elseif ($s instanceof \Compile\Mir\While_ || $s instanceof \Compile\Mir\For_
        || $s instanceof \Compile\Mir\DoWhile_ || $s instanceof Foreach_) {
        $out['body'] = $s->body->stmts;
    } elseif ($s instanceof \Compile\Mir\Switch_) {
        foreach ($s->arms as $i => $arm) {
            $out[($arm->value === null ? 'default' : 'case') . '#' . $i] = $arm->body;
        }
    } elseif ($s instanceof \Compile\Mir\TryCatch_) {
        $out['try'] = $s->tryBody;
        foreach ($s->catches as $i => $c) { $out['catch#' . $i] = $c->body; }
        if ($s->hasFinally) { $out['finally'] = $s->finallyBody; }
    } elseif ($s instanceof Block) {
        $out['block'] = $s->stmts;
    }
    return $out;
}

echo "function {$fn->name}\n";
dumpStmts($fn->body->stmts, $before, 1);
echo "edges\n";
foreach ($lat->edges as $e) { echo '  ' . $e . "\n"; }
