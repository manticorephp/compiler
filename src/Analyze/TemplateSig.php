<?php

namespace Analyze;

/**
 * The generic part of a function or method signature: its own `@template` names (name => qualified upper
 * bound, '' for none), every parameter name in order, and the qualified docblock text of the params and
 * the return that mention a template. Null parts do not mention one (or name a class-level template).
 */
final class TemplateSig
{
    /**
     * @param array<string, string> $templates
     * @param string[] $order
     * @param array<string, string> $params
     */
    public function __construct(
        public array $templates,
        public array $order,
        public array $params,
        public ?string $ret,
    ) {}
}
