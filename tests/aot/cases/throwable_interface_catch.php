<?php
try {
    throw new RuntimeException("boom");
} catch (\Throwable $e) {
    echo $e->getMessage();
}
