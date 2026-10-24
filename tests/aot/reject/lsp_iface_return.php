<?php
interface I { public function f(): int; }
class B implements I { public function f(): string { return ""; } }
