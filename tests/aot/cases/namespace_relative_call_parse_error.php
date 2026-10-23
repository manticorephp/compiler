<?php
// A namespace-relative name in an expression (namespace\f(), namespace\C) fails to parse.
namespace A;

const C = 'c';
function f(): string { return "f"; }

echo namespace\f(), ' ', namespace\C, "\n";
