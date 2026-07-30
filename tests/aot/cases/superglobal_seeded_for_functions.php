<?php
// Superglobals are seeded in __main, but the seed was emitted only when __main
// ITSELF mentioned the name — the exact case the pass's own docblock warns
// against. A program whose entry file never names one (every console app:
// symfony's ArgvInput reads $_SERVER['argv'], bin/app.php does not) left the
// cell uninitialised, so each function read the hard-lowered `int`.
//
// Registering the name as an implicit `global` is the other half: without it
// scanGlobalTypes bails on its empty-globalVarNames guard and never unifies the
// type, so even a seeded cell was read as an integer.
//
// NOTE: __main below deliberately never mentions $_SERVER.
function readsServer(): string
{
    $argv = $_SERVER['argv'] ?? [];
    return 'fn: is_array=' . (\is_array($argv) ? 'Y' : 'n')
        . ' has0=' . (\strlen((string) ($argv[0] ?? '')) > 0 ? 'Y' : 'n')
        . ' argc_matches=' . ($_SERVER['argc'] === \count($argv) ? 'Y' : 'n');
}
class Reader
{
    public function read(): string
    {
        $a = $_SERVER['argv'];
        return 'method: is_array=' . (\is_array($a) ? 'Y' : 'n') . ' count>0=' . (\count($a) > 0 ? 'Y' : 'n');
    }
}
function readsEnvToo(): string
{
    $e = $_ENV;
    return 'env: is_array=' . (\is_array($e) ? 'Y' : 'n');
}

echo readsServer(), "\n";
echo (new Reader())->read(), "\n";
echo readsEnvToo(), "\n";
$c = static function (): string {
    $s = $_SERVER['argv'] ?? [];
    return 'closure: is_array=' . (\is_array($s) ? 'Y' : 'n');
};
echo $c(), "\n";
