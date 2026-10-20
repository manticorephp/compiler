<?php
class H {
    private array|null $d = null;
    private ?array $q = null;
    function set(): void { $this->d = ['type' => 1, 'n' => 2]; $this->q = [7, 8, 9]; }
    function f(): int {
        $e = $this->d;
        if ($e === null) { $e = array_merge([1], [2]); }
        return count($e);
    }
    function g(): int {
        $e = $this->q;
        if ($e === null) { $e = array_merge([1], [2]); }
        return count($e);
    }
}
$h = new H;
echo $h->f(), $h->g(), "\n";
$h->set();
echo $h->f(), $h->g(), "\n";
