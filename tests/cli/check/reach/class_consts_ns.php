<?php
namespace App\Log;
// A class constant read inside its own class lands in the class's `__mc_consts_<C>` table: an unused
// namespaced class must still be unused (its undeclared parent a hidden site, not an error).
class Dead_Logger extends \Missing\AbstractLogger { const A = 1; const B = self::A + 1; }
class Live_Box { const A = 2; const B = self::A + 1; }
echo Live_Box::B, \count((new \ReflectionClass(Live_Box::class))->getConstants()), "\n";
