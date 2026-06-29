<?php
try {
    try {
        throw new Exception("inner");
    } catch (\Throwable $e) {
        echo "inner-caught:", $e->getMessage(), ";";
        throw new Exception("rethrown");
    }
} catch (\Throwable $e) {
    echo "outer-caught:", $e->getMessage();
}
