<?php

namespace Semantic;

use Analyze\Index;
use Analyze\Unit;

/**
 * Variable types visible at an expression. `thisClass` is the ENCLOSING class even in a static method
 * (so `self::` resolves); `$this` is a variable only when `isStatic` is false.
 */
final class TypeEnv
{
    /**
     * @param array<string, SourceType> $vars name without `$` → type
     */
    public function __construct(
        public array $vars = [],
        public ?string $thisClass = null,
        public bool $isStatic = false,
        public ?string $parentClass = null,
        /** unreachable point (after return / throw / break): joins ignore it */
        public bool $dead = false,
    ) {}

    public function copy(): TypeEnv
    {
        return new TypeEnv($this->vars, $this->thisClass, $this->isStatic, $this->parentClass, $this->dead);
    }

    /**
     * Key under which a variable or a property path (`$a->b->c` => `a->b->c`) is tracked; null for anything
     * else. A nullsafe hop keys like a plain one.
     */
    public static function pathKey(\Parser\Ast\Expr $e): ?string
    {
        if ($e instanceof \Parser\Ast\Variable) { return $e->name; }
        if ($e instanceof \Parser\Ast\PropertyAccess) {
            $base = self::pathKey($e->object);
            return $base === null ? null : $base . '->' . $e->property;
        }
        return null;
    }

    /** Drop `$key` and every path below it. */
    public function forget(string $key): void
    {
        unset($this->vars[$key]);
        $this->forgetBelow($key);
    }

    /** Drop every path below `$key` (`$key->…`), keep `$key` itself. */
    public function forgetBelow(string $key): void
    {
        $prefix = $key . '->';
        foreach (\array_keys($this->vars) as $k) {
            if (\str_starts_with((string)$k, $prefix)) { unset($this->vars[$k]); }
        }
    }

    public static function forUnit(Unit $u, Index $idx): TypeEnv
    {
        $owner = $u->ownerClass !== '' ? $u->ownerClass : ($u->thisClass !== '' ? $u->thisClass : '');
        $env = new TypeEnv([], $owner === '' ? null : $owner, $owner !== '' && $u->thisClass === '', null);
        if ($owner !== '') {
            $ci = $idx->findClass($owner);
            if ($ci !== null && $ci->kind === 'class' && \count($ci->parents) > 0) { $env->parentClass = \ltrim($ci->parents[0], '\\'); }
            if (!$env->isStatic) { $env->vars['this'] = new SourceType(SourceType::CLASS_, $owner); }
        }
        foreach ($u->params as $p) {
            if ($p->variadic) { $env->vars[$p->name] = new SourceType(SourceType::SCALAR, 'array'); continue; }
            $native = SourceTypeParser::fromHint($p->typeHint, $owner);
            $doc = SourceTypeParser::fromHint($u->paramDocs[$p->name] ?? null, $owner);
            $t = ExprTyper::bind(SourceTypeParser::withDoc($native, $doc), $owner, $owner, $idx);
            if ($t !== null) { $env->vars[$p->name] = $t; }
        }
        return $env;
    }
}
