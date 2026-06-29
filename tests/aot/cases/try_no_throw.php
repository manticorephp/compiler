<?php
try {
    echo "ran";
} catch (\Throwable $e) {
    echo "should-not";
}
