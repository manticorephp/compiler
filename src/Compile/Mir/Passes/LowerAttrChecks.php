<?php

declare(strict_types=1);

namespace Compile\Mir\Passes;

/**
 * Attribute-name resolution and the PHP reserved-attribute checks, mixed into
 * `LowerFromAst`.
 *
 * `AttributeNode::$name` is ALREADY a fully-qualified name: the parser routes
 * attribute names through `parseClassName()` → `resolveClassName()`, which
 * strips a leading `\`, expands `use X as Y` aliases, and prepends the current
 * namespace. The only thing missing was one shared accessor — every consumer
 * used to hand-roll `ltrim($attr->name, '\\')` off an untyped loop variable,
 * which is a T5 offset hazard under self-host.
 */
trait LowerAttrChecks
{
    /** Canonical attribute-class name: FQN, no leading backslash. Read through
     *  a typed param — a base-typed `->name` resolves by OFFSET under self-host. */
    private function attrFqn(\Parser\Ast\AttributeNode $a): string
    {
        return \ltrim($a->name, '\\');
    }

    /** An attribute's argument list, likewise through a typed param.
     *  @return \Parser\Ast\Expr[] */
    private function attrArgs(\Parser\Ast\AttributeNode $a): array
    {
        return $a->args;
    }

    /**
     * Whether an attribute names one of `$accepted` (each already canonical).
     * The compiler's own markers accept a bare, an `Attr\` and a
     * `Manticore\Attr\` spelling because `src/` is parsed both with and without
     * namespaces across the seed and self-host paths.
     *
     * @param string[] $accepted
     */
    private function attrIsOneOf(\Parser\Ast\AttributeNode $a, array $accepted): bool
    {
        $name = $this->attrFqn($a);
        foreach ($accepted as $want) {
            if ($name === $want) { return true; }
        }
        return false;
    }

    /**
     * The PHP reserved attribute this occurrence names, or '' when it names
     * none. Global namespace only: a `Foo\Override` is an unrelated userland
     * attribute and must never be validated against the reserved rules.
     */
    private function reservedAttr(\Parser\Ast\AttributeNode $a): string
    {
        $name = $this->attrFqn($a);
        if (\strpos($name, '\\') !== false) { return ''; }
        return \Compile\BuiltinAttributes::isReserved($name) ? $name : '';
    }
}
