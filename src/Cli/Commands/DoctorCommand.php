<?php

namespace Cli\Commands;

use Cli\ArgParse;
use Cli\Command;
use Cli\Doctor\Checks;
use Cli\Doctor\Result;
use Cli\Option;
use Cli\ParsedArgs;

final class DoctorCommand extends Command
{
    public function name(): string { return 'doctor'; }

    public function summary(): string { return 'Check that this compiler and its toolchain work; exit 1 on a failure'; }

    public function usage(): string { return '[--offline] [--no-smoke]'; }

    /** @return Option[] */
    public function options(): array
    {
        return [
            new Option('offline', ArgParse::FLAG, 'Skip the update check'),
            new Option('no-smoke', ArgParse::FLAG, 'Skip compiling and running a hello-world'),
        ];
    }

    public function run(ParsedArgs $args): int
    {
        /** @var Result[] $results */
        $results = [Checks::compiler(), Checks::install(), Checks::clang(), Checks::cc()];
        $results = \array_merge($results, Checks::assets(), Checks::libs());
        if (!$args->flag('no-smoke')) {
            $results[] = Checks::smoke();
        }
        $results[] = Checks::update($args->flag('offline'));
        $ok = 0;
        $warn = 0;
        $fail = 0;
        foreach ($results as $r) {
            $label = ' OK ';
            if ($r->status === Result::WARN) {
                $label = 'WARN';
                $warn++;
            } elseif ($r->status === Result::FAIL) {
                $label = 'FAIL';
                $fail++;
            } else {
                $ok++;
            }
            \Manticore\puts('[' . $label . '] ' . $r->name . ' — ' . $r->detail);
            if ($r->hint !== '' && $r->status !== Result::OK) {
                \Manticore\puts('       hint: ' . $r->hint);
            }
        }
        \Manticore\puts('');
        \Manticore\puts($ok . ' ok, ' . $warn . ' warnings, ' . $fail . ' failures');
        return $fail > 0 ? 1 : 0;
    }
}
