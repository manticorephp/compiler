<?php
class S { function __toString(): string { return "s!"; } }
echo strval(new S), "\n";
