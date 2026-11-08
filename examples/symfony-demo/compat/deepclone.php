<?php

/**
 * symfony/polyfill-deepclone rebuilds object graphs through Closure::bind() and reflection hacks that a compiled binary
 * does not carry. A deep clone is exactly serialize() + unserialize(), so the payload carries the serialized string, plus
 * the few keys symfony/var-exporter reads to clone an object AS another class (`prepared`, `classes`, `objectMeta`).
 */

function deepclone_to_array(mixed $value, ?array $allowed_classes = null, bool $allow_named_closures = false): array
{
    $payload = ['serialized' => serialize($value)];
    if (is_object($value)) {
        $payload['prepared'] = 0;
        $payload['classes'] = [get_class($value)];
        $payload['objectMeta'] = 1;
    }
    return $payload;
}

function deepclone_from_array(array $data, ?array $allowed_classes = null, bool $allow_named_closures = false): mixed
{
    $s = $data['serialized'];
    $classes = $data['classes'] ?? null;
    $meta = $data['objectMeta'] ?? null;
    $root = $data['prepared'] ?? -1;
    if (is_array($classes) && is_array($meta) && $root >= 0 && isset($meta[$root])) {
        $entry = $meta[$root];
        $idx = is_array($entry) ? $entry[0] : $entry;
        $target = $classes[$idx] ?? $classes[0];
        $orig = $classes[0];
        if ($target !== $orig && str_starts_with($s, 'O:' . strlen($orig) . ':"' . $orig . '":')) {
            $s = 'O:' . strlen($target) . ':"' . $target . '":' . substr($s, strlen('O:' . strlen($orig) . ':"' . $orig . '":'));
        }
    }
    return unserialize($s, ['allowed_classes' => $allowed_classes ?? true]);
}
