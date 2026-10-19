<?php
class Box {
    public mixed $s;
    function __construct() { $this->s = [1, 2, 3]; }
    function snap(): mixed { $x = $this->s; $this->s[] = 4; return $x; }
    function at(int $i): mixed { return $this->s[$i]; }
    function cnt(): int { return count($this->s); }
}
$b = new Box();
$x = $b->snap();
echo json_encode($x), json_encode($b->s), "\n";
$y = $b->s;
$y[] = 99;
echo json_encode($b->s), json_encode($y), $b->at(3), $b->cnt(), "\n";
$f = fopen('php://memory', 'w+');
for ($i = 0; $i < 5; $i++) { fwrite($f, "ab$i\n"); }
fseek($f, 1); fwrite($f, "ZZ"); fseek($f, 0, SEEK_END); fwrite($f, "end");
rewind($f);
echo stream_get_contents($f), "\n";
