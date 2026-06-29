<?php
function bad() { throw new Exception("broken"); }
try {
    echo "before;";
    bad();
    echo "unreachable;";
} catch (\Throwable $e) {
    echo "caught:", $e->getMessage();
}
echo ";after";
