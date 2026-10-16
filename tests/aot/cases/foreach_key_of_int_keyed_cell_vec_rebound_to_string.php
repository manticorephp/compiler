<?php
class Dbg
{
    /** @return array<int, mixed> */
    public function __debugInfo(): array
    {
        return [1, 'x'];
    }
}

function cast(object $obj, bool $has): array
{
    $a = ['p' => 1, 'q' => 2];
    if ($has) {
        $debugInfo = $obj->__debugInfo();
    }
    foreach ($a as $k => $v) {
        if ("\0" !== ($k[0] ?? '')) {
            $a['x' . $k] = $v;
        }
    }
    if ($has && is_array($debugInfo)) {
        foreach ($debugInfo as $k => $v) {
            if (!isset($k[0]) || "\0" !== $k[0]) {
                if (array_key_exists('d' . $k, $a)) {
                    continue;
                }
                $k = 'v' . $k;
            }
            unset($a[$k]);
            $a[$k] = $v;
        }
    }
    return $a;
}

echo json_encode(cast(new Dbg(), true)), "\n";
echo json_encode(cast(new Dbg(), false)), "\n";
