<?php
// Fast-path shapes (scalars + arrays of them) and fallback shapes (objects, references).
class P { public int $a = 1; public string $b = "x"; public ?P $next = null; }
enum E: string { case A = 'a'; }

function show(string $label, mixed $v): void {
    $s = serialize($v);
    echo $label, ' ', strlen($s), ' ', str_replace("\0", '\0', $s), "\n";
    $u = unserialize($s);
    echo '  rt ', var_export($u === $v || ($u != $u && $v != $v), true), "\n";
}

show('null', null);
show('true', true);
show('false', false);
show('int0', 0);
show('neg', -5);
show('max', PHP_INT_MAX);
show('min', PHP_INT_MIN);
show('big', 1 << 50);
show('f0.1', 0.1);
show('f-0', -0.0);
show('f0', 0.0);
show('f1', 1.0);
show('f1e100', 1e100);
show('f1e-10', 1.5e-10);
show('f1e15', 1e15);
show('f123456', 123456789012345.0);
show('f0.30000000000000004', 0.1 + 0.2);
show('inf', INF);
show('-inf', -INF);
echo serialize(NAN), "\n";
show('empty str', '');
show('str', 'hello');
show('quotes', "a\"b';c");
show('nul', "a\0b");
show('multibyte', "héllo wörld ✓");
show('empty arr', []);
show('list', [1, 2, 3]);
show('strs', ['a', 'bb', 'ccc']);
show('assoc', ['x' => 1, 'y' => 'two', 'z' => 3.5, 'n' => null, 't' => true]);
show('intlike', ['5' => 'a', '05' => 'b', '-3' => 'c', 7 => 'd']);
show('nested', [[1, 2], ['k' => [true, null, 'v']], []]);
show('sparse', [3 => 'a', 1 => 'b', 10 => 'c']);
$del = [1, 2, 3, 4]; unset($del[1]);
show('deleted', $del);
$big = []; for ($i = 0; $i < 300; $i++) { $big[] = "item $i"; }
echo md5(serialize($big)), "\n";
$ints = []; for ($i = 0; $i < 300; $i++) { $ints[] = $i * 997 - 100000; }
echo md5(serialize($ints)), "\n";
$fl = []; for ($i = 1; $i < 200; $i++) { $fl[] = $i / 7.0; $fl[] = $i * 1.5e10; $fl[] = $i * 1e-7; }
echo md5(serialize($fl)), "\n";

// fallback shapes (objects, enum)
$o = new P; $o->next = new P;
echo serialize($o), "\n";
echo serialize([1, $o, 'z']), "\n";
echo serialize(['k' => ['deep' => $o]]), "\n";
echo serialize(E::A), "\n";
echo serialize([E::A, 1]), "\n";
$same = new P;
echo serialize([$same, $same]), "\n";
$u = unserialize(serialize([1, $o, 'z']));
echo get_class($u[1]), ' ', $u[1]->next->a, "\n";

// malformed input
foreach (['', 'a:1:{', 'a:2:{i:0;i:1;}', 's:5:"abc";', 'i:;', 'a:1:{i:0;s:3:"ab";}', 'x:1;', 'a:1:{i:0;i:1;', 'b:2;'] as $bad) {
    echo var_export(@unserialize($bad), true), "\n";
}

// parser corners: literals, duplicate keys, int-like string keys, trailing bytes
foreach (['d:INF;', 'd:-INF;', 'd:NAN;', 'd:-0;', 'd:1.5e3;', 'd:.5;', 'd:7;', 'i:-0;', 'i:007;', 'i:+5;',
          'a:2:{i:0;i:1;i:0;i:2;}', 'a:2:{s:1:"5";i:1;s:2:"05";i:2;}', 'a:1:{s:19:"1000000000000000000";i:1;}',
          'a:1:{s:19:"9223372036854775808";i:1;}', 'a:2:{i:5;N;i:0;N;}', 'i:5;trailing', 'a:0:{}x',
          'a:1:{i:0;a:1:{i:0;a:1:{i:0;s:0:"";}}}', 'b:1;', 'N;', 's:0:"";', 'a:3:{i:0;i:1;}', 'a:99999999999:{}',
          's:4:"abc";', 'a:1:{d:1;i:1;}'] as $in) {
    $r = @unserialize($in);
    echo $in, ' => ', is_float($r) && is_nan($r) ? 'NAN' : serialize($r), "\n";
}
$deep = 'i:1;'; for ($i = 0; $i < 300; $i++) { $deep = 'a:1:{i:0;' . $deep . '}'; }
echo strlen(serialize(unserialize($deep))), "\n";
