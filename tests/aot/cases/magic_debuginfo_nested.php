<?php

// __debugInfo returning containers and other objects, and a subclass that
// overrides it — the arm has to be picked most-derived-first.
//
// Each dump runs in its own function so only ONE object graph is alive at a
// time: var_dump's object id is a fixed `#1` here (documented divergence), and
// php only agrees while it has no second live object to number.

class Node_
{
    public string $name = '';
    /** @var int[] */
    public array $kids = [];

    public function __construct(string $n)
    {
        $this->name = $n;
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'kids' => $this->kids];
    }
}

class Leaf extends Node_
{
    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['leaf' => $this->name];
    }
}

class Holder
{
    /** @var array<string,mixed> */
    public array $box = [];

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['box' => $this->box, 'depth' => 1];
    }
}

// A bag class with __debugInfo: the dynamic entries are NOT added on top.
#[\AllowDynamicProperties]
class Loose
{
    public int $declared = 1;

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['only' => 'this'];
    }
}

function showNode(): void
{
    $n = new Node_('root');
    $n->kids = [1, 2, 3];
    var_dump($n);
}

function showLeaf(): void
{
    var_dump(new Leaf('tip'));
}

function showHolder(): void
{
    $h = new Holder();
    $h->box = ['a' => 1, 'b' => ['deep' => true]];
    var_dump($h);
}

function showLoose(): void
{
    $l = new Loose();
    $l->extra = 'ignored';
    var_dump($l);
}

showNode();
showLeaf();
showHolder();
showLoose();
