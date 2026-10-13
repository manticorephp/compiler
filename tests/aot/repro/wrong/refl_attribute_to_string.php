<?php
// (string) ReflectionAttribute throws "could not be converted to string"; php renders an Attribute [ ... ] block.
// issue: #245

#[Attribute]
class Tag { public function __construct(public string $v = '', public int $n = 0) {} }

#[Tag('x', n: 3)]
class K {}

#[Tag]
class L {}

echo (string) (new ReflectionClass('K'))->getAttributes()[0];
echo (string) (new ReflectionClass('L'))->getAttributes()[0];
