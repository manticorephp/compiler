<?php
final class F {}
class SubF extends F {}
class Fm { final public function f() {} }
class SubFm extends Fm { public function f() {} }
interface I { function g(); }
class Missing implements I {}
class Vis { public function v() {} }
class SubVis extends Vis { private function v() {} }
