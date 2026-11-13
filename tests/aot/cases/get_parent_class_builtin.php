<?php
// get_parent_class() answers false for built-in exception classes whose parent php reports.
foreach (['ErrorException', 'ParseError', 'CompileError', 'UnhandledMatchError', 'ClosedGeneratorException', 'RuntimeException', 'LogicException', 'InvalidArgumentException', 'TypeError', 'ArgumentCountError', 'ArithmeticError', 'DivisionByZeroError', 'ValueError', 'JsonException', 'Exception', 'Error', 'ArrayIterator', 'ArrayObject', 'SplStack', 'SplQueue', 'SplObjectStorage', 'Closure', 'Generator', 'stdClass'] as $c) {
    var_dump(get_parent_class($c));
}
$o = new ErrorException;
var_dump(get_parent_class($o));
$n = 'ParseError';
var_dump(get_parent_class(new $n));
function pc(object $x) { return get_parent_class($x); }
var_dump(pc(new UnhandledMatchError('m')), pc(new SplQueue));
