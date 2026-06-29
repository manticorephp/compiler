<?php

namespace Manticore;

use Parser\Parser;

/**
 * `.manticore.php` loader.
 *
 * The config file is a regular PHP file declaring a class annotated
 * with `#[Manticore\Attr\Project('name')]`. Each property tagged
 * `#[Module('relative/path')]` registers a source-root directory we
 * recursively scan for `*.php` files. One property tagged `#[Entry]`
 * picks the entry-point file (its string default is the path).
 *
 * Loader returns a flat list of source contents in compile order:
 * every module file first, entry file last (so its top-level
 * statements lower into `main()` after every class/function has been
 * registered, matching `bin/compile`'s sort-zzz-last convention).
 *
 * Module paths + entry land in static class properties — local
 * `string[]` accumulators don't survive the self-host compiler's
 * element-type inference today, so we lean on typed statics instead.
 */
final class Manifest
{
    /** @var string[] */
    public array $modulePaths = [];
    public ?string $entryPath = null;

    /** @return string[]|null */
    public static function loadSources(string $manifestPath): ?array
    {
        $self = new self();
        return $self->load($manifestPath);
    }

    /** @return string[]|null */
    public function load(string $manifestPath): ?array
    {
        $configSrc = read_file($manifestPath);
        if ($configSrc === null) { return null; }

        try {
            $program = Parser::parseSource($configSrc);
        } catch (\Throwable $e) {
            dprint("manifest parse failed: " . $e->getMessage());
            return null;
        }

        foreach ($program->statements as $stmt) {
            if (!($stmt instanceof \Parser\Ast\ClassStmt)) { continue; }
            $decl = self::stmtDecl($stmt);
            if (!self::hasAttribute($decl->attributes, 'Project')) { continue; }
            $this->collectFromClass($decl);
        }

        /** @var string[] $sources */
        $sources = [];
        // Inline file discovery — going through a helper that returns
        // `string[]` would let the bootstrap compiler narrow the
        // element type to i64 on the way back across the call
        // boundary, so we keep the explode loop here where the vec
        // append stays in one frame.
        $listPath = "/tmp/manticore_files_" . (string)getpid() . ".txt";
        foreach ($this->modulePaths as $dir) {
            system("find " . $dir . " -name '*.php' -type f 2>/dev/null | sort > " . $listPath);
            $contents = read_file($listPath);
            if ($contents === null) { continue; }
            foreach (\explode("\n", $contents) as $f) {
                if (\strlen($f) === 0) { continue; }
                if ($this->entryPath !== null && $f === $this->entryPath) { continue; }
                $src = read_file($f);
                if ($src !== null) { $sources[] = $src; }
            }
        }
        if ($this->entryPath !== null) {
            $src = read_file($this->entryPath);
            if ($src !== null) { $sources[] = $src; }
        }
        return $sources;
    }

    /**
     * Walk a `#[Project]`-tagged class declaration once, harvesting
     * module paths and the entry file into the instance state. Object
     * properties (vs class statics) play nicer with the bootstrap
     * compiler's `$x[] = …` append lowering today.
     */
    private function collectFromClass(\Parser\Ast\ClassDecl $decl): void
    {
        foreach ($decl->properties as $prop) {
            foreach ($prop->attributes as $attr) {
                $short = self::shortName($attr->name);
                if ($short === 'Module') {
                    if (\count($attr->args) > 0) {
                        $arg0 = $attr->args[0];
                        if ($arg0 instanceof \Parser\Ast\StringLiteral) {
                            $this->modulePaths[] = $arg0->value;
                        }
                    }
                } elseif ($short === 'Entry') {
                    $def = $prop->default;
                    if ($def instanceof \Parser\Ast\StringLiteral) {
                        $this->entryPath = $def->value;
                    }
                }
            }
        }
    }

    /**
     * Pull the `decl` field from a ClassStmt explicitly so the
     * compiler keeps the `ClassDecl` class tag attached. Reading
     * `$stmt->decl` straight from the Stmt-element foreach loses the
     * subclass-narrowed class info on the way out.
     */
    private static function stmtDecl(\Parser\Ast\ClassStmt $stmt): \Parser\Ast\ClassDecl
    {
        return $stmt->decl;
    }

    /**
     * @param \Parser\Ast\AttributeNode[] $attrs
     */
    private static function hasAttribute(array $attrs, string $shortName): bool
    {
        foreach ($attrs as $a) {
            if (self::shortName($a->name) === $shortName) { return true; }
        }
        return false;
    }

    /**
     * Drop namespace prefix from a `Foo\Bar\Baz` attribute name so we
     * can match by short tag without knowing how user code aliased it.
     */
    private static function shortName(string $name): string
    {
        $clean = \ltrim($name, '\\');
        if (\str_contains($clean, '\\')) {
            $clean = \substr($clean, \strrpos($clean, '\\') + 1);
        }
        return $clean;
    }

}
