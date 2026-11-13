<?php
// A local bound by an inner foreach and reassigned in the outer loop, captured by a by-ref closure: ownership compensation must settle.
class TJ {
    const PSEUDO = ['core/button' => [':hover', ':focus']];
    private function features($meta, $node) { return is_array($node) ? ['.a' => ['color' => 'red'], '.b' => []] : []; }
    private static function append($sel, $p) { return $sel . $p; }
    private static function props($node) { return is_array($node) ? $node : []; }
    public function process($node, $base, $block, $meta = null, $variation = null) {
        $pseudo = array();
        $add = static function ($selector, $declarations) use (&$pseudo) {
            if (empty($declarations)) { return; }
            if (isset($pseudo[$selector])) {
                $pseudo[$selector] = array_merge($pseudo[$selector], $declarations);
            } else {
                $pseudo[$selector] = $declarations;
            }
        };
        if (!isset(static::PSEUDO[$block])) { return $pseudo; }
        foreach (static::PSEUDO[$block] as $ps) {
            if (isset($node[$ps])) {
                $pnode = $node[$ps];
                if (is_array($meta)) {
                    $feats = $this->features($meta, $pnode);
                    foreach ($feats as $fsel => $declarations) {
                        $target = is_array($variation) ? $fsel . '-v' : $fsel;
                        $combined = static::append($target, $ps);
                        $add($combined, $declarations);
                    }
                }
                $combined = static::append($base, $ps);
                $declarations = static::props($pnode);
                $add($combined, $declarations);
            }
        }
        return $pseudo;
    }
}
print_r((new TJ)->process([':hover' => ['x' => 1]], '.btn', 'core/button', ['m' => 1]));
