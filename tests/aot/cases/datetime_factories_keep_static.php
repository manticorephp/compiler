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

// An internal factory builds the called class WITHOUT running its constructor; a constructor that
// itself calls the factory (Carbon's does) must not recurse.
class CtorDT extends DateTime {
    public static int $ctors = 0;
    public ?string $clock = null;
    public function __construct(string $t = 'now', ?DateTimeZone $z = null) {
        self::$ctors++;
        parent::__construct($t, $z);
        $x = static::createFromFormat('U', '0');
    }
    public static function viaParent(DateTimeInterface $d) { return parent::createFromFormat('U.u', $d->format('U.u')); }
}
$a = CtorDT::createFromFormat('Y-m-d', '2026-01-01');
$b = CtorDT::viaParent($base);
echo get_class($a), ' ', get_class($b), ' ctors=', CtorDT::$ctors, ' ', var_export($a->clock, true), ' ', $a->format('Y'), "\n";
