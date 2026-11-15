<?php

namespace Semantic;

use Analyze\ParsedFile;

/**
 * The analyzer's library universe: the library stubs (declarations of the prelude and
 * signatures of the stdlib `.o.sig`, {@see sections}) parsed, and the stdlib function names.
 *
 * Prelude stubs are labelled `lib:prelude/<name>.php`, the stdlib's `lib:stubs/stdlib.php`.
 * A label is not a disk path. A prelude stub keeps every declaration on its line in the
 * prelude file, so a host opens the real file (`\Manticore\find_prelude_path`) at the same
 * position when there is one, and the stub text otherwise.
 */
final class LibrarySymbols
{
    /** Reserved label prefix of every library file; a Workspace refuses it as a user URI. */
    public const LABEL_PREFIX = 'lib:';

    /** The library stubs file, shipped in lib/ beside the prelude ({@see sections}). */
    public const STUBS_FILE = "manticore.stubs.php";

    /** Label of the stdlib signature section. */
    public const STDLIB_LABEL = "stubs/stdlib.php";

    private static ?LibrarySymbols $loaded = null;

    /** @var array<string, string>|null label => stub source, once read */
    private static ?array $sections = null;

    /** @var ParsedFile[] */
    private array $prelude;

    /** @var string[] */
    private array $fnNames;

    /**
     * @param ParsedFile[] $prelude
     * @param string[] $fnNames
     */
    private function __construct(array $prelude, array $fnNames)
    {
        $this->prelude = $prelude;
        $this->fnNames = $fnNames;
    }

    /**
     * @param ParsedFile[] $prelude
     * @param string[] $fnNames
     */
    public static function fromParts(array $prelude, array $fnNames): LibrarySymbols
    {
        return new LibrarySymbols($prelude, $fnNames);
    }

    public static function load(): LibrarySymbols
    {
        if (self::$loaded === null) {
            self::$loaded = new LibrarySymbols(self::readPrelude(), self::readStdlibNames());
        }
        return self::$loaded;
    }

    /** @return ParsedFile[] */
    public function preludeFiles(): array
    {
        return $this->prelude;
    }

    /** @return string[] */
    public function stdlibFunctionNames(): array
    {
        return $this->fnNames;
    }

    /**
     * Stdlib function names, lowercased. Global declarations are taken as-is.
     * Namespaced ones are NOT simply dropped: `LowerFromAst::lowerProgram`
     * (LowerFromAst.php:822-841) registers a bare-name alias for every namespaced
     * extern whose bare name is unique, and `resolveCallName` (:1826) resolves an
     * unqualified call through it. So an unqualified `strncmp()` really does bind
     * to `Runtime\Libc\strncmp`, and the analyzer must model that or it reports a
     * call that compiles and runs.
     *
     * The alias set is deliberately the SAME shape the lowering computes, including
     * the collision rule (two namespaced decls sharing a bare name cancel each
     * other out and no alias is registered).
     *
     * ⚠ Modelling this here makes the analyzer quiet about those names. Whether a
     * given capture is CORRECT is a separate question — `tools/audit/alias_scan.php`
     * answers it, and reports the ones that bind a raw C function under a PHP name.
     *
     * @param string[] $declNames
     * @return string[]
     */
    public static function normalizeNames(array $declNames): array
    {
        /** @var string[] $out */
        $out = [];
        /** @var array<string,int> $bareCount bare name -> namespaced decls seen */
        $bareCount = [];
        /** @var string[] $bareNames */
        $bareNames = [];
        foreach ($declNames as $name) {
            $pos = \strrpos($name, "\\");
            if ($pos === false) {
                $out[] = \strtolower($name);
                continue;
            }
            $bare = \strtolower(\substr($name, $pos + 1));
            if (!isset($bareCount[$bare])) {
                $bareCount[$bare] = 0;
                $bareNames[] = $bare;
            }
            $bareCount[$bare] = $bareCount[$bare] + 1;
        }
        // Only a UNIQUE bare name becomes an alias — mirrors the `isset(...) ? ''`
        // collision guard in the lowering.
        foreach ($bareNames as $bare) {
            if ($bareCount[$bare] === 1) {
                $out[] = $bare;
            }
        }
        return $out;
    }

