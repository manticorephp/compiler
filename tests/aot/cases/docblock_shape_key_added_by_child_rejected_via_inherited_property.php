<?php
// A key the child docblock adds to the parent @param array shape is rejected as missing when read through the inherited property
class Box { public function __construct(public int $n) {} }
class Base {
    protected array $options;
    /** @param array{a?: ?bool} $options */
    public function __construct(array $options = []) { $this->options = $options; }
}
class Child extends Base {
    /** @param array{a?: ?bool, b?: int} $options */
    public function __construct(array $options = []) {
        $options['b'] = 7;
        parent::__construct($options);
    }
    public function box(): Box { return new Box($this->options['b']); }
}
echo (new Child(['a' => 1]))->box()->n, "\n";
