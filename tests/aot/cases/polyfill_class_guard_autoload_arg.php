<?php

// The polyfill idiom: `class_exists($name, false)` with the autoload flag, and a
// guard that names the very class its own branch declares.
if (!class_exists('ValueError', false)) {
    class ValueError extends Error
    {
    }
}

if (!class_exists('PolyfillOnly', false)) {
    class PolyfillOnly
    {
        public function hi(): string { return 'polyfill'; }
    }
}

if (!class_exists('PolyfillToo')) {
    class PolyfillToo
    {
        public function hi(): string { return 'too'; }
    }
}

if (class_exists('PolyfillOnly', false)) {
    echo "seen after its branch\n";
}

var_dump(class_exists('ValueError', false));
echo (new PolyfillOnly())->hi(), ' ', (new PolyfillToo())->hi(), "\n";
try {
    throw new ValueError('v');
} catch (ValueError $e) {
    echo get_class($e), ' ', $e->getMessage(), "\n";
}
