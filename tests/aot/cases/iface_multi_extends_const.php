<?php
interface U { const A = 0; }
interface V { const B = 1; }
interface R extends U, V { }
class B implements R {}
class C extends B {}
echo json_encode((new ReflectionClass('C'))->getConstants()), "\n";
echo C::A, C::B, "\n";
