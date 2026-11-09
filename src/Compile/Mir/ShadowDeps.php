<?php

namespace Compile\Mir;

/**
 * `MANTICORE_DEPS_SHADOW=1`: would precise read dependencies have predicted what
 * the next full inference changed? Observes each full run and changes nothing.
 *
 * Reads come from the types inference left on the body: a call to a known callee
 * reads that callee's slots, a property access on a known class reads that
 * property. Between two runs, the slots that moved name their recorded readers;
 * a body whose types changed although nothing it read moved is a MISS, and the
 * misses are the edges a scheduler built on these reads would lose.
 */
final class ShadowDeps
{
    /** @var array<string, array<string, bool>> slot => the functions reading it */
    private static array $reads = [];
    /** @var array<string, string> function => its nodes' kinds and exact types */
    private static array $bodies = [];
    /** @var array<string, string> function => its nodes' kinds alone */
    private static array $shapes = [];
    /** @var array<string, string> function => its return type */
    private static array $returns = [];
    /** @var array<string, string> function => its parameter types */
    private static array $params = [];
    /** @var array<string, string> `Class::prop` => its type */
    private static array $props = [];
    /** @var array<string, string> function symbol => bare method name */
    private static array $bareOf = [];
    /** @var array<string, string[]> class => every class below it, itself included */
    private static array $subtypes = [];
    /** @var array<string, ClassDef> */
    private static array $classes = [];
    /** @var array<string, bool> functions woken by what moved in the previous interval: a scan can retype a slot after the walk, so its readers move one run later */
    private static array $wokenBefore = [];
    private static bool $seeded = false;
    private static int $run = 0;

    /** @var string */
    private static string $collectBody = '';
    /** @var string */
    private static string $collectShape = '';

    public static function enabled(): bool
    {
        return \getenv('MANTICORE_DEPS_SHADOW') === '1';
    }

