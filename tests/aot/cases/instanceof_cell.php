<?php
interface Shape {}
class Circle implements Shape {}
class Square implements Shape {}
function chk(mixed $v): void {
  var_dump($v instanceof Shape);
  var_dump($v instanceof Circle);
}
chk(new Circle());
chk(new Square());
chk(42);
chk("hi");
chk(null);
chk([1,2]);
