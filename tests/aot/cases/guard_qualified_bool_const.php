<?php
// A declaration guarded by a namespace-qualified `\false` / `\true` folds like the
// bare literal: the dead class is never declared, the live one is.
class Real {}
if (\false) {
    class Legacy extends Real {}
}
if (\TRUE) {
    class Live extends Real {}
}
if (\true === \PHP_INT_SIZE > 4) {
    function wide(): string { return 'wide'; }
}
var_dump(class_exists('Legacy'), class_exists('Live'), function_exists('wide'));
echo "ok\n";
