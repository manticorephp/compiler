<?php

/**
 * Blocking-offload pool. The codegen builtin of the same name starts one native
 * worker thread (IR, EmitLlvmModule::offloadRuntime) — this body is what a compiler
 * that predates the builtin links, and "-1" makes every caller run inline.
 */
function __mc_pool_start(int $submitFd, int $doneFd): int
{
    return -1;
}
