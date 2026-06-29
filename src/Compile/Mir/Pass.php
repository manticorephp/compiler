<?php

namespace Compile\Mir;

/**
 * Pass interface — every analysis, transform, and lowering step
 * follows this contract.
 *
 * `requires()` lists pass names whose effects this pass depends on.
 * The driver asserts those have run before calling `run()`.
 */
interface Pass
{
    public function name(): string;

    /** @return string[] */
    public function requires(): array;

    public function run(Module $module): Module;
}
