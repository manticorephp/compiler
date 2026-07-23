<?php
// A DECLARED `array` property, filled by a NESTED element write
// (`$this->g[$i][$j] = v`), holds arrays as elements. Reading an inner array
// back must keep its array-ness (is_array / var_dump / count / gettype), not
// decode a raw untagged pointer as int/garbage-float. FLOAT elements unmask the
// bug (NaN-boxing hides small ints).

class Grid { public array $g = []; }

$G = new Grid();
$G->g[0][0] = 1.5;
$G->g[0][1] = 2.5;
$G->g[1][0] = 9;

var_dump(is_array($G->g[0]));
var_dump($G->g[0]);
echo count($G->g[0]), "\n";
echo gettype($G->g[0]), "\n";
var_dump($G->g[0][1]);
var_dump($G->g[1][0]);

// string-keyed outer
class Table { public array $rows = []; }
$T = new Table();
$T->rows['a'][0] = "x";
$T->rows['a'][1] = "y";
var_dump(is_array($T->rows['a']));
foreach ($T->rows['a'] as $v) { echo $v; }
echo "\n";

// REGRESSION GUARD: a flat backing slot (depth-1 element write, SPL-style)
// must stay a raw keyed buffer — boxing it would break key lookup.
class Store {
    public array $s = [];
    public function set(string $k, int $v): void { $this->s[$k] = $v; }
    public function get(string $k): int { return $this->s[$k]; }
}
$S = new Store();
$S->set("one", 1);
$S->set("two", 2);
echo $S->get("one"), $S->get("two"), "\n";
var_dump($S->s["two"]);
