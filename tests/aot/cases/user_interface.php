<?php
interface Greetable {
    public function greet(): string;
}
class English implements Greetable {
    public function greet(): string { return "hi"; }
}
class Spanish implements Greetable {
    public function greet(): string { return "hola"; }
}
$g = new English();
echo $g->greet();
$g = new Spanish();
echo "/", $g->greet();
