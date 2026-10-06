<?php

namespace Compile\Mir;

/**
 * One by-ref foreach that walks its base LIVE ({@see Passes\EmitLlvmControl}):
 * `$v` is a copy of the element, written back by key. Every body statement
 * that names `$v` writes it back too, so the element never names a buffer
 * `$v` has since relocated (an unset promotes a packed buffer and frees the
 * old one) — the body may reach that element through another path.
 *
 * `$liveSlot` holds the base buffer, null while no array arm walks it (an
 * erased base's generator arm shares the body); `$liveKey` the current key.
 */
final class LiveByRefLoop
{
    public function __construct(
        public Foreach_ $fe,
        public string $fnName,
        public string $liveSlot,
        public string $liveKey,
        public string $feFlag,
    ) {}
}
