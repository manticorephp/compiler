<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\BitOp;
use Compile\Mir\BitNot_;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayElement_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Spread_;
use Compile\Mir\Block;
use Compile\Mir\ClassDef;
use Compile\Mir\EnumDef;
use Compile\Mir\BoolConst;
use Compile\Mir\Break_;
use Compile\Mir\Call;
use Compile\Mir\Walk;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Continue_;
use Compile\Mir\Goto_;
use Compile\Mir\Label_;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
use Compile\Mir\FunctionDef;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Throw_;
use Compile\Mir\Yield_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\MethodCall_;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\NullConst;
use Compile\Mir\Param;
use Compile\Mir\Pass;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\StringConst;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\While_;
use Parser\Ast\Program;

/**
 * The synthesized prelude: PHP source the compiler builds for itself
 * (Throwable, the array classes, predefined constants, the CLI superglobals).
 *
 * A trait on the one {@see LowerFromAst} host — split by concern so a reader opens
 * the file for the thing they are looking at. State stays on the host.
 */
trait LowerPrelude
{
    /**
     * The prelude as PHP source, assembled from `prelude/*.php` and parsed once.
     *
     * Nothing here is a string literal in the compiler any more: the Throwable
     * hierarchy, the backtrace-frame builder, var_dump's recursive backend, the
     * SPL array classes, the array functions, the CLI superglobals and print_r
     * are ordinary PHP files, read by Main (see {@see \Manticore\find_prelude_src})
     * and gated by what the program actually demands ({@see \Compile\Mir\PreludeDemand}).
     *
     * `exceptions.php` is unconditional — every program can `throw`. It calls
     * `__mir_bt_frames`, which arrives from `backtrace.php` (a program that
     * queries a trace) or `backtrace_stub.php` (one that does not); Main picks
     * one, so the name is always defined exactly once.
     *
     * The one thing still GENERATED is `__mir_dump_object` — it is written from
     * the finished class table, so it cannot be a file. See {@see dumpObjectSrc}.
     *
     * @return \Parser\Ast\Stmt[]
     */
    private function preludeStatements(): array
    {
        $src = "<?php\n" . $this->exceptionsSrc . $this->backtraceSrc;
        if ($this->includeVarDump) {
            $src = $src . $this->varDumpSrc;
        }
        if ($this->includeArrayClasses) {
            $src = $src . $this->arrayClassesSrc;
        }
        if ($this->includeArrayFns) {
            $src = $src . $this->arrayFnsSrc;
        }
        if ($this->includeCli) {
            $src = $src . $this->cliSrc;
        }
        if ($this->includePrintR) {
            $src = $src . $this->printRSrc;
        }
        $program = \Parser\Parser::parseSource($src);
        return $program->statements;
    }

