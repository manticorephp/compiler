<?php
// Exception chaining: 3rd ctor arg $previous + getPrevious().
try {
    try {
        throw new InvalidArgumentException("bad arg", 22);
    } catch (Exception $e) {
        throw new RuntimeException("wrapped", 0, $e);
    }
} catch (Exception $e) {
    echo $e->getMessage(), " <- ", $e->getPrevious()->getMessage(), "\n";
    echo $e->getPrevious()->getCode(), "\n";
    var_dump($e->getPrevious()->getPrevious());
}
$solo = new Exception("solo");
var_dump($solo->getPrevious());