    /**
     * Stdlib function names from the stdlib stub section, for a binary with no `.sig` beside it
     * (a bare copy): the names a user program can call, less the internal ones the stubs leave out.
     *
     * @return string[]
     */
    private static function stubFunctionNames(): array
    {
        /** @var ParsedFile[] $files */
        $files = [];
        try {
            foreach (self::sections() as $label => $src) {
                if (!\str_starts_with((string)$label, "prelude/")) {
                    $files[] = new ParsedFile(self::LABEL_PREFIX . (string)$label, \Parser\Parser::parseSource($src));
                }
            }
            /** @var string[] $declNames */
            $declNames = [];
            $idx = \Analyze\Index::build($files);
            foreach ($idx->functions as $fn) {
                $declNames[] = $fn->name;
            }
            return self::normalizeNames($declNames);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return string[] */
    private static function readStdlibNames(): array
    {
        $path = \Manticore\find_stdlib_sig();
        $json = $path === "" ? null : \Manticore\read_file($path);
        if ($json === null) {
            return self::stubFunctionNames();
        }
        try {
            /** @var string[] $declNames */
            $declNames = [];
            foreach (\Manticore\Sig::declsFromJson($json) as $decl) {
                $declNames[] = $decl->name;
            }
            return self::normalizeNames($declNames);
        } catch (\Throwable $e) {
            // A malformed sig just yields no stdlib names (rule stays conservative).
            return [];
        }
    }

    /**
     * The library the analyzer knows, as stub sources: the prelude files and the stdlib.
     *
     * @return array<string, string> label (`prelude/<name>.php`, `stdlib/<File>.php`, `stubs/stdlib.php`) => stub source
     */
    public static function librarySources(): array
    {
        return self::sections();
    }

    /** The stub source of one library label (`prelude/<name>.php`, `stdlib/<File>.php`, `stubs/stdlib.php`), or null. */
    public static function sectionSource(string $label): ?string
    {
        return self::sections()[$label] ?? null;
    }

    /**
     * The library stubs: one section per prelude file, one per stdlib source file, and one of
     * `.sig` signatures for the stdlib names the source did not cover.
     *
     * Read from the stubs file (MANTICORE_STUBS, else `manticore.stubs.php` beside the binary,
     * else the copy embedded in it), then checked against the prelude the compiler would read:
     * a prelude file found on disk whose hash differs from its section's (an edited dev tree)
     * is re-stubbed from that file. A bare binary has no prelude on disk and uses its copy as is.
     *
     * @return array<string, string> label => stub source
     */
    public static function sections(): array
    {
        if (self::$sections !== null) {
            return self::$sections;
        }
        $text = self::stubsText();
        /** @var array<string, string> $have */
        $have = $text === "" ? [] : Stubber::split($text);
        /** @var array<string, string> $out */
        $out = [];
        $st = new Stubber();
        foreach (self::preludeFileNames() as $name) {
            $label = "prelude/" . $name;
            $path = \Manticore\find_prelude_path($name);
            $src = $path === "" ? false : @\file_get_contents($path);
            if (\is_string($src) && $src !== "") {
                $old = $have[$label] ?? "";
                if ($old !== "" && Stubber::sectionHash($old) === Stubber::sourceHash($src)) {
                    $out[$label] = $old;
                    continue;
                }
                try {
                    $one = Stubber::split("<?php\n" . $st->stubFile($src, true, $label));
                    if (isset($one[$label])) { $out[$label] = $one[$label]; }
                } catch (\Throwable $e) {
                    // A prelude file that does not lex is skipped, as it always was.
                }
                continue;
            }
            if (isset($have[$label])) { $out[$label] = $have[$label]; }
        }
        foreach ($have as $label => $src) {
            if (!\str_starts_with((string)$label, "prelude/")) { $out[(string)$label] = $src; }
        }
        if ($text === "") {
            $sig = self::stdlibSection($st);
            if ($sig !== "") {
                $one = Stubber::split("<?php\n" . $sig);
                if (isset($one[self::STDLIB_LABEL])) { $out[self::STDLIB_LABEL] = $one[self::STDLIB_LABEL]; }
            }
        }
        self::$sections = $out;
        return $out;
    }

    /**
     * The library stubs file: `manticore stubs --library [stdlib sources…]`, which `bin/build`
     * runs. The prelude the compiler resolves, line for line; then the stdlib from its source
     * ($stdlibFiles, path => contents), only what its `.sig` exports, docblocks and exact types
     * kept; then signatures from the `.sig` for every exported name the source did not declare
     * (all of them when no source is given). Throws when a file does not lex.
     *
     * @param array<string, string> $stdlibFiles
     */
    public static function generate(array $stdlibFiles = []): string
    {
        $st = new Stubber();
        /** @var string[] $parts */
        $parts = [];
        foreach (self::preludeFileNames() as $name) {
            $path = \Manticore\find_prelude_path($name);
            $src = $path === "" ? false : @\file_get_contents($path);
            if (\is_string($src) && $src !== "") {
                $parts[] = $st->stubFile($src, true, "prelude/" . $name);
            }
        }
        $path = \Manticore\find_stdlib_sig();
        $json = $path === "" ? null : \Manticore\read_file($path);
        if ($json !== null && $json !== "" && \count($stdlibFiles) > 0) {
            $fns = Stubber::exportedFunctions($json);
            foreach ($st->functions as $k => $_) { unset($fns[$k]); }
            $classes = Stubber::exportedClasses($json);
            foreach ($st->classes as $k => $_) { unset($classes[$k]); }
            $st->onlyFns = $fns;
            $st->onlyClasses = $classes;
            foreach ($stdlibFiles as $file => $src) {
                $part = $st->stubFile($src, false, "stdlib/" . (string)$file);
                if ($part !== "") { $parts[] = $part; }
            }
            $st->onlyFns = null;
            $st->onlyClasses = null;
        }
        $sig = self::stdlibSection($st);
        if ($sig !== "") { $parts[] = $sig; }
        return Stubber::merge($parts);
    }

    /** The stdlib section, signatures from the bundled `.sig`; "" when there is none. */
    private static function stdlibSection(Stubber $st): string
    {
        $path = \Manticore\find_stdlib_sig();
        $json = $path === "" ? null : \Manticore\read_file($path);
        if ($json === null || $json === "") {
            return "";
        }
        try {
            return $st->sigSection($json, self::STDLIB_LABEL);
        } catch (\Throwable $e) {
            return "";
        }
    }

    /** The stubs file text: MANTICORE_STUBS, else beside the binary, else embedded; "" when none. */
    private static function stubsText(): string
    {
        $env = \getenv("MANTICORE_STUBS");
        /** @var string[] $cands */
        $cands = [];
        if (\is_string($env) && $env !== "") {
            $cands[] = $env;
        }
        $dir = \Manticore\self_dir();
        if ($dir !== "") {
            $cands[] = $dir . "/../lib/" . self::STUBS_FILE;
            $cands[] = $dir . "/lib/" . self::STUBS_FILE;
            $cands[] = $dir . "/" . self::STUBS_FILE;
        }
        foreach ($cands as $p) {
            $t = @\file_get_contents($p);
            if (\is_string($t) && $t !== "") {
                return $t;
            }
        }
        return \Manticore\EmbeddedStubs::TEXT;
    }

    /** @return ParsedFile[] */
    private static function readPrelude(): array
    {
        /** @var ParsedFile[] $out */
        $out = [];
        foreach (self::sections() as $label => $src) {
            try {
                $out[] = new ParsedFile(self::LABEL_PREFIX . (string)$label, \Parser\Parser::parseSource($src));
            } catch (\Throwable $e) {
                // A section that does not parse is simply skipped.
            }
        }
        return $out;
    }

    /**
     * The prelude file set (stable). Class-defining files matter most for the
     * undefined-class rule; loading all also seeds prelude functions.
     * `tools/audit/calibrate.sh` gates this list against `prelude/*.php`.
     *
     * @return string[]
     */
    private static function preludeFileNames(): array
    {
        $names = [
            "core_interfaces.php",
            "exceptions.php",
            "resource.php",
            "reflection.php",
            "spl_arrays.php",
            "spl_iterators.php",
            "array_fns.php",
            "backtrace.php",
            "cli.php",
            "print_r.php",
            "var_dump.php",
            "datetime.php",
            "errors.php",
            "binary.php",
            "serialize.php",
            "unserialize.php",
            // \Fiber (fiber.php) and the Io\Poll\* class tree (io_poll.php) are
            // DEMAND-GATED at compile time (Main::lower_module), but the analyzer's
            // undefined-symbol rules run closed-world across the whole source set —
            // so they need every prelude class the user program can name. Without
            // these, `new \Fiber(...)`, an `\Io\Poll\Context` hint, or the
            // `StreamPollHandle` handle read as unknown classes.
            "fiber.php",
            "io_poll.php",
            "async.php",
            "pcntl.php",
            "random.php",
            "bcmath.php",
            "overloadable.php",
            // Function-only prelude files. Omitting these is invisible in a normal
            // build (they are injected on demand all the same) but makes the
            // closed-world undefined-function rule report every one of their
            // declarations — `array_splice`, `array_replace_recursive`,
            // `array_walk_recursive`, `array_diff_ukey`, `var_export`, the reserved
            // attribute classes — against code that runs correctly.
            // `tools/audit/calibrate.sh` gates this list against `prelude/*.php`.
            "array_fns_ext.php",
            "attributes.php",
            "backtrace_stub.php",
            "var_export.php",
            "ob.php",
            "autoload.php",
            "sapi.php",
            "session.php",
            // The Buffer\ and Http\ class trees, same reasoning as the demand-gated
            // trees above: closed-world analysis must know every prelude class a
            // user program can name.
            "buffer.php",
            "http.php",
            "websocket.php",
            "ds.php",
            "ds_map.php",
            "filter.php",
            "filter_fns.php",
            "weak.php",
            // ext/simplexml + ext/dom: SimpleXMLElement, DOMDocument and the node
            // tree are prelude CLASSES, so closed-world analysis needs them for the
            // same reason as Buffer\/Http\.
            "xml.php",
            "xml_xpath.php",
            "xml_dom.php",
            // Same reason: PhpToken is demand-gated at compile time, but the
            // analyzer is closed-world and would read it as an unknown class.
            "tokenizer.php",
            "tokenizer_api.php",
            // And again for OpenSSLAsymmetricKey: `function f(OpenSSLAsymmetricKey $k)`
            // is the ordinary spelling, and closed-world it would read as unknown.
            "openssl_x509.php",
            // And again for CurlHandle / CurlMultiHandle / CurlShareHandle — a
            // `function fetch(CurlHandle $ch)` hint is the ordinary way to write
            // ext/curl code, and closed-world it would read as an unknown class.
            "curl.php",
            "curl_multi.php",
            // And again for PDO / PDOStatement / PDOException — `function
            // repo(PDO $db)` is the ordinary way to write PDO code, and closed-world
            // it would read as an unknown class. pdo_sqlite.php comes along because
            // it declares the driver classes pdo.php's seam is satisfied by.
            "pdo.php",
            "pdo_sqlite.php",
            "pdo_sql.php",
            "pdo_pgsql.php",
            "pdo_mysql.php",
            // Same for mysqli / mysqli_result / mysqli_stmt type hints and the mysqli_* functions.
            "mysqli.php",
            "mysqli_fn.php",
            // ext/intl: Collator, NumberFormatter, IntlDateFormatter, … and the intl_*/
            // grapheme_*/idn_* functions. (`*_off.php` are stand-ins for a driver file and
            // redeclare its names, so they never belong here.)
            "intl.php",
            "intl_breakiter.php",
            "intl_calendar.php",
            "intl_char.php",
            "intl_collator.php",
            "intl_datefmt.php",
            "intl_idn.php",
            "intl_listfmt.php",
            "intl_locale.php",
            "intl_msgfmt.php",
            "intl_numfmt.php",
            "intl_rb.php",
            "intl_spoof.php",
            "intl_timezone.php",
            "intl_translit.php",
            "intl_ucnv.php",
        ];
        return $names;
    }
}
