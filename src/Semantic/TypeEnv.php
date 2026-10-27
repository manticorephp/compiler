<?php

namespace Semantic;

use Analyze\DocType;
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
    ) {}

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
            $doc = SourceTypeParser::fromHint(DocType::tag($u->docComment, '@param', $p->name), $owner);
            $t = ExprTyper::bind(SourceTypeParser::withDoc($native, $doc), $owner, $owner, $idx);
            if ($t !== null) { $env->vars[$p->name] = $t; }
        }
        return $env;
    }
}
