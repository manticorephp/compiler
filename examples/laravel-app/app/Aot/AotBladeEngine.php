<?php

namespace App\Aot;

use Illuminate\View\Engines\CompilerEngine;

/**
 * Renders Blade views that were compiled to PHP functions at build time
 * (`php aot/compile-views.php`). The stock engine `include`s a compiled file and
 * `extract()`s the data into its scope, neither of which exists in a native binary.
 *
 * It extends CompilerEngine only so code that inspects the engine (the dev error
 * renderer reads `lastCompiled` and `getCompiler()`) keeps working.
 */
class AotBladeEngine extends CompilerEngine
{
    /** The project-relative path with `.` / `..` segments folded (a provider hands over `Providers/../resources/…`). */
    private static function relative(string $path): string
    {
        $base = base_path();
        if (str_starts_with($path, $base)) { $path = substr($path, strlen($base)); }
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') { continue; }
            if ($seg === '..') { array_pop($out); continue; }
            $out[] = $seg;
        }

        return implode('/', $out);
    }

    public function get($path, array $data = [])
    {
        $this->lastCompiled[] = $path;
        $rel = self::relative((string) $path);
        $out = \aot_view_render($rel, $data);
        array_pop($this->lastCompiled);
        if ($out === null) {
            throw new \RuntimeException("View [$rel] was not precompiled; run `php aot/compile-views.php`.");
        }

        return $out;
    }
}
