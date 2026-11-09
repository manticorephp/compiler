<?php
// `$o->$name` on a class with many non-public properties: denied from outside, allowed inside, by a sorted-table lookup.
class Many {
    protected $alpha = 1; protected $bravo = 2; protected $charlie = 3; protected $delta = 4; protected $echo = 5;
    protected $foxtrot = 6; protected $golf = 7; protected $hotel = 8; private $india = 9; private $juliet = 10;
    public $kilo = 11;
    public function read(string $n) { return $this->$n; }
}
$m = new Many();
$names = ['juliet', 'alpha', 'hotel', 'charlie', 'india', 'echo', 'bravo', 'golf', 'delta', 'foxtrot'];
foreach ($names as $n) {
    try { $v = $m->$n; echo "$n read $v\n"; } catch (Error $e) { echo $e->getMessage(), "\n"; }
    echo "$n inside ", $m->read($n), "\n";
}
$k = 'kilo';
echo "kilo outside ", $m->$k, "\n";
