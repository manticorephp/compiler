<?php

namespace Semantic;

use Analyze\Index;
use Analyze\MemberInfo;
use Parser\Ast\Expr;
use Parser\Ast\MethodCallExpr;
use Parser\Ast\PropertyAccess;
use Parser\Ast\StaticAccess;
use Parser\Ast\StaticCall;

/**
 * Member use site -> the declarations it can denote. The receiver is the PropertyAccess / MethodCallExpr /
 * StaticCall / StaticAccess node whose member name sits at the occurrence; its class set comes from
 * {@see Flow} + {@see ExprTyper}. A union receiver yields one declaration per class (deduplicated);
 * when any class lacks the member, or the receiver type is unknown, the site is unresolved (null).
 */
final class MemberResolver
{
    public function __construct(private Index $idx) {}

    /** @return MemberInfo[]|null */
    public function resolve(string $uri, NameOccurrence $site, ExprAt $at, UnitLookup $units): ?array
    {
        if ($site->kind !== 'member' || $site->isDecl) { return null; }
        $name = \ltrim($site->resolved, '$');
        $node = null;
        foreach (\array_reverse($at->chain($site->line, $site->col)) as $n) {
            if (self::namesMember($n, $site->role, $name)) { $node = $n; break; }
        }
        if ($node === null) { return null; }
        $env = $units->envAt($node, $this->idx, $uri);
        $typer = new ExprTyper($this->idx, $uri);
        if ($node instanceof StaticCall || $node instanceof StaticAccess) {
            $c = $typer->classRef($node->class, $env);
            $classes = $c === null ? null : [$c];
        } else {
            $recv = $node instanceof PropertyAccess || $node instanceof MethodCallExpr ? $typer->typeOf($node->object, $env) : null;
            $classes = $typer->classesOf($recv, $env);
        }
        if ($classes === null) { return null; }
        $kind = $site->role === 'method' ? 'method' : ($site->role === 'const' ? 'const' : 'prop');
        /** @var MemberInfo[] $out */
        $out = [];
        /** @var array<string, bool> $seen */
        $seen = [];
        foreach ($classes as $cls) {
            $m = $this->idx->lookupMember($cls, $name, $kind);
            if ($m === null) { return null; }
            // `$x->sp` on a static property (or `A::$n` on an instance one) is a different namespace: left unresolved, so it is listed as uncertain
            if ($kind === 'prop' && ($site->role === 'sprop') !== $m->isStatic) { return null; }
            $k = self::key($m);
            if (!isset($seen[$k])) { $seen[$k] = true; $out[] = $m; }
        }
        return $out;
    }

    public static function key(MemberInfo $m): string
    {
        return $m->kind . ':' . \strtolower($m->declClass) . '::' . ($m->kind === 'method' ? \strtolower($m->name) : $m->name) . '@' . $m->file . ':' . $m->line . ':' . $m->col;
    }

    private static function namesMember(Expr $n, string $role, string $name): bool
    {
        if ($n instanceof PropertyAccess) { return $role === 'prop' && $n->property === $name; }
        if ($n instanceof MethodCallExpr) { return $role === 'method' && \strtolower($n->method) === \strtolower($name); }
        if ($n instanceof StaticCall) { return $role === 'method' && \strtolower($n->method) === \strtolower($name); }
        if ($n instanceof StaticAccess) {
            if ($role === 'sprop') { return \ltrim($n->name, '$') === $name && ($n->name[0] ?? '') === '$'; }
            return $role === 'const' && $n->name === $name;
        }
        return false;
    }
}
