<?php
// A group use with function and const items fails to parse ("expected '}' to close use group")
// issue: #478
namespace A {
    function e() { return 'e'; }
    const K = 'k';
}
namespace {
    use A\{function e, const K};
    echo e(), K, "\n";
}
