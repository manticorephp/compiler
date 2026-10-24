<?php
// Http\Status constants and helpers, Http\Method cases and predicates.

use Http\{Method, Status};

echo Status::NOT_FOUND, ' ', Status::text(Status::NOT_FOUND), "\n";
echo Status::text(299) === '' ? "299 has no phrase\n" : "unexpected\n";

foreach ([200, 204, 301, 304, 404] as $code) {
    echo $code, ': redirect=', var_export(Status::isRedirect($code), true),
        ' body=', var_export(Status::hasBody($code), true), "\n";
}

foreach (Method::cases() as $m) {
    echo str_pad($m->value, 8),
        'safe=', var_export($m->isSafe(), true),
        ' idempotent=', var_export($m->isIdempotent(), true),
        ' body=', var_export($m->allowsBody(), true), "\n";
}
var_dump(Method::tryFrom('PROPFIND'));
