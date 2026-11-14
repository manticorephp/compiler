<?php

namespace Lsp;

final class Uri
{
    public static function toPath(string $uri): ?string
    {
        if (\strncmp($uri, 'file://', 7) !== 0) { return null; }
        $rest = \substr($uri, 7);
        if ($rest !== '' && $rest[0] !== '/') {
            $slash = \strpos($rest, '/');
            $rest = $slash === false ? '' : \substr($rest, $slash);
        }
        return \rawurldecode($rest);
    }

    public static function fromPath(string $path): string
    {
        return 'file://' . \str_replace('%2F', '/', \rawurlencode($path));
    }
}
