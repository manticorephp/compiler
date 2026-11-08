<?php
enum Level: int { case Debug = 100; case Info = 200; }
class Cfg {
    protected $levels = ['debug' => Level::Debug, 'info' => Level::Info];
    /**
     * @param  string  $k
     * @return int
     */
    protected function level($k)
    {
        if (isset($this->levels[$k])) { return $this->levels[$k]; }
        throw new InvalidArgumentException('bad');
    }
    public function go(): void { $l = $this->level('info'); var_dump($l); echo $l->name, "\n"; }
}
(new Cfg)->go();