    /**
     * PHP source for `__mir_dump_object` — a class-aware var_dump for typed
     * objects, generated from the complete class table. Each known class gets
     * an `instanceof` branch (most-derived first, so a subclass is matched
     * before its base) that prints `object(Class)#1 (N) { ["prop"]=> ... }` over
     * its declared properties via the recursive `__mir_var_dump`. A dynamic
     * (stdClass / bag) object falls through to a bag walk. Clarity over strict
     * PHP parity: public-style keys (no visibility annotation), a fixed `#1` id.
     */
    private function dumpObjectSrc(): string
    {
        $names = [];
        $depths = [];
        foreach ($this->classTable as $cname => $cd) {
            if ($cname === 'stdClass') { continue; }
            if ($cd->isStruct) { continue; }
            // A `#[TypeDef]` has no object to dump — and the dumper is generated as
            // PHP SOURCE, so an arm for one would emit `$x instanceof U8` against a
            // class that no longer exists at runtime (CheckTypeDefs then rejects the
            // compiler's own generated code, blaming the user's program).
            if ($this->isTypeDef($cname)) { continue; }
            $names[] = $cname;
            $depths[] = $this->classDepth($cname);
        }
        // Selection sort by depth DESC (a subclass before its base).
        $n = \count($names);
        $i = 0;
        while ($i < $n) {
            $max = $i;
            $j = $i + 1;
            while ($j < $n) {
                if ($depths[$j] > $depths[$max]) { $max = $j; }
                $j = $j + 1;
            }
            if ($max !== $i) {
                $tn = $names[$i]; $names[$i] = $names[$max]; $names[$max] = $tn;
                $td = $depths[$i]; $depths[$i] = $depths[$max]; $depths[$max] = $td;
            }
            $i = $i + 1;
        }
        $body = "function __mir_dump_object(mixed \$v, int \$indent): void {\n"
            . "  \$pad = ''; \$jj = 0; while (\$jj < \$indent) { \$pad = \$pad . '  '; \$jj = \$jj + 1; }\n"
            // An enum-case singleton renders `enum(Enum::Case)` — detected via its
            // class descriptor before the instanceof walk (enums aren't classes here).
            . "  \$en = __mir_enum_name(\$v); if (\$en !== '') { echo 'enum(', \$en, \")\\n\"; return; }\n";
        $ci = 0;
        while ($ci < $n) {
            $cname = $names[$ci];
            $cd = $this->classTable[$cname];
            $props = $cd->propertyNames;
            $pc = (string)\count($props);
            // A reified specialization reports its ORIGIN's name (`Box`, not
            // `Box__of__float`) — but is still matched by its OWN name, so the
            // props read at the specialized (concrete) types. Depth-sorting puts
            // it before its origin, which is what makes the narrowing land here.
            $body = $body . "  if (\$v instanceof \\" . $cname . ") {\n"
                . "    echo 'object(" . $cd->display() . ")#1 (" . $pc . ") {' . \"\\n\";\n";
            foreach ($props as $p) {
                $body = $body . "    echo \$pad, '  [\"" . $p . "\"]=>', \"\\n\", \$pad, '  '; __mir_var_dump(\$v->" . $p . ", \$indent + 1);\n";
            }
            $body = $body . "    echo \$pad, \"}\\n\"; return;\n  }\n";
            $ci = $ci + 1;
        }
        $body = $body
            . "  \$arr = (array)\$v;\n"
            . "  echo 'object(stdClass)#1 (', (string)count(\$arr), \") {\\n\";\n"
            . "  foreach (\$arr as \$k => \$val) {\n"
            . "    echo \$pad, '  [\"', \$k, \"\\\"]=>\\n\", \$pad, '  ';\n"
            . "    __mir_var_dump(\$val, \$indent + 1);\n"
            . "  }\n"
            . "  echo \$pad, \"}\\n\";\n}\n";
        return $body;
    }

    private function injectCliSuperglobals(array $mainStmts): array
    {
        $readArgv = false; $readArgc = false;
        $setArgv = false; $setArgc = false;
        foreach ($mainStmts as $s) {
            if ($this->nodeReadsLocal($s, 'argv')) { $readArgv = true; }
            if ($this->nodeReadsLocal($s, 'argc')) { $readArgc = true; }
            if ($this->nodeWritesLocal($s, 'argv')) { $setArgv = true; }
            if ($this->nodeWritesLocal($s, 'argc')) { $setArgc = true; }
        }
        $pre = [];
        if ($readArgv && !$setArgv) {
            $pre[] = new StoreLocal(
                'argv',
                new Call('__mc_argv', [], Type::vec(Type::string_())),
                Type::vec(Type::string_()),
            );
        }
        if ($readArgc && !$setArgc) {
            $pre[] = new StoreLocal(
                'argc',
                new Call('__mir_argc', [], Type::int_()),
                Type::int_(),
            );
        }
        if ($pre === []) { return $mainStmts; }
        foreach ($mainStmts as $s) { $pre[] = $s; }
        return $pre;
    }

