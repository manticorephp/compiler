<?php
// A cell-element array bound to a `string[]` param / property. `array_keys()`
// answers boxed cells, and every consumer that trusts the DECLARED element type
// then walked tagged words as raw `char*`: the rc retain faulted in
// `__mir_array_retain_str`, and implode inttoptr'd the NaN tag.
//
// The array records what its slots actually hold (ARRAY_ELEM_HINT_*), so the
// retain / release pair and the join ask it instead of guessing. symfony:
// `new CommandNotFoundException($msg, array_values($alternatives))`, which is
// what `./app <unknown-command>` runs into.

class Alternatives
{
    /** @var string[] */
    private array $alts;

    /** @param string[] $alts */
    public function __construct(array $alts = []) { $this->alts = $alts; }

    /** @return string[] */
    public function get(): array { return $this->alts; }
}

/** @param string[] $names */
function firstMatching(array $names, string $needle): string
{
    foreach ($names as $n) {
        if (str_contains($n, $needle)) { return $n; }
    }
    return '';
}

$m = ['config:set' => 1, 'list:items' => 2, 'greet' => 3];
$keys = array_keys($m);

$h = new Alternatives(array_values($keys));
echo implode(',', $h->get()), "\n";
echo count($h->get()), "\n";
echo firstMatching($h->get(), 'list'), "\n";

// The ctor default keeps the empty case honest.
$empty = new Alternatives();
echo count($empty->get()), "\n";
echo '[', implode(',', $empty->get()), "]\n";

// A concrete string vec must keep the raw path — the hint says so.
$raw = ['a', 'b', 'c'];
echo implode('-', $raw), "\n";
echo firstMatching($raw, 'b'), "\n";

// The same array reaching a second owner: the retain and the release have to
// read the SAME nibble, or this leaks (harmless) or double-frees (fatal).
$copy = $h->get();
$again = $copy;
echo implode('|', $again), "\n";
unset($copy);
echo implode('|', $h->get()), "\n";
echo "done\n";
