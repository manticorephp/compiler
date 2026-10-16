<?php

namespace Cli\Commands;

use Cli\ArgParse;
use Cli\Option;

/** The progress/timing options `compile` and `build` share. */
final class ProgressOptions
{
    /** @return Option[] */
    public static function all(): array
    {
        return [
            new Option('progress', ArgParse::VALUE, 'Progress output: auto|live|plain|json|off (default auto)', 'mode'),
            new Option('quiet', ArgParse::FLAG, 'Silence progress and timings (diagnostics stay)'),
            new Option('timings', ArgParse::FLAG, 'Print a per-stage time table at the end'),
            new Option('color', ArgParse::VALUE, 'Colour: auto|always|never (default auto)', 'when'),
        ];
    }
}
