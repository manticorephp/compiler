<?php

// Deep nesting: the indent has to keep agreeing with php at every level, and
// the two key columns (object +3, array +2) must not drift into each other.

class Leaf
{
    public int $v = 0;

    public function __construct(int $v)
    {
        $this->v = $v;
    }
}

class Branch
{
    /** @var array<string,mixed> */
    public array $map = [];
    public ?Leaf $leaf = null;
}

class Trunk
{
    public ?Branch $b = null;
    public string $label = '';
}

$leaf = new Leaf(9);

$branch = new Branch();
$branch->leaf = $leaf;
$branch->map = ['one' => 1, 'deep' => ['two' => 2]];

$trunk = new Trunk();
$trunk->b = $branch;
$trunk->label = 'root';

var_export($trunk);
echo "\n";

// Object -> array -> object.
var_export(['list' => [new Leaf(1), new Leaf(2)]]);
echo "\n";

// Array -> object -> array.
var_export([$branch]);
echo "\n";
