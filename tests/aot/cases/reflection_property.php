<?php

class Point
{
    public int $x = 1;
    protected float $y = 2.5;
    private ?string $label = null;
    public readonly int $id;
    public static int $count = 7;

    public function __construct()
    {
        $this->id = 42;
    }
}

$p = new Point();
$rc = new ReflectionClass('Point');

foreach ($rc->getProperties() as $rp) {
    $vis = $rp->isPublic() ? 'pub' : ($rp->isProtected() ? 'prot' : 'priv');
    $type = $rp->hasType() ? $rp->getType()->getName() : '-';
    $null = ($rp->hasType() && $rp->getType()->allowsNull()) ? '1' : '0';
    echo $rp->getName(), ' vis=', $vis,
         ' static=', $rp->isStatic() ? '1' : '0',
         ' ro=', $rp->isReadonly() ? '1' : '0',
         ' type=', $type, ' null=', $null, "\n";
}

$rx = $rc->getProperty('x');
echo 'x=', $rx->getValue($p), "\n";
$rx->setValue($p, 99);
echo "x'=", $rx->getValue($p), "\n";

$ry = $rc->getProperty('y');
echo 'y=', $ry->getValue($p), "\n";
$ry->setValue($p, 3.5);
echo "y'=", $ry->getValue($p), "\n";

$rl = $rc->getProperty('label');
$lv = $rl->getValue($p);
echo 'label=', ($lv === null ? 'NULL' : $lv), "\n";
$rl->setValue($p, 'hi');
echo "label'=", $rl->getValue($p), "\n";

$rid = $rc->getProperty('id');
echo 'id=', $rid->getValue($p), ' ro=', $rid->isReadonly() ? '1' : '0', "\n";

$rcount = $rc->getProperty('count');
echo 'count static=', $rcount->isStatic() ? '1' : '0', ' val=', $rcount->getValue(), "\n";

echo 'mods x=', $rx->getModifiers(), ' label=', $rl->getModifiers(), ' count=', $rcount->getModifiers(), "\n";
