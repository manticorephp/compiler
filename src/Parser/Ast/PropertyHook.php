<?php

namespace Parser\Ast;

/**
 * A PHP 8.4 property hook: `get`/`set` accessor attached to a property
 * declaration. Either an arrow body (`get => expr;`) OR a block body
 * (`get { ... }`). A `set` hook may declare its value parameter
 * (`set(string $v) => ...`); otherwise the value is the implicit `$value`.
 */
final class PropertyHook
{
    public function __construct(
        public readonly string $kind,        // 'get' | 'set'
        public readonly ?string $paramName,  // set value-param name; null → 'value'
        public readonly ?string $paramType,  // set value-param type hint
        public readonly ?Expr $exprBody,     // `=> expr`
        public readonly ?Block $blockBody,   // `{ ... }`
    ) {}
}
