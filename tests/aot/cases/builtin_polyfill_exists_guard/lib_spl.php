<?php
// sodium_compat's polyfill: leaves when the runtime has the class.
if (class_exists('SplFixedArray')) {
    return;
}
class SplFixedArray implements Countable
{
    private $size = 0;
    public function __construct($size = 0) { $this->size = $size; }
    public function count(): int { return -1; }
}
