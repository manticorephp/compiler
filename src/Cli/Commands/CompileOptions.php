<?php

namespace Cli\Commands;

use Cli\ArgParse;
use Cli\Option;

/** The option set every front-end command shares (was `compile_arg_spec()` in Main.php). */
final class CompileOptions
{
    /** @return Option[] */
    public static function shared(): array
    {
        return [
            new Option('o', ArgParse::VALUE, 'Output path (default a.out)', 'path'),
            new Option('memory', ArgParse::VALUE, 'Memory mode: rc|arena|hybrid (default: $MANTICORE_MEMORY)', 'mode'),
            new Option('backend', ArgParse::VALUE, 'Codegen backend override', 'name'),
            new Option('O', ArgParse::VALUE, 'Optimisation level: 0|1|2|3|s|z (default 2)', 'level'),
            new Option('prelude', ArgParse::FLAG, 'dump-*: include the prelude in the dump'),
            new Option('effects', ArgParse::FLAG, 'dump-mir: show inferred effects'),
            new Option('emit-library', ArgParse::FLAG, 'Emit a library object instead of an executable'),
            new Option('runtime', ArgParse::FLAG, 'With --emit-library: this library is the bundled stdlib'),
            new Option('allow-undefined-traps', ArgParse::FLAG, 'Do not refuse a library that contains undefined-function traps'),
            new Option('keep-ir', ArgParse::FLAG, 'Keep the intermediate .ll files'),
            new Option('j', ArgParse::VALUE, 'Parallel clang jobs; 0 = one per core', 'n'),
        ];
    }
}
