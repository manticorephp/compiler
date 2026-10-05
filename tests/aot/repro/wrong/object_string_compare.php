<?php
// object == string never calls __toString
// issue: #54
final class S { public function __toString(): string { return "x"; } }
var_dump(new S() == "x", "x" == new S(), new S() == "y");
