<?php
class Base {
    final public function tag(): string { return "B"; }
}
class Sub extends Base {}
echo (new Sub())->tag();