    public static function observe(Module $module): void
    {
        self::$run = self::$run + 1;
        self::indexClasses($module);
        /** @var array<string, string> $bodies */
        $bodies = [];
        /** @var array<string, string> $shapes */
        $shapes = [];
        /** @var array<string, string> $returns */
        $returns = [];
        /** @var array<string, string> $params */
        $params = [];
        /** @var array<string, array<string, bool>> $reads */
        $reads = [];
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            self::$collectBody = '';
            self::$collectShape = '';
            self::collect($fn->body, $fn->name, $reads);
            $bodies[$fn->name] = self::$collectBody;
            $shapes[$fn->name] = self::$collectShape;
            $returns[$fn->name] = $fn->returnType->exactString();
            $ps = '';
            foreach ($fn->params as $p) { $ps .= $p->type->exactString() . ','; }
            $params[$fn->name] = $ps;
        }
        /** @var array<string, string> $props */
        $props = [];
        foreach ($module->classes as $cd) {
            foreach ($cd->propertyTypes as $name => $t) {
                $props[$cd->name . '::' . (string)$name] = $t->exactString();
            }
        }
        if (self::$seeded) { self::report($bodies, $shapes, $returns, $params, $props); }
        self::$seeded = true;
        self::$bodies = $bodies;
        self::$shapes = $shapes;
        self::$returns = $returns;
        self::$params = $params;
        self::$props = $props;
        self::$reads = $reads;
    }

    /**
     * @param array<string, string> $bodies
     * @param array<string, string> $shapes
     * @param array<string, string> $returns
     * @param array<string, string> $params
     * @param array<string, string> $props
     */
    private static function report(array $bodies, array $shapes, array $returns, array $params, array $props): void
    {
        /** @var array<string, bool> $predicted */
        $predicted = [];
        $retMoved = 0;
        $paramMoved = 0;
        $propMoved = 0;
        foreach ($returns as $name => $r) {
            if (!isset(self::$returns[$name]) || self::$returns[$name] === $r) { continue; }
            $retMoved = $retMoved + 1;
            $predicted[$name] = true;
            self::wake('r:' . $name, $predicted);
            $bare = self::$bareOf[$name] ?? '';
            if ($bare !== '') { self::wake('rm:' . $bare, $predicted); }
        }
        foreach ($params as $name => $p) {
            if (!isset(self::$params[$name]) || self::$params[$name] === $p) { continue; }
            $paramMoved = $paramMoved + 1;
            $predicted[$name] = true;
            self::wake('r:' . $name, $predicted);
            $bare = self::$bareOf[$name] ?? '';
            if ($bare !== '') { self::wake('rm:' . $bare, $predicted); }
        }
        foreach ($props as $key => $t) {
            if ((self::$props[$key] ?? '') === $t) { continue; }
            $propMoved = $propMoved + 1;
            $cut = \strrpos($key, '::');
            if ($cut === false) { continue; }
            $cls = \substr($key, 0, $cut);
            $prop = \substr($key, $cut + 2);
            self::wake('fw:' . $prop, $predicted);
            foreach (self::related($cls) as $c) { self::wake('f:' . $c . '::' . $prop, $predicted); }
        }
        $actual = 0;
        $mutated = 0;
        $fresh = 0;
        $missed = 0;
        /** @var array<string, int> $missKinds */
        $missKinds = [];
        $late = 0;
        /** @var string[] $samples */
        $samples = [];
        foreach ($bodies as $name => $b) {
            if (!isset(self::$bodies[$name])) { $fresh = $fresh + 1; continue; }
            if (self::$bodies[$name] === $b) { continue; }
            if (self::$shapes[$name] !== $shapes[$name]) { $mutated = $mutated + 1; continue; }
            $actual = $actual + 1;
            if (isset($predicted[$name])) { continue; }
            if (isset(self::$wokenBefore[$name])) { $late = $late + 1; continue; }
            $missed = $missed + 1;
            $diff = self::firstDiff(self::$bodies[$name], $b);
            $cut = \strpos($diff, ':');
            $kind = $cut === false ? '?' : \substr($diff, 0, $cut);
            $missKinds[$kind] = ($missKinds[$kind] ?? 0) + 1;
            if (\count($samples) < 14 && !\str_starts_with($name, '__mir_') && !\str_starts_with($name, '__mc_')) { $samples[] = $name . ' :: ' . $diff; }
        }
        \Compile\Stats::line('deps run ' . (string)self::$run
            . ': moved ret=' . (string)$retMoved . ' param=' . (string)$paramMoved . ' prop=' . (string)$propMoved
            . ' | bodies changed=' . (string)$actual . ' mutated=' . (string)$mutated . ' new=' . (string)$fresh
            . ' | predicted=' . (string)\count($predicted) . ' of ' . (string)\count($bodies)
            . ' | late=' . (string)$late . ' MISSED=' . (string)$missed . ' ' . \json_encode($missKinds));
        self::$wokenBefore = $predicted;
        foreach ($samples as $s) { \Compile\Stats::line('  deps miss ' . $s); }
    }

    private static function firstDiff(string $was, string $now): string
    {
        $a = \explode("\n", $was);
        $b = \explode("\n", $now);
        $n = \min(\count($a), \count($b));
        for ($i = 0; $i < $n; $i = $i + 1) {
            if ($a[$i] !== $b[$i]) { return \substr($a[$i], 0, 70) . ' -> ' . \substr($b[$i], 0, 70); }
        }
        return '(length)';
    }

    /** @param array<string, bool> $out */
    private static function wake(string $slot, array &$out): void
    {
        foreach ((self::$reads[$slot] ?? []) as $fn => $unused) { $out[$fn] = true; }
    }

    /**
     * Classes sharing an inherited slot with `$cls`: itself, what is above it and
     * what is below it.
     * @return string[]
     */
    private static function related(string $cls): array
    {
        $out = self::$subtypes[$cls] ?? [$cls];
        $c = self::$classes[$cls] ?? null;
        while ($c !== null && $c->parent !== '') {
            $out[] = $c->parent;
            $c = self::$classes[$c->parent] ?? null;
        }
        return $out;
    }

    private static function indexClasses(Module $module): void
    {
        self::$classes = $module->classes;
        self::$subtypes = [];
        self::$bareOf = [];
        foreach ($module->classes as $cd) {
            foreach ($cd->methodNames as $m => $unused) { self::$bareOf[$cd->name . '__' . (string)$m] = (string)$m; }
            /** @var array<string, bool> $seen */
            $seen = [];
            /** @var string[] $stack */
            $stack = [$cd->name];
            while ($stack !== []) {
                $c = \array_pop($stack);
                if ($c === '' || isset($seen[$c])) { continue; }
                $seen[$c] = true;
                self::$subtypes[$c][] = $cd->name;
                $pcd = $module->classes[$c] ?? null;
                if ($pcd === null) { continue; }
                if ($pcd->parent !== '') { $stack[] = $pcd->parent; }
                foreach ($pcd->interfaces as $i) { $stack[] = $i; }
            }
        }
    }

    private static function owner(string $cls, string $method): string
    {
        $c = self::$classes[$cls] ?? null;
        while ($c !== null) {
            if (isset($c->methodNames[$method])) { return $c->name; }
            $c = $c->parent !== '' ? (self::$classes[$c->parent] ?? null) : null;
        }
        return '';
    }

    /** @param array<string, array<string, bool>> $reads */
    private static function readMethod(string $cls, string $method, string $fn, array &$reads): void
    {
        $subs = self::$subtypes[$cls] ?? null;
        if ($cls === '' || $subs === null) { $reads['rm:' . $method][$fn] = true; return; }
        foreach ($subs as $sub) {
            $o = self::owner($sub, $method);
            if ($o !== '') { $reads['r:' . $o . '__' . $method][$fn] = true; }
        }
    }

    private static function objClass(Node $n): string
    {
        return $n->type->kind === Type::KIND_OBJ ? ($n->type->class ?? '') : '';
    }

    private static function asCall(Node $n): Call { return $n; }
    private static function asMethodCall(Node $n): MethodCall_ { return $n; }
    private static function asStaticCall(Node $n): StaticCall_ { return $n; }
    private static function asNew(Node $n): NewObj { return $n; }
    private static function asPropRead(Node $n): PropertyAccess_ { return $n; }
    private static function asPropWrite(Node $n): StoreProperty { return $n; }

    /** @param array<string, array<string, bool>> $reads */
    private static function collect(Node $n, string $fn, array &$reads): void
    {
        self::$collectBody .= $n->kind . ':' . $n->type->exactString() . "\n";
        self::$collectShape .= $n->kind . "\n";
        $k = $n->kind;
        if ($k === Node::KIND_CALL) {
            $reads['r:' . self::asCall($n)->function][$fn] = true;
        } elseif ($k === Node::KIND_METHOD_CALL) {
            $mc = self::asMethodCall($n);
            self::readMethod(self::objClass($mc->object), $mc->method, $fn, $reads);
        } elseif ($k === Node::KIND_STATIC_CALL) {
            $sc = self::asStaticCall($n);
            self::readMethod($sc->class, $sc->method, $fn, $reads);
        } elseif ($k === Node::KIND_NEW_OBJ) {
            self::readMethod(self::asNew($n)->class, '__construct', $fn, $reads);
        } elseif ($k === Node::KIND_PROPERTY_ACCESS) {
            $pr = self::asPropRead($n);
            self::readProp(self::objClass($pr->object), $pr->property, $fn, $reads);
        } elseif ($k === Node::KIND_STORE_PROPERTY) {
            $pw = self::asPropWrite($n);
            self::readProp(self::objClass($pw->object), $pw->property, $fn, $reads);
        }
        foreach (Walk::children($n) as $c) { self::collect($c, $fn, $reads); }
    }

    /** @param array<string, array<string, bool>> $reads */
    private static function readProp(string $cls, string $prop, string $fn, array &$reads): void
    {
        if ($cls === '') { $reads['fw:' . $prop][$fn] = true; return; }
        $reads['f:' . $cls . '::' . $prop][$fn] = true;
    }
}
