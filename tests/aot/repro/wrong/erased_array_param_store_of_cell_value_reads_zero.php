<?php
// A string value read from an array<int, mixed> and stored into a bare-array parameter reads back as 0
// issue: #161
class Dbg {
    /** @return array<int, mixed> */
    public function __debugInfo(): array { return [1, 'x']; }
}
function cast(object $obj, array $a): array {
    $debugInfo = $obj->__debugInfo();
    foreach ($debugInfo as $k => $v) {
        $a['v' . $k] = $v;
    }
    return $a;
}
echo json_encode(cast(new Dbg(), ['p' => 1])), "\n";
