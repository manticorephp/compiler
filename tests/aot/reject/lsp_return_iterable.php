<?php
class A { public function f(): array { return []; } }
class B extends A { public function f(): iterable { return []; } }
