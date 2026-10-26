<?php
// An untyped param whose docblock says `array` is erased like a hinted one: an empty array is falsy
class C {
    public function make($a, array $parameters = []) { return $this->resolve($a, $parameters); }
    /**
     * @param  array  $parameters
     */
    protected function resolve($a, $parameters = [], $raise = true) {
        $needs = ! empty($parameters) || ! is_null($a);
        return ($parameters ? 'full' : 'empty') . ' ' . ($needs ? 'needs' : 'plain');
    }
}
$c = new C;
echo $c->make(null), "\n";
echo $c->make(null, [1]), "\n";
echo $c->make(1), "\n";
