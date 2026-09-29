<?php
// An array local rebound to null / a scalar gave its array back only if every
// owned store was a copy: a fresh literal leaked, and every object in it kept
// its destructor until exit.
final class T { public function __construct(public string $n) {} public function __destruct() { echo "dtor {$this->n}\n"; } }
function toNull(): void { $l = [new T('null')]; $l = null; echo "after null\n"; }
function aliased(): void { $d = new T('aliased'); $l = [$d]; $l = null; $d = null; echo "after aliased\n"; }
function mixed_(string $m): void {
    if ($m === 'a') { $t = [new T('mix-arr')]; } elseif ($m === 'o') { $t = new T('mix-obj'); } else { $t = null; }
    echo "end ", $m, "\n";
}
toNull(); aliased(); mixed_('a'); mixed_('o'); mixed_('n');
echo "end\n";
