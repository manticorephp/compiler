<?php
/**
 * An array property whose ELEMENT is itself an array — `array<string, P[]>`.
 *
 *   bin/manticore compile tools/prof/nested_prop.php -o /tmp/nested
 *   /tmp/nested nested 40000   vs   /tmp/nested flat 40000
 *
 * `flat` is the paired control: the same class, the same store, the same object
 * lifetime, one level shallower. php 8.5 is flat for both, so the oracle is
 * free: `php tools/prof/nested_prop.php nested 40000`.
 *
 * The store retains an inner array with `__mir_array_retain_obj` — it is an
 * ARRAY, and that is the array retain. The DROP had no name for "one array
 * release per element": the flavor vocabulary dispatched on the element KIND and
 * an ARRAY element matched no arm, so the slot fell through to the buffer-only
 * `vec`/`assoc` and every inner array leaked whole, with everything in it.
 * 155.7 MB against the control's 1.2 before the `vecarr` family.
 *
 * ⚠ The deep flavor is claimed ONLY on a LOCAL SLOT drop ({@see
 * \Compile\Mir\Passes\EmitLlvmMemory::rcReleaseFlavorPlain}), where the slot is
 * the buffer's sole owner. Claiming it in `discardReleaseFlavor` — which also
 * answers for PROPERTIES, call arguments and the erased repr path — over-releases:
 * 20 array_ cases and a gen-3 abort.
 */
final class P { public function __construct(public readonly string $n) {} }

final class Holder {
    /** @var array<string, P[]> name -> params */
    private array $nested = [];
    /** @var array<string, P> name -> one param */
    private array $flat = [];
    /** @param P[] $ps */
    public function addNested(string $k, array $ps): void { $this->nested[$k] = $ps; }
    public function addFlat(string $k, P $p): void { $this->flat[$k] = $p; }
    public function count_(): int { return count($this->nested) + count($this->flat); }
}

function main(string $mode, int $iters): int {
    $acc = 0;
    for ($k = 0; $k < $iters; $k++) {
        $h = new Holder();
        for ($i = 0; $i < 8; $i++) {
            $key = 'k' . (string)$i;
            if ($mode === 'nested') {
                $ps = [];
                for ($j = 0; $j < 4; $j++) { $ps[] = new P('p' . (string)$j); }
                $h->addNested($key, $ps);
            } else {
                $h->addFlat($key, new P('p'));
            }
        }
        $acc = $acc + $h->count_();
        $h = null;
    }
    return $acc;
}

echo main($argv[1] ?? 'nested', (int)($argv[2] ?? 1000)), "\n";
