<?php

namespace Compile\Mir;

/**
 * MIR enum descriptor. Cases are modelled as ordinals (0..n-1); the
 * name + backing-value tables are emitted as module globals and
 * indexed by ordinal at `->name` / `->value` sites. Identity (`===`)
 * and `match` reduce to ordinal integer compares.
 *
 * Backing-value arrays are kept as plain scalar lists (int[]/string[])
 * aligned with `$caseNames` — not a map of objects — so the self-host
 * backend iterates them safely.
 */
final class EnumDef
{
    /**
     * @param string[] $caseNames ordered case names (ordinal = index)
     * @param int[]    $intValues backed-int value per case ('' backing → empty)
     * @param string[] $strValues backed-string value per case
     */
    public function __construct(
        public string $name,
        public array $caseNames,
        public string $backing,
        public array $intValues = [],
        public array $strValues = [],
    ) {}

    /** Ordinal of `$case`, or -1 if unknown. */
    public function ordinalOf(string $case): int
    {
        $i = 0;
        foreach ($this->caseNames as $n) {
            if ($n === $case) { return $i; }
            $i = $i + 1;
        }
        return -1;
    }
}
