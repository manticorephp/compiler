<?php
enum Level: int { case Debug = 100; case Info = 200; }
function take(int|string|Level $l): string {
    return $l instanceof Level ? 'enum ' . $l->name : (is_string($l) ? "str $l" : "int $l");
}
trait Levels {
    protected $levels = ['debug' => Level::Debug, 'info' => Level::Info];
    protected function level(string $k) { return isset($this->levels[$k]) ? $this->levels[$k] : null; }
}
class M {
    use Levels;
    public $pub;
    protected $late;
    public function __construct() { $this->late = ['d' => Level::Debug, 'i' => Level::Info]; $this->pub = [Level::Info]; }
    public function go(): void {
        echo take($this->level('debug')), ' ', take($this->level('info')), "\n";
        var_dump($this->late, $this->pub);
        echo take($this->late['i']), "\n";
    }
}
(new M)->go();
