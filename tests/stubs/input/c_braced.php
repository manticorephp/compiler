<?php

namespace Lib\Util {
    const VERSION = '1.0';

    function slug(string $s): string
    {
        return strtolower($s);
    }

    try {
        function maybe(): int { return 1; }
    } catch (\Throwable $e) {
        echo $e->getMessage();
    }
}

namespace {
    function global_fn(): bool { return true; }

    $unused = global_fn();
}
