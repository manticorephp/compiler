<?php

namespace Analyze;

/**
 * A resolved input file: its path (as the user named it, kept verbatim so
 * diagnostics point where they typed) and its full contents. A typed-field
 * object, not an `array<string,string>` — an associative array loses its
 * element types across a self-host call boundary.
 */
final class SourceFile
{
    public function __construct(
        public string $path,
        public string $contents,
    ) {}
}
