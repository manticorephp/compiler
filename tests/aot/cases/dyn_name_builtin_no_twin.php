<?php
// A codegen builtin with no stdlib twin (count, abs, substr, min, ...) called through a runtime name throws "undefined function".
// issue: #317
foreach (['count', 'abs'] as $n) {
    echo $n === 'count' ? $n([1, 2, 3]) : $n(-4), "\n";
}
