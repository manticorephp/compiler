<?php
// `new $class` with the name of a class that does not exist returns null instead of throwing Error
function make(string $class) { return new $class(1); }
class Known { public function __construct(public int $a) {} }
echo make('Known')->a, "\n";
try {
    var_dump(make('NoSuchClass'));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
