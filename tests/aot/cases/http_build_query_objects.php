<?php
// http_build_query walks an object value by its public properties: a request attribute holding a service made the login redirect throw.
class Pv { private $x = 1; }
class Pu { public $a = 1; public $b = [2, 3]; protected $c = 4; public $n = null; }
class St implements Stringable { public $p = 'pub'; public function __toString(): string { return 's'; } }
echo http_build_query(['a' => 1, 'o' => new Pv, 'u' => new Pu, 's' => new St, 'z' => 2]), "\n";
echo http_build_query(new Pu), "|", http_build_query(['k' => [new Pu]]), "|", http_build_query(['t' => true, 'f' => false, 'n' => null, 'fl' => 1.5]), "\n";
echo http_build_query(['a b' => 'c d', 'x' => ['y' => 'z']], '', '&amp;', PHP_QUERY_RFC3986), "|", http_build_query([1, 2], 'p_'), "\n";
