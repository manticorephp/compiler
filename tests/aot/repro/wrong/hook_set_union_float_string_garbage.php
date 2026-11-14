<?php
// A float|string property with a set hook reads back its value as raw double bits.
class K {
    public float|string $hs { set(float|string $v) { $this->hs = $v; } get => $this->hs; }
}
$k = new K();
$k->hs = 8.5; var_dump($k->hs);
$k->hs = "a"; var_dump($k->hs);
