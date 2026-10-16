<?php
// ReflectionProperty / ReflectionClassConstant / enum-case members derivable without new metadata.
enum Suit: string { case Hearts = 'h'; case Spades = 's'; const Wild = self::Spades; }
class Ctr
{
    public int $pub = 1;
    protected string $prot = 'p';
    private array $priv = [];
    public static int $stat = 0;
    public readonly int $ro;
    public const A = 1;
    protected const B = 2;
    final public const C = 3;
    public function __construct() { $this->ro = 5; }
}
$names = ['pub', 'prot', 'priv', 'stat', 'ro'];
foreach ($names as $n) {
    $p = new ReflectionProperty(Ctr::class, $n);
    echo $n, ': ', var_export($p->isDefault(), true), ' ', var_export($p->isDynamic(), true), ' ',
        var_export($p->isAbstract(), true), ' ', var_export($p->isFinal(), true), ' ',
        str_replace("\0", '~', $p->getMangledName()), "\n";
}
$o = new Ctr();
var_dump((new ReflectionProperty(Ctr::class, 'pub'))->isLazy($o));
$pp = new ReflectionProperty(Ctr::class, 'pub');
$pp->setRawValueWithoutLazyInitialization($o, 77);
var_dump($o->pub);

foreach (['A', 'B', 'C'] as $k) {
    $c = new ReflectionClassConstant(Ctr::class, $k);
    echo $k, ': ', var_export($c->isEnumCase(), true), ' ', var_export($c->isDeprecated(), true), "\n";
}
$w = new ReflectionClassConstant(Suit::class, 'Hearts');
var_dump($w->isEnumCase());
var_dump((new ReflectionClassConstant(Suit::class, 'Wild'))->isEnumCase());

$u = new ReflectionEnumUnitCase(Suit::class, 'Hearts');
var_dump($u->getEnum() instanceof ReflectionEnum, $u->getEnum()->getName());
var_dump($u->isPublic(), $u->isPrivate(), $u->isProtected(), $u->isFinal(), $u->getModifiers(), $u->isEnumCase(), $u->isDeprecated());
$b = new ReflectionEnumBackedCase(Suit::class, 'Spades');
var_dump($b->getEnum()->getName(), $b->isPublic(), $b->isEnumCase(), $b->getBackingValue());
