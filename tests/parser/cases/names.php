<?php
namespace App\Core;

use Lib\Util as U;
use Lib\Tool;
use function Lib\helper as h;
use function Lib\other;
use const Lib\LIMIT;
use Lib\Group\{Alpha, Beta as B};

#[Tool(1)]
final class Box extends U implements \Fq\Iface, Alpha
{
    use Tool;

    public function run(?Beta $a, int|Tool $b, (Alpha&B)|null $c): static
    {
        try {
            $x = new self();
            $y = static::make(parent::NAME, \Fq\Name::class);
            if ($x instanceof Missing) {
                return h(strlen('a'), other(), \Fq\fn1(), Ns\fn2());
            }
            return PHP_EOL . LIMIT . \FOO . Ns\BAR . __LINE__ . Box::K . namespace\BAZ;
        } catch (\RuntimeException | B $e) {
            return $this;
        }
    }
}
