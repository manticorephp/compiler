<?php
class Foo {}
interface IBar {}
var_dump(class_exists('foo'), class_exists('\FOO'), class_exists('Foo'), class_exists('nope'));
var_dump(interface_exists('ibar'));