    /**
     * PHP predefined constants → a literal node, or null if `$name` is not a
     * known predefined. Covers the broadly-used core / math / flag families
     * (php.net/reserved.constants, math.constants, string.constants); values
     * are baked at compile time. INF/NAN ride a FloatConst (EmitLlvm emits the
     * exact bit pattern). User constants (define()) are handled separately.
     */
    private function predefinedConstant(string $name): ?Node
    {
        // PHP_INT_MAX/MIN are written out (too wide for some literal paths).
        if ($name === 'PHP_INT_MAX') { return new IntConst(9223372036854775807, Type::int_()); }
        if ($name === 'PHP_INT_MIN') { return new IntConst(-9223372036854775807 - 1, Type::int_()); }
        if ($name === 'INF') { return new FloatConst(\INF, Type::float_()); }
        if ($name === 'NAN') { return new FloatConst(\NAN, Type::float_()); }
        // Standard CLI stream resources (libc FILE*). A codegen builtin loads
        // the platform global so fwrite(STDOUT, ...) shares echo's buffer.
        if ($name === 'STDIN')  { return new Call('__mir_stdin',  [], Type::obj('Ffi\\Ptr')); }
        if ($name === 'STDOUT') { return new Call('__mir_stdout', [], Type::obj('Ffi\\Ptr')); }
        if ($name === 'STDERR') { return new Call('__mir_stderr', [], Type::obj('Ffi\\Ptr')); }

        $ints = [
            // string padding
            'STR_PAD_RIGHT' => 1, 'STR_PAD_LEFT' => 0, 'STR_PAD_BOTH' => 2,
            // sort flags
            'SORT_REGULAR' => 0, 'SORT_NUMERIC' => 1, 'SORT_STRING' => 2,
            'SORT_DESC' => 3, 'SORT_ASC' => 4, 'SORT_LOCALE_STRING' => 5,
            'SORT_NATURAL' => 6, 'SORT_FLAG_CASE' => 8,
            // count / array_filter
            'COUNT_NORMAL' => 0, 'COUNT_RECURSIVE' => 1,
            'ARRAY_FILTER_USE_KEY' => 2, 'ARRAY_FILTER_USE_BOTH' => 1,
            // round modes
            'PHP_ROUND_HALF_UP' => 1, 'PHP_ROUND_HALF_DOWN' => 2,
            'PHP_ROUND_HALF_EVEN' => 3, 'PHP_ROUND_HALF_ODD' => 4,
            // error reporting levels
            'E_ERROR' => 1, 'E_WARNING' => 2, 'E_PARSE' => 4, 'E_NOTICE' => 8,
            'E_CORE_ERROR' => 16, 'E_CORE_WARNING' => 32, 'E_COMPILE_ERROR' => 64,
            'E_COMPILE_WARNING' => 128, 'E_USER_ERROR' => 256, 'E_USER_WARNING' => 512,
            'E_USER_NOTICE' => 1024, 'E_STRICT' => 2048, 'E_RECOVERABLE_ERROR' => 4096,
            'E_DEPRECATED' => 8192, 'E_USER_DEPRECATED' => 16384, 'E_ALL' => 30719,
            // php core ints
            'PHP_INT_SIZE' => 8, 'PHP_VERSION_ID' => 80503, 'PHP_MAJOR_VERSION' => 8,
            'PHP_MINOR_VERSION' => 5, 'PHP_RELEASE_VERSION' => 3, 'PHP_FLOAT_DIG' => 15,
            'PHP_ZTS' => 0, 'PHP_DEBUG' => 0, 'PHP_MAXPATHLEN' => 1024,
            // json flags
            'JSON_HEX_TAG' => 1, 'JSON_HEX_AMP' => 2, 'JSON_HEX_APOS' => 4,
            'JSON_HEX_QUOT' => 8, 'JSON_FORCE_OBJECT' => 16, 'JSON_NUMERIC_CHECK' => 32,
            'JSON_UNESCAPED_SLASHES' => 64, 'JSON_PRETTY_PRINT' => 128,
            'JSON_UNESCAPED_UNICODE' => 256, 'JSON_PARTIAL_OUTPUT_ON_ERROR' => 512,
            'JSON_PRESERVE_ZERO_FRACTION' => 1024, 'JSON_INVALID_UTF8_IGNORE' => 1048576,
            'JSON_INVALID_UTF8_SUBSTITUTE' => 2097152, 'JSON_THROW_ON_ERROR' => 4194304,
            'JSON_OBJECT_AS_ARRAY' => 1, 'JSON_BIGINT_AS_STRING' => 2, 'JSON_ERROR_NONE' => 0,
            // preg flags
            'PREG_PATTERN_ORDER' => 1, 'PREG_SET_ORDER' => 2, 'PREG_OFFSET_CAPTURE' => 256,
            'PREG_UNMATCHED_AS_NULL' => 512, 'PREG_SPLIT_NO_EMPTY' => 1,
            'PREG_SPLIT_DELIM_CAPTURE' => 2, 'PREG_SPLIT_OFFSET_CAPTURE' => 4,
            'PREG_GREP_INVERT' => 1,
            // preg_last_error() codes
            'PREG_NO_ERROR' => 0, 'PREG_INTERNAL_ERROR' => 1,
            'PREG_BACKTRACK_LIMIT_ERROR' => 2, 'PREG_RECURSION_LIMIT_ERROR' => 3,
            'PREG_BAD_UTF8_ERROR' => 4, 'PREG_BAD_UTF8_OFFSET_ERROR' => 5,
            'PREG_JIT_STACKLIMIT_ERROR' => 6,
            // htmlspecialchars / entities (common subset)
            'ENT_NOQUOTES' => 0, 'ENT_COMPAT' => 2, 'ENT_QUOTES' => 3, 'ENT_HTML5' => 48,
            // filesystem: fseek whence + file_put_contents / flock flags
            'SEEK_SET' => 0, 'SEEK_CUR' => 1, 'SEEK_END' => 2,
            'FILE_USE_INCLUDE_PATH' => 1, 'FILE_APPEND' => 8,
            'FILE_IGNORE_NEW_LINES' => 2, 'FILE_SKIP_EMPTY_LINES' => 4, 'FILE_NO_DEFAULT_CONTEXT' => 16,
            'LOCK_SH' => 1, 'LOCK_EX' => 2, 'LOCK_UN' => 3,
            'PATHINFO_DIRNAME' => 1, 'PATHINFO_BASENAME' => 2,
            'PATHINFO_EXTENSION' => 4, 'PATHINFO_FILENAME' => 8, 'PATHINFO_ALL' => 15,
        ];
        if (isset($ints[$name])) { return new IntConst($ints[$name], Type::int_()); }

        $floats = [
            'M_PI' => 3.14159265358979323846, 'M_E' => 2.7182818284590452354,
            'M_SQRT2' => 1.41421356237309504880, 'M_SQRT1_2' => 0.70710678118654752440,
            'M_SQRT3' => 1.7320508075688772935, 'M_2_SQRTPI' => 1.12837916709551257390,
            'M_SQRTPI' => 1.77245385090551602729, 'M_1_PI' => 0.31830988618379067154,
            'M_2_PI' => 0.63661977236758134308, 'M_PI_2' => 1.57079632679489661923,
            'M_PI_4' => 0.78539816339744830962, 'M_LN2' => 0.69314718055994530942,
            'M_LN10' => 2.30258509299404568402, 'M_LOG2E' => 1.4426950408889634074,
            'M_LOG10E' => 0.43429448190325182765, 'M_EULER' => 0.57721566490153286061,
            'PHP_FLOAT_EPSILON' => 2.2204460492503131E-16,
            'PHP_FLOAT_MAX' => 1.7976931348623157E+308,
            'PHP_FLOAT_MIN' => 2.2250738585072014E-308,
        ];
        if (isset($floats[$name])) { return new FloatConst($floats[$name], Type::float_()); }

        $strs = [
            'PHP_EOL' => "\n", 'DIRECTORY_SEPARATOR' => '/', 'PATH_SEPARATOR' => ':',
            'PHP_VERSION' => '8.5.3', 'PHP_SAPI' => 'cli', 'PHP_EXTRA_VERSION' => '',
        ];
        if (isset($strs[$name])) { return new StringConst($strs[$name], Type::string_()); }

        // Host-target OS, detected at compile time via libc uname(2) — the
        // sysname ("Darwin" / "Linux") is both PHP_OS and PHP_OS_FAMILY for the
        // two supported targets, matching the interpreter on the build host.
        if ($name === 'PHP_OS' || $name === 'PHP_OS_FAMILY') {
            $os = \Manticore\host_os();
            $os = \substr($os, 0, 6) === 'Darwin' ? 'Darwin'
                : (\substr($os, 0, 5) === 'Linux' ? 'Linux' : $os);
            return new StringConst($os, Type::string_());
        }

        return null;
    }
}
