<?php
class MyAO extends ArrayObject {}
function dump(string $tag, $o): void {
    echo $tag, ' count=', count($o), ' keys=', json_encode(array_keys($o->getArrayCopy())), ' ', json_encode($o->getArrayCopy()), "\n";
}
function erased($o) {
    $o['1'] = 'a'; $o[1] = 'b'; $o['01'] = 'c'; $o['-0'] = 'd'; $o['-5'] = 'e'; $o[' 1'] = 'f'; $o['9223372036854775808'] = 'g';
    var_dump(isset($o['1']), isset($o[1]), isset($o['-5']), isset($o[-5]), isset($o['01']), $o['1'] ?? 'none', $o['-5'], $o[-5]);
    unset($o['-5']);
    var_dump(isset($o[-5]), count($o));
    return $o;
}
$ao = new ArrayObject([]);
$ao['1'] = 'a'; $ao[1] = 'b'; $ao['01'] = 'c'; $ao['-0'] = 'd'; $ao['-5'] = 'e'; $ao[' 1'] = 'f'; $ao['9223372036854775808'] = 'g';
dump('typed', $ao);
var_dump(isset($ao['1']), isset($ao[1]), $ao['-5'], $ao[-5]);
unset($ao['1']);
dump('typed-unset', $ao);
dump('erased', erased(new ArrayObject([])));
$m = new MyAO([]); $m['1'] = 'a'; $m[1] = 'b'; $m['2'] = 'x'; dump('subclass', $m);
dump('erased-subclass', erased(new MyAO([])));
$it = new ArrayIterator([]); $it['2'] = 'x'; $it[2] = 'y'; $it['01'] = 'z';
var_dump(count($it), $it->getArrayCopy());
class H { public $q; }
$h = new H; $h->q = new ArrayObject([]); $h->q['1'] = 1; $h->q[1] = 2; unset($h->q['1']); var_dump(count($h->q));
$h->q['3'] = 1; unset($h->q[3]); var_dump(count($h->q));
