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

    /** The one spelling of a file URI (percent-encoding differs between clients); other schemes pass through. */
    public static function normalize(string $uri): string
    {
        $p = self::toPath($uri);
        return $p === null ? $uri : self::fromPath($p);
    }

    public static function fromPath(string $path): string
    {
        return 'file://' . \str_replace('%2F', '/', \rawurlencode($path));
    }
}
