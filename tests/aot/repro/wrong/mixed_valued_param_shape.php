<?php
// An `array<string,mixed>` param given a nested literal refuses a later write of another kind
// issue: #50
/** @param array<string,mixed> $m */
function t(array $m): void { $m['k'] = 'str'; var_dump($m['k']); }
t(['k' => ['a', 'b']]);
