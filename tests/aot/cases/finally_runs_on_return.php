<?php
// finally runs when the try block returns or throws-then-catches, and nested
// finallys run inner-first. Regression guard for the return-in-try lowering.
function ret() { try { return "R"; } finally { echo "f1|"; } }
function nested() { try { try { return "N"; } finally { echo "in|"; } } finally { echo "out|"; } }
function loop() {
    foreach ([1, 2, 3] as $x) {
        try { if ($x === 2) { return "got2"; } } finally { echo "L$x|"; }
    }
    return "none";
}
function thrown() { try { throw new RuntimeException("boom"); } finally { echo "ft|"; } }
echo ret(), "\n";
echo nested(), "\n";
echo loop(), "\n";
try { thrown(); } catch (Exception $e) { echo "caught:", $e->getMessage(), "\n"; }
// depth intact after a return-through-finally:
echo ret(), " ";
try { throw new Exception("z"); } catch (Exception $e) { echo "still-ok\n"; }
