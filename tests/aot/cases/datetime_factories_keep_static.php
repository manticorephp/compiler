<?php
// DateTime / DateTimeImmutable factories return `static`, also through `parent::` from a subclass.
class MyDT extends DateTime {
    public $tag = 'my';
    public static function viaParent(DateTimeInterface $d) { return parent::createFromFormat('U.u', $d->format('U.u')); }
}
class MyImm extends DateTimeImmutable {
    public $tag = 'imm';
    public static function viaParent(DateTimeInterface $d) { return parent::createFromFormat('U.u', $d->format('U.u')); }
}
$base = new DateTime('2026-01-01 00:00:00 UTC');
$imm = new DateTimeImmutable('2026-01-01 00:00:00 UTC');
foreach ([
    MyDT::createFromFormat('Y-m-d H:i:s', '2026-01-01 00:00:00'),
    MyDT::createFromInterface($base),
    MyDT::createFromImmutable($imm),
    MyDT::viaParent($base),
    MyImm::createFromFormat('Y-m-d H:i:s', '2026-01-01 00:00:00'),
    MyImm::createFromInterface($base),
    MyImm::createFromMutable($base),
    MyImm::viaParent($base),
    DateTime::createFromFormat('Y-m-d', '2026-01-01'),
    DateTimeImmutable::createFromFormat('Y-m-d', '2026-01-01'),
] as $d) {
    echo get_class($d), ' ', $d->format('Y'), isset($d->tag) ? ' ' . $d->tag : '', "\n";
}
