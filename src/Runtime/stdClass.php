<?php

/**
 * The built-in empty class. Declared here (global namespace, linked into
 * the compiler runtime) rather than synthesised inside LowerFromAst, so
 * both backends register a real `stdClass` and `(object)` casts /
 * json_decode can name it.
 *
 *   #[AllowDynamicProperties]  — carries a dynamic-property bag so
 *                                `$o->$key = …` and `(object)$assoc` work.
 *
 * ONE layout, the one every program synthesises for itself (class id, rc,
 * bag — {@see \Compile\Mir\Passes\LowerFromAst}): the descriptor is
 * `linkonce_odr` and coalesces with the program's. It was `#[Struct]` once —
 * a bag-only value layout — so an object this library built (the compiled
 * JSON parser's `(object)$o`) reached the program under a layout its readers
 * did not share and read back as `(0) {}`.
 */
#[AllowDynamicProperties]
class stdClass {}
