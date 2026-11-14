<?php
class Locator { public function get(string $id): string { return $id; } }
class Console { public function get(string $id): object { return new Missing\Command(); } }
function spec(Locator $l): array { return [$l, 'get']; }
echo spec(new Locator())[1], "\n";
