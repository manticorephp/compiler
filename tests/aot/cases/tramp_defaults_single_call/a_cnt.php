<?php
class Cnt {
    public function counted(int $a, int $b = 5): string { return $a . "+" . $b . " n=" . func_num_args(); }
}
