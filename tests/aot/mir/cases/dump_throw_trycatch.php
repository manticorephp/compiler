<?php
function guarded(bool $fail): string {
    try {
        if ($fail) {
            throw new RuntimeException("boom");
        }
        return "ok";
    } catch (RuntimeException $e) {
        return $e->getMessage();
    } finally {
        echo "[done]";
    }
}
echo guarded(true);
