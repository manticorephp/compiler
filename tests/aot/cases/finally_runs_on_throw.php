<?php
try {
    try {
        echo "try;";
        throw new Exception("boom");
    } finally {
        echo "finally;";
    }
} catch (\Throwable $e) {
    echo "caught:", $e->getMessage();
}
