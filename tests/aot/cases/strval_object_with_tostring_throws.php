<?php
// strval() of an object that defines __toString throws "could not be converted to string".
class S { function __toString(): string { return "s!"; } }
echo strval(new S), "\n";
