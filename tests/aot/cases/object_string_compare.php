<?php
// object == string never calls __toString
final class S { public function __toString(): string { return "x"; } }
var_dump(new S() == "x", "x" == new S(), new S() == "y");
