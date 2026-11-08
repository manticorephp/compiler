<?php
class There
{
    public function run(callable $c)
    {
        return $c();
    }
}
