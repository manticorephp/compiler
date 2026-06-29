<?php

namespace Manticore\Attr;

/**
 * Mark a class as a value-type struct: no per-instance class-id
 * header, fields start at offset 0, dispatch is statically resolved.
 *
 *   #[Manticore\Attr\Struct]
 *   final class Span {
 *       public function __construct(
 *           public int $line,
 *           public int $col,
 *       ) {}
 *   }
 *
 * Constraints (compile-time enforced):
 *   - no `extends` (no polymorphism → no class id needed)
 *   - no `abstract` methods (must be statically dispatched)
 *   - can be `final` (recommended); `extends` from a Struct rejects
 *
 * Pays off for AST nodes, span markers, small value records — every
 * instance is 8 bytes smaller and every field access is one offset
 * closer to the base ptr.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Struct
{
}
