<?php

namespace Compile\Mir\Passes;

use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayElement_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Block;
use Compile\Mir\BoolConst;
use Compile\Mir\Call;
use Compile\Mir\Foreach_;
use Compile\Mir\If_;
use Compile\Mir\Isset_;
use Compile\Mir\LoadLocal;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\RefAddr_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RefBind_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\StoreLocal;
use Compile\Mir\StringConst;
use Compile\Mir\TryCatch_;
use Compile\Mir\Type;

/**
 * `extract()` writes locals by RUNTIME name, and a local here is a static slot.
 * Lowering leaves a marker, `Block([Call(SITE, [arr, flags, prefix, hasPrefix])])`;
 * once every function is lowered — templates its includes inlined among them
 * ({@see LowerExprs::lowerScopedInclude}) — the names a function can ever read
 * are known, and the marker becomes:
 *
 *     $plan = __mc_extract_plan(arr, flags, prefix, hasPrefix, [name => defined?…], inMain);
 *     if (array_key_exists('a', $plan)) { $a = $plan['a']; }   // one per name
 *     __mc_extract_count($plan)                                // the value
 *
 * The stdlib plan decides php's assignments and count. A key naming no local
 * of the function is counted and dropped: no code of the function can read it.
 */
final class ExtractScope
{
    public const SITE = '__mc_extract_site';

    private const NOT_LOCALS = [
        'this' => true, 'GLOBALS' => true, '_SERVER' => true, '_GET' => true, '_POST' => true,
        '_COOKIE' => true, '_FILES' => true, '_ENV' => true, '_REQUEST' => true, '_SESSION' => true,
    ];

    private static int $seq = 0;

    public static function expand(Module $module): void
    {
        foreach ($module->functions as $fn) {
            /** @var Block[] $sites */
            $sites = [];
            /** @var array<string, bool> $names */
            $names = [];
            /** @var array<string, bool> $params */
            $params = [];
            foreach ($fn->params as $p) {
                $names[$p->name] = true;
                $params[$p->name] = true;
            }
            self::scan($fn->body, $sites, $names);
            if ($sites === []) { continue; }
            $locals = [];
            foreach ($names as $n => $_b) {
                $n = (string)$n;
                if (isset(self::NOT_LOCALS[$n]) || \str_starts_with($n, '__') || !self::validName($n)) { continue; }
                $locals[] = $n;
            }
            \sort($locals);
            foreach ($sites as $site) {
                self::fill($site, $locals, $params, $fn->name === '__main');
            }
        }
    }

    /**
     * @param Block[] $sites
     * @param array<string, bool> $names
     */
    private static function scan(Node $n, array &$sites, array &$names): void
    {
        if ($n instanceof Block && \count($n->stmts) === 1) {
            $c = $n->stmts[0];
            if ($c instanceof Call && $c->function === self::SITE) { $sites[] = $n; }
        }
        if ($n instanceof LoadLocal) { $names[$n->name] = true; }
        elseif ($n instanceof StoreLocal) { $names[$n->name] = true; }
        elseif ($n instanceof Foreach_) {
            $names[$n->valueVar] = true;
            if ($n->keyVar !== null) { $names[$n->keyVar] = true; }
        } elseif ($n instanceof TryCatch_) {
            foreach ($n->catches as $c) {
                if ($c->var !== null) { $names[$c->var] = true; }
            }
        } elseif ($n instanceof StaticLocalDecl_) { $names[$n->name] = true; }
        elseif ($n instanceof RefAlias_) { $names[$n->target] = true; }
        elseif ($n instanceof RefBind_) { $names[$n->target] = true; }
        elseif ($n instanceof RefAddr_) { $names[$n->target] = true; }
        foreach ($n->children() as $ch) {
            self::scan($ch, $sites, $names);
        }
    }

    /**
     * @param string[] $locals
     * @param array<string, bool> $params
     */
    private static function fill(Block $site, array $locals, array $params, bool $inMain): void
    {
        $call = $site->stmts[0];
        if (!($call instanceof Call)) { return; }
        $plan = '__mc_extract' . (string)self::$seq;
        self::$seq = self::$seq + 1;
        $exists = [];
        foreach ($locals as $n) {
            // A parameter is always defined; any other local is defined once it
            // holds a value. ponytail: a local assigned NULL reads as undefined
            // (php keeps it defined) — the slot carries no definedness bit.
            $def = isset($params[$n])
                ? new BoolConst(true, Type::bool_())
                : new Isset_([new LoadLocal($n, Type::unknown())], Type::bool_());
            $exists[] = new ArrayElement_(new StringConst($n, Type::string_()), $def);
        }
        $args = $call->args;
        $args[] = new ArrayLit($exists, Type::unknown());
        $args[] = new BoolConst($inMain, Type::bool_());
        $stmts = [new StoreLocal($plan, new Call('__mc_extract_plan', $args, Type::unknown()), Type::unknown())];
        foreach ($locals as $n) {
            $stmts[] = new If_(
                new Call('array_key_exists', [
                    new StringConst($n, Type::string_()),
                    new LoadLocal($plan, Type::unknown()),
                ], Type::bool_()),
                new Block([new StoreLocal($n, new ArrayAccess_(
                    new LoadLocal($plan, Type::unknown()),
                    new StringConst($n, Type::string_()),
                    Type::cell(),
                ), Type::cell())], Type::void()),
                null,
            );
        }
        $stmts[] = new Call('__mc_extract_count', [new LoadLocal($plan, Type::unknown())], Type::int_());
        $site->stmts = $stmts;
    }

    private static function validName(string $n): bool
    {
        $len = \strlen($n);
        if ($len === 0) { return false; }
        for ($i = 0; $i < $len; $i = $i + 1) {
            $c = \ord($n[$i]);
            if ($c === 95 || ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || $c >= 127) { continue; }
            if ($i > 0 && $c >= 48 && $c <= 57) { continue; }
            return false;
        }
        return true;
    }
}
