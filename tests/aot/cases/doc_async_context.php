<?php

use Async\Context;
use function Async\async;
use function Async\awaitAll;
use function Async\spawn;
use function Async\timeout;

async(function () {
    // Values are scoped: every task spawned inside sees them, nothing outside does.
    Context::withValue('request-id', 'r-42', function () {
        $t = spawn(fn() => Context::value('request-id'));
        echo "inside: ", $t->await(), "\n";
    });
    var_dump(Context::value('request-id'));

    // No deadline means unbounded.
    var_dump(Context::remaining());

    // A timeout() scope publishes its deadline.
    timeout(5.0, function () {
        $left = Context::remaining();
        echo "deadline set: ", ($left !== null && $left > 0.0 && $left <= 5.0) ? "yes" : "no", "\n";
        echo "cancelled: ", Context::isCancelled() ? "yes" : "no", "\n";
    });
});
