<?php

namespace Ffi;

use Attribute;

/**
 * The native library an FFI binding's C symbol lives in — and what puts that
 * library on the link line.
 *
 * `'c'` is implicit (libc / libSystem is always linked) and never becomes a
 * flag. Any other name resolves through `pkg-config --libs <name>`, then
 * `<name>-config --libs`, then a bare `-l<name>`; the probe order is what makes
 * OpenSSL and PCRE2 link on a host where Homebrew keeps them off the default
 * search path.
 *
 * Requirements are collected per EMITTED WRAPPER and carried in the module's
 * `.sig`, because linking is a whole-program property while a wrapper is
 * emitted once, in the module owning the source: a program calling `preg_match`
 * gets the pcre2 wrapper out of `lib/manticore_stdlib.o` and has no
 * `#[Library]` of its own to derive `-lpcre2-8` from.
 *
 * The manifest's `extensions[].link` remains the escape hatch, for a library no
 * `#[Library]` names or one whose flags are not a bare `-l`. Both sources are
 * deduped by `-l<name>`. See docs/ffi.md.
 */
#[Attribute(Attribute::TARGET_FUNCTION)]
final class Library
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $version = null,
    ) {}
}
