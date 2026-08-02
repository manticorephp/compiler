<?php
// A local FIRST BOUND inside an expression the preallocation walk did not
// enumerate got no entry slot, and its StoreLocal then emitted `store …, ptr `
// with an empty operand — invalid IR, which clang rejects as "expected
// instruction opcode" while pointing at the NEXT line.
//
// The witness is symfony/polyfill-intl-messageformatter:
//     if (!isset($values[$param = trim($token[0])])) { … }
// The walk is now exhaustive by construction rather than a list of kinds, so
// these cases are covered by the same rule instead of one at a time.

function viaIsset(array $values, array $token): string
{
    if (!isset($values[$param = trim($token[0])])) {
        return '{' . $param . '}';
    }
    return 'hit:' . $param . '=' . $values[$param];
}

echo viaIsset(['a' => 1], ['  a  ']), "\n";
echo viaIsset(['a' => 1], ['  b  ']), "\n";

// The same shape through the other expression positions that bind a local.
function viaEmpty(array $v): string
{
    if (empty($v[$k = 'key'])) {
        return 'empty:' . $k;
    }
    return 'set:' . $k;
}
echo viaEmpty([]), "\n";
echo viaEmpty(['key' => 1]), "\n";

function viaUnset(array $v): string
{
    unset($v[$u = 'gone']);
    return 'unset:' . $u . ':' . (string)count($v);
}
echo viaUnset(['gone' => 1, 'stay' => 2]), "\n";

function viaIssetNested(array $v): string
{
    if (isset($v[$outer = 'a'][$inner = 'b'])) {
        return 'both:' . $outer . $inner;
    }
    return 'miss:' . $outer . $inner;
}
echo viaIssetNested(['a' => ['b' => 1]]), "\n";
echo viaIssetNested(['a' => []]), "\n";

// A closure's own locals must NOT claim a slot in the enclosing frame, which is
// what makes the generic recursion safe: Walk::children of a closure yields its
// captures, never its body.
function viaClosure(array $values): string
{
    $outerBound = 'outer';
    $f = function (array $vals) {
        if (!isset($vals[$innerBound = 'z'])) {
            return 'inner-miss:' . $innerBound;
        }
        return 'inner-hit:' . $innerBound;
    };
    return $outerBound . '|' . $f($values);
}
echo viaClosure([]), "\n";
echo viaClosure(['z' => 1]), "\n";
