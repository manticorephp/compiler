<?php
#[Attribute]
class Tag { public function __construct(public int $v) { echo "tag\n"; } }
#[Tag(1)] class T {}
class Unrelated { public function __construct() {} }
$r = new ReflectionClass(T::class);
echo $r->getAttributes()[0]->newInstance()->v, "\n";
