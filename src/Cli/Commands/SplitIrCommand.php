<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\ParsedArgs;

final class SplitIrCommand extends Command
{
    public function name(): string { return 'split-ir'; }

    public function summary(): string { return 'Split a staged .ll module into N part files (dev tool)'; }

    public function usage(): string { return '<module.ll> <parts> <outbase>'; }

    /** @return \Cli\Option[] */
    public function options(): array { return []; }

    public function run(ParsedArgs $args): int
    {
        $a = $args->positional;
        if (\count($a) < 3) {
            \Manticore\dprint('usage: split-ir <module.ll> <parts> <outbase>');
            return 64;
        }
        $in = $a[0];
        $parts = (int)$a[1];
        $base = $a[2];
        if (!\file_exists($in)) {
            \Manticore\dprint('split-ir: no such file ' . $in);
            return 66;
        }
        $t = \Compile\Stats::now();
        $splitter = new \Compile\Mir\SplitModule();
        $out = $splitter->runFile($in, $parts, $base);
        if ($out === []) {
            \Manticore\dprint('split-ir: split produced no parts');
            return 65;
        }
        $total = 0;
        foreach ($out as $i => $p) {
            $sz = (int)\filesize($p);
            $total = $total + $sz;
            \Manticore\puts('  p' . (string)$i . '  ' . (string)\intdiv($sz, 1048576) . ' MB');
        }
        $src = (int)\filesize($in);
        \Manticore\puts(
            'split-ir: ' . (string)\count($out) . ' parts  shared=' . (string)$splitter->sharedDefs
            . ' internal=' . (string)$splitter->internalDefs
        );
        \Manticore\puts(
            'split-ir: module ' . (string)\intdiv($src, 1048576) . ' MB -> parts '
            . (string)\intdiv($total, 1048576) . ' MB  (x'
            . (string)(\round($total / ($src > 0 ? $src : 1), 3)) . ')'
        );
        \Compile\Stats::step('split-ir', $t, -1, -1);
        return 0;
    }
}
