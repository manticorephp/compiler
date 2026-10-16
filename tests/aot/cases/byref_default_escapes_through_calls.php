<?php
// An omitted by-ref default passed down by reference to a generator that captures it outlives the call.
class B {
    public function get(mixed $v): mixed { return $this->doGet($v); }
    private function doGet(mixed $v, ?array &$inline = null): mixed { $inline ??= []; return $this->create($v, $inline); }
    private function create(mixed $v, array &$inline): mixed { return $this->doRes($v, $inline); }
    private function doRes(mixed $value, array &$inline = []): mixed
    {
        if (\is_array($value)) {
            return (function () use ($value, &$inline) {
                foreach ($value as $k => $v) { yield $k => $this->doRes($v, $inline); }
            })();
        }
        $inline["\0" . $value] = $value;
        return $value . count($inline);
    }
}
$b = new B();
$g = $b->get(['a', 'b', 'c']);
$junk = []; for ($i = 0; $i < 100; $i++) { $junk[] = str_repeat('x', 40); }
foreach ($g as $k => $v) { echo "$k=$v\n"; }
