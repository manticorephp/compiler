<?php
// An omitted by-ref default the callee's generator closure captures by reference outlives the call.
class B {
    public function res(mixed $v): mixed { return $this->doRes($v); }
    private function doRes(mixed $value, array &$inline = [], bool $x = false): mixed
    {
        if (\is_array($value)) {
            return (function () use ($value, &$inline) {
                foreach ($value as $k => $v) {
                    yield $k => $this->doRes($v, $inline);
                }
            })();
        }
        $this->share($value, $inline);
        return $value . count($inline);
    }
    private function share(mixed $s, array &$inline): void { $inline["\0" . $s] = $s; }
}
$b = new B();
foreach ($b->res(['a', 'b', 'c']) as $k => $v) { echo "$k=$v\n"; }
echo $b->res('z'), "\n";
