<?php
namespace App\Core;

use Lib\Util as U;
use function Lib\helper as h;
use const Lib\LIMIT;
use Lib\Group\{Alpha, function gf, const GC};

interface Shape {}

trait Loud
{
    public $volume = 1;
    public static int $count = 0, $max = 9;
}

enum Suit: string
{
    case Hearts = 'H';
    case Spades = 'S';
    const Wild = self::Spades;
}

abstract class Base
{
    const A = 1, B = 2;
    abstract public function run(): void;
}

final class Box extends Base implements Shape
{
    public function run(): void
    {
        $o = new class { public $q; function inner() {} };
        $p = new class extends Base { function run(): void {} };
    }
}

function helper(int $a): int
{
    return $a;
}
