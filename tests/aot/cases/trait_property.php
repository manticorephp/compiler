<?php
trait Counter {
    private int $count = 0;
    private string $label = "n";
    public function bump(): void { $this->count = $this->count + 1; }
    public function show(): string { return $this->label . "=" . (string)$this->count; }
}
final class Widget {
    use Counter;
    public int $id = 42;
    public function run(): string {
        $this->bump();
        $this->bump();
        return $this->show() . " id=" . (string)$this->id;
    }
}
$w = new Widget();
echo $w->run(), "\n";
