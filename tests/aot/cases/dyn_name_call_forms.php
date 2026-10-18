<?php
// issue repros
foreach (["strlen"] as $n) { $f = $n; var_dump($f(-5)); }
$f = 'strlen';
$c = 0;
for ($i = 0; $i < 10; $i++) { $c += $f('abc'); }
echo $c, "\n";
function a() { $f = 'strlen'; $c = -1; for ($i = 0; $i < 2; $i++) { $c = $f('abc'); } return $c; }
echo a(), "\n";

// compound / compare / concat / element store / return / echo
function userfn(string $s): int { return strlen($s) * 2; }
class K {
    public static function sm(string $s): string { return strtoupper($s); }
    public function m(string $s): string { return $s . '!'; }
    public function viaName(string $n): int {
        $t = 0;
        foreach ([1, 2, 3] as $_) { $t += $n('abcd'); }
        return $t;
    }
}
foreach (['strlen', 'userfn', 'STRLEN', 'UserFn'] as $n) {
    $t = 10;
    $t += $n('abc');
    $t -= $n('a');
    $t *= $n('ab');
    echo $n, ' ', $t, ' ', $n('xyz') + 1, ' ', $n('xy') . 'z', ' ', $n('xy') == 2 ? 'eq' : 'ne', ' ', $n('abc') > 5 ? 'gt' : 'le', "\n";
    $arr = [];
    $arr[] = $n('hello');
    $arr['k'] = $n('hi');
    echo json_encode($arr), "\n";
    $s = 'len=';
    $s .= $n('four');
    echo $s, "\n";
}
function ret(string $n) { return $n('abcde'); }
echo ret('strlen'), ret('userfn'), "\n";
function loopret(string $n) { $x = 0; for ($i = 0; $i < 3; $i++) { $x = $n('ab') + $i; } return $x; }
echo loopret('strlen'), loopret('userfn'), "\n";
echo (new K)->viaName('strlen'), "\n";

// string forms
$f = 'K::sm';
echo $f('abc'), "\n";
$f = [new K, 'm'];
echo $f('abc'), "\n";
$f = ['K', 'sm'];
echo $f('abc'), "\n";
$f = fn($s) => strlen($s) + 100;
echo $f('abc'), "\n";
$f = strlen(...);
echo $f('abcd'), "\n";
echo call_user_func('strlen', 'abcdef'), "\n";
echo call_user_func('userfn', 'abc'), "\n";
echo call_user_func_array('strlen', ['abcdefg']), "\n";
echo call_user_func('K::sm', 'x'), call_user_func([new K, 'm'], 'x'), "\n";
echo implode(',', array_map('strlen', ['a', 'bb', 'ccc'])), "\n";
echo implode(',', array_map('strtoupper', ['a', 'bb'])), "\n";
echo implode(',', array_map('userfn', ['a', 'bb'])), "\n";
foreach (['strtoupper', 'strtolower', 'ord', 'chr', 'str_repeat', 'intdiv', 'dechex', 'trim'] as $n) {
    var_dump(match ($n) {
        'chr' => $n(65), 'str_repeat' => $n('ab', 2), 'intdiv' => $n(7, 2), 'dechex' => $n(255),
        'ord' => $n('A'), default => $n('Ab '),
    });
}
// case-insensitive, by-ref builtin by name
$f = 'STRTOUPPER';
echo $f('abc'), "\n";
$f = 'sort';
$data = [3, 1, 2];
$f($data);
echo implode(',', $data), "\n";
// undefined
$f = 'no_such_fn_xyz';
try { $f(1); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
