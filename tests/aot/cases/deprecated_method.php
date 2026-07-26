<?php

// #[\Deprecated] on methods: instance, static, and one reached through a
// subclass — php reports the DECLARING class in every case.

class Legacy
{
    #[\Deprecated(since: "2.0")]
    public function instance(): string { return 'i'; }

    #[\Deprecated]
    public static function stat(): string { return 's'; }

    public function fine(): string { return 'f'; }
}

class Heir extends Legacy {}

echo "start\n";
$l = new Legacy();
echo $l->instance(), "\n";
echo Legacy::stat(), "\n";
echo $l->fine(), "\n";
$h = new Heir();
echo $h->instance(), "\n";
echo "end\n";
