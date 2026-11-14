<?php
interface Unused { public function f(MissingA $a): void; }
interface UsedI { public function g(MissingB $b): void; }
abstract class Base implements UsedI { abstract public function h(MissingC $c): void; }
final class Impl extends Base { public function g($b): void {} public function h($c): void {} }
(new Impl())->g(1);
