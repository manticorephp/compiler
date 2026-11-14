<?php

class LogicException
{
    public function mine(): void {}
}

$e = new LogicException();
$e->getMessage();
$e->mine();
$e->other();
