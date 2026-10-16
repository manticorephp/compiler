<?php
// `/** @var T $p */` names $p; it must not type the local the next statement assigns.
class Param
{
    public function __construct(public string $name) {}
    public function __toString(): string { return 'Param(' . $this->name . ')'; }
}

function typeOf(Param $p): ?string { return $p->name === 'a' ? null : 'int'; }

$out = [];
foreach ([new Param('a'), new Param('b')] as $p) {
    /** @var Param $p */
    $type = ltrim(typeOf($p) ?? '', '?');
    $out[] = $type . ' $' . $p->name;
}
echo implode(', ', $out), "\n";

/** @var string */
$s = 'unnamed ' . 'still applies';
echo $s, "\n";
