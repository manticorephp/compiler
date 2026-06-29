<?php
try {
    throw new RuntimeException("not found");
} catch (Exception $e) {
    echo $e->getMessage();
}
