<?php
namespace App;

class Foo {}

class Box
{
    /**
     * @template T
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object { return new Foo; }

    /** @param class-string $c */
    public function takes(string $c): void {}
}

function go(Box $b, string $dyn): void
{
    $b->get('App\Foo');
    $b->get(Foo::class);
    $b->get($dyn);
    $b->get('Nope\Missing');
    $b->takes('\\App\\Foo');
    $b->takes('Typo');
}
