<?php
namespace A {
    function e() { return 'e'; }
    const K = 'k';
}
namespace {
    use A\{function e, const K};
    echo e(), K, "\n";
}
