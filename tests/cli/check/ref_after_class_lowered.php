<?php
class A {}
function g() { $a = new A(); return A::class; }
echo g(), "\n";
