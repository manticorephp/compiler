<?php
// A callable array whose names exist only at run time: direct, through a local, through
// call_user_func(_array) and array_map; a static method by class name, an instance method by object.
class Z {
    public static function run($x = 'd') { return 'run ' . $x; }
    public function inst($x = 'd') { return 'inst ' . $x; }
}
function direct(string $c, string $m, array $a) { return [$c, $m](...$a); }
function viaCufa(string $c, string $m, array $a) { return call_user_func_array([$c, $m], $a); }
function viaCuf(string $c, string $m) { return call_user_func([$c, $m], 'q'); }
function viaLocal(string $c, string $m) { $f = [$c, $m]; return $f('l'); }
function viaMap(string $c, string $m) { return array_map([$c, $m], ['x'])[0]; }
function instDirect(Z $o, string $m) { return [$o, $m]('i'); }
function instCufa(object $o, string $m) { return call_user_func_array([$o, $m], ['p']); }
function instLocal(object $o, string $m) { $f = [$o, $m]; return $f('m'); }
echo direct('Z', 'run', ['v']), "\n", viaCufa('Z', 'run', ['w']), "\n", viaCuf('Z', 'run'), "\n";
echo viaLocal('Z', 'run'), "\n", viaMap('Z', 'run'), "\n", viaCufa('z', 'RUN', ['b']), "\n";
echo instDirect(new Z, 'inst'), "\n", instCufa(new Z, 'inst'), "\n", instLocal(new Z, 'INST'), "\n";
