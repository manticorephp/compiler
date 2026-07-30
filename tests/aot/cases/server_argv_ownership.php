<?php
// Two bugs that together made every console app run its DEFAULT command.
//
// 1. Boxing a named LOCAL array into a value that outlives the frame does not
//    co-own it, so the local's scope-exit release freed every element. That is
//    how $_SERVER['argv'] was built: argv[0] survived because PHP_SELF had
//    retained it separately, and argv[1..] came back EMPTY.
// 2. array_shift/array_pop on a CELL base inttoptr'd the TAGGED word instead of
//    stripping the tag, and walked a wild address.

/** The shape __mc_server used: a local string[] boxed into a mixed map. */
function buildBad(): array
{
    $items = ['zero', 'one', 'two'];
    $first = $items[0];
    /** @var array<string, mixed> $out */
    $out = [];
    $out['self'] = $first;
    $out['items'] = \__mir_to_cell($items);
    return $out;
}
$m = buildBad();
$items = $m['items'];
echo 'count=', \count($items), " [", \implode('|', $items), "]\n";

// array_shift / array_pop over a CELL container (value read from a mixed map).
$a = buildBad()['items'];
$head = \array_shift($a);
echo 'shift=', \var_export($head, true), ' left=', \count($a), ' [', \implode('|', $a), "]\n";
$b = buildBad()['items'];
$tail = \array_pop($b);
echo 'pop=', \var_export($tail, true), ' left=', \count($b), ' [', \implode('|', $b), "]\n";

// NOTE: each container comes from a FRESH build — reading one array out of a
// cell slot twice currently ALIASES rather than copying (php value semantics),
// which is a separate gap and not what this case is about.
// $_SERVER['argv'] itself: element 0 must be a real, non-empty string, and
// argc must agree with the array (the runner passes no extra arguments).
$argv = $_SERVER['argv'] ?? [];
echo 'argv_is_array=', (\is_array($argv) ? 'Y' : 'n'),
     ' argv0_nonempty=', (\strlen((string) ($argv[0] ?? '')) > 0 ? 'Y' : 'n'),
     ' argc_matches=', ($_SERVER['argc'] === \count($argv) ? 'Y' : 'n'), "\n";
