<?php

use Acme\Boxed;
use Acme\Circle;
use Acme\Color;
use Acme\Point;
use Acme\Shape;

$p = new Point(2, 3);
echo $p->sum(), "\n";
echo $p->label(), "\n";
echo Point::NAME, ' ', Point::LABEL, "\n";
echo Point::ORIGIN[0], Point::ORIGIN[1], "\n";

$q = new Point(10, 20);
echo $q->sum(), ' ', Point::made(), ' ', Point::$count, "\n";

$c = new Circle(4);
echo $c->name(), ' ', $c->area(), "\n";
var_dump($c instanceof Shape);
echo Shape::SIDES_UNKNOWN, "\n";

$b = new Boxed();
echo $b->grow(5), ' ', $b->unit(), "\n";
var_dump($b instanceof Acme\Sized);

echo Color::Red->value, ' ', Color::Green->loud(), "\n";
echo Color::from('red')->name, "\n";

echo ACME_VERSION, ' ', ACME_LIMIT, "\n";

// Object services over an IMPORTED receiver. These work because the importing
// module holds the imported ClassDef in its own class table, so the generated
// `__mir_dump_object` / `get_object_vars` arms cover it.
//
// `json_encode($p)` is deliberately NOT here: it encodes through
// `__mc_json_enc`, which lives in `manticore_stdlib.o` — a separate module whose
// class table is empty — so it answers `{}` for EVERY object, imported or local.
// That gap predates this feature (see docs/ROADMAP.md); testing it here would
// make this gate red for something it does not measure.
echo get_class($p), ' ', get_class($c), "\n";
var_dump($p);
var_dump($b);
print_r(get_object_vars($p));
echo "\n";
