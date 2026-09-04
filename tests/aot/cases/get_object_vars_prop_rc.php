<?php
// get_object_vars() must not consume a reference to the properties it reports:
// the map it returns owns its elements, so the receiver's own properties stay
// alive. Without the retain the object property is freed under its owner and
// the last echo prints an empty string.
class Inner { public string $v = 'deep'; }
class Outer {
    public ?Inner $in = null;
    public string $s = 'hi';
    /** @var string[] */
    public array $tags = [];
}

$o = new Outer();
$o->in = new Inner();
$o->tags = ['a', 'b'];

for ($i = 0; $i < 5; $i++) {
    $vars = get_object_vars($o);
    unset($vars);
}
echo $o->in->v, "\n";
echo $o->s, "\n";
echo count($o->tags), $o->tags[0], $o->tags[1], "\n";

$cast = (array)$o;
$cast2 = (array)$o;
$cast3 = (array)$o;
unset($cast, $cast2, $cast3);
echo $o->in->v, "\n";
