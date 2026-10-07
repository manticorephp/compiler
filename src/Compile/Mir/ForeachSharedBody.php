<?php

namespace Compile\Mir;

/**
 * One erased-base foreach whose runtime arms (generator, Traversable object,
 * array) share a SINGLE copy of the loop body ({@see Passes\EmitLlvmControl}).
 * Each arm records its own step / end label and enters the body with its index
 * in `$armSlot`; `continue`, the body's fall-through and `break` dispatch back
 * on it.
 */
final class ForeachSharedBody
{
    /** @var string[] step label of each arm, by arm index */
    public array $steps = [];
    /** @var string[] end label of each arm, by arm index */
    public array $ends = [];
    public bool $bodyEmitted = false;
    /** The Traversable arm's iterator slot and its owned flag (i1), allocated
     *  before the arms so the shared body can give getIterator()'s result back
     *  on a `return` / `break N` whichever arm emitted it; '' when none. */
    public string $aggIterSlot = '';
    public string $aggOwnSlot = '';

    public function __construct(
        public Foreach_ $fe,
        public string $armSlot,
        public string $bodyLabel,
        public string $contLabel,
        public string $brkLabel,
    ) {}
}
