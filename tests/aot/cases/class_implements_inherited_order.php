<?php
// class_implements() of a subclass lists inherited interfaces in a different order than php.

interface I {}
interface J extends I {}
class A implements J {}
class B extends A {}

echo json_encode(class_implements('A')), "\n";
echo json_encode(class_implements('B')), "\n";
