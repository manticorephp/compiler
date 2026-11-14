<?php
function deadMaker() { return function () { return new Missing\InDeadClosure(); }; }
class W { public function run() { return function () { return new Missing\InMethodClosure(); }; } }
$g = fn() => MISSING_ARROW_CONST;
(new W())->run();
echo "ok\n";
