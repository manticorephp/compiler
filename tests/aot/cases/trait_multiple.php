<?php
trait Hello { public function hi(): string { return "hi"; } }
trait World { public function world(): string { return "world"; } }
class Greeter { use Hello, World; }
$g = new Greeter();
echo $g->hi(), " ", $g->world();
