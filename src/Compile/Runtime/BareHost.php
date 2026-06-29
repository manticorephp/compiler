<?php

namespace Compile\Runtime;

use Codegen\Llvm\Block;
use Codegen\Llvm\FunctionDef;
use Codegen\Llvm\Type;
use Codegen\Llvm\Value;

/**
 * Minimal {@see RuntimeHost} for the MIR backend: plain libc
 * `malloc` / `realloc`, no arena, no rc-trace, no profile counters,
 * no verify probes. Owns a private label counter so emitted block
 * names stay unique within the module.
 *
 * The caller (MIR EmitLlvm) is responsible for declaring `malloc`
 * and `realloc` in the module before invoking a runtime emitter,
 * exactly as the AST trait does.
 */
final class BareHost implements RuntimeHost
{
    private int $labelCounter = 0;

    public function rtAlloc(Block $b, Value $size, string $flavor): Value
    {
        // Assoc buffers are tag-allocated (ASSOC_TAG_MAGIC at ptr-8) so the
        // rc helpers can self-route and a misrouted vec can't corrupt the rc.
        return $b->call('__mir_alloc_assoc_tagged', Type::ptr(), [$size]);
    }

    public function rtRealloc(Block $b, Value $oldPtr, Value $oldSize, Value $newSize): Value
    {
        // Tagged realloc shifts to the base (ptr-8); the tag rides along in
        // the copied bytes. Shared with the vec grow path.
        return $b->call('__mir_realloc_tagged', Type::ptr(), [$oldPtr, $newSize]);
    }

    public function rtArenaBypass(FunctionDef $fn, Block $entry, Value $ptr, ?Value $ret): Block
    {
        return $entry;
    }

    public function rtFreshLabel(string $hint): string
    {
        $this->labelCounter = $this->labelCounter + 1;
        return $hint . '_' . (string)$this->labelCounter;
    }

    public function rtDebugDprintf(Block $b, string $fmt, array $args): void
    {
    }

    public function rtProfileBump(Block $b, string $counter): void
    {
    }
}
