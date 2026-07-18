<?php

namespace Analyze;

/**
 * Sink handed to {@see \Manticore\lower_module} in ANALYSIS mode: the compiler's
 * own MIR passes (`TypeCheck`, `CheckTypeDefs`) push their findings here instead
 * of aborting the build. The analyzer then maps these lines to {@see Diagnostic}s.
 *
 * This is the no-duplication seam — the repr-soundness family is not a
 * reimplementation of the compiler's erasure logic, it IS the compiler's passes,
 * driven read-only. Any future MIR check that writes here surfaces automatically.
 */
final class MirDiags
{
    /** @var string[] raw finding strings from the MIR passes */
    public array $lines = [];
}
