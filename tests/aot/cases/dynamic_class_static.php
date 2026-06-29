<?php
class Foo {}
class Bar {}
$o = new Foo();
$b = new Bar();
echo $o::class, ",", $b::class;
