<?php
try {
    echo "try;";
    throw new Exception("x");
} catch (\Throwable $e) {
    echo "caught:", $e->getMessage(), ";";
} finally {
    echo "finally";
}
