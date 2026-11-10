<?php
new Missing\A();
Missing\B::make();
echo Missing\C::K;
function f($x) { return $x instanceof Missing\D; }
try { echo 1; } catch (Missing\E | \RuntimeException $e) {}
new Missing\A();
echo NO_SUCH_CONST;
