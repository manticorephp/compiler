<?php
// php 8.2+ DEPRECATES creating a dynamic property on a class without
// #[AllowDynamicProperties] — it does not refuse it. Without a bag slot the
// store went through an offset the class does not have: a SIGSEGV, and
// `isset($o->nope)` answered true.
//
// The oracle for this case is php with E_DEPRECATED silenced: php CLI prints
// the deprecation on STDOUT, and we do not emit one (that is a separate
// question — the value semantics are what this pins).

class DynPlain
{
    public int $x = 1;
    public string $s = 'a';
}

class DynChild extends DynPlain
{
    public int $y = 2;
}

$o = new DynPlain();
echo 'before=', var_export(isset($o->dyn), true), "\n";
$o->dyn = 5;
echo 'dyn=', $o->dyn, ' x=', $o->x, ' s=', $o->s, "\n";
echo 'after=', var_export(isset($o->dyn), true), ' other=', var_export(isset($o->nope), true), "\n";

// The declared slots must be untouched by the dynamic write.
$o->x = 9;
$o->dyn = 6;
echo 'x=', $o->x, ' dyn=', $o->dyn, "\n";

// get_object_vars and the array cast both see declared THEN dynamic.
echo json_encode(get_object_vars($o)), "\n";
echo json_encode((array)$o), "\n";

// A second dynamic name, and a string value.
$o->tag = 'hello';
echo $o->tag, ' ', json_encode(get_object_vars($o)), "\n";

// unset() removes it again.
unset($o->dyn);
echo 'unset=', var_export(isset($o->dyn), true), ' tag=', $o->tag, "\n";

// A subclass inherits the slot rather than writing into one of its own.
$c = new DynChild();
$c->extra = 'sub';
echo $c->x, ' ', $c->y, ' ', $c->extra, ' ', json_encode(get_object_vars($c)), "\n";

// A fresh instance carries nothing over.
$o2 = new DynPlain();
echo 'fresh=', var_export(isset($o2->dyn), true), ' ', json_encode(get_object_vars($o2)), "\n";
