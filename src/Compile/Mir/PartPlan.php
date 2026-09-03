<?php

declare(strict_types=1);

namespace Compile\Mir;

/**
 * What one split part contains, decided without touching definition bodies.
 *
 * {@see SplitModule::planPart} answers the load-bearing questions — which
 * symbols this part owns (its assigned shared definitions plus every file-local
 * one reachable from them, to a fixpoint), which globals it must carry and in
 * what linkage, and what it has to declare because another part defines it.
 * None of that needs the body text, only each definition's header line and the
 * set of symbols it names.
 *
 * Keeping the answer in a typed object rather than a heterogeneous array is
 * deliberate: a bare `array` return erases its element type across a delegation
 * hop, and this value crosses one.
 */
final class PartPlan
{
    /**
     * Symbols whose full definition text this part emits, in module order.
     *
     * @var string[]
     */
    public array $mine = [];

    /** Global definition lines, already rewritten to this part's linkage. */
    public string $gtext = '';

    /** `declare` lines for what this part calls but another part defines. */
    public string $dtext = '';

    /** The `@llvm.compiler.used` anchor pinning this part's linkonce_odr bodies. */
    public string $usedText = '';
}
