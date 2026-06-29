<?php
trait Greetable {
    public function greet(): string { return "hello"; }
}
class English {
    use Greetable;
}
$e = new English();
echo $e->greet();
