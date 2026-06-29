<?php

/**
 * The built-in empty class. Declared here (global namespace, linked into
 * the compiler runtime) rather than synthesised inside LowerFromAst, so
 * both backends register a real `stdClass` and `(object)` casts /
 * json_decode can name it.
 *
 *   #[Struct]                  — value layout, no class-id / rc header.
 *   #[AllowDynamicProperties]  — carries a dynamic-property bag so
 *                                `$o->$key = …` and `(object)$assoc` work.
 */
#[Struct, AllowDynamicProperties]
class stdClass {}
