<?php

namespace Semantic;

use Analyze\ParsedFile;

/**
 * The analyzer's library universe: the prelude (parsed) and the stdlib function
 * names from the bundled `.o.sig`. Resolved the way the compiler resolves them.
 *
 * Prelude files are labelled `lib:prelude/<name>.php`. The label is not a disk path: the
 * prelude is found through `\Manticore\prelude_src_or_empty` (several candidate roots), so a
 * host that wants to open one maps the `lib:prelude/` prefix to its prelude directory.
 */
final class LibrarySymbols
{
    /** Reserved label prefix of every library file; a Workspace refuses it as a user URI. */
    public const LABEL_PREFIX = 'lib:';

    private static ?LibrarySymbols $loaded = null;

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

    /** @return string[] */
    private static function readStdlibNames(): array
    {
        $path = \Manticore\find_stdlib_sig();
        if ($path === "") {
            return [];
        }
        $json = \Manticore\read_file($path);
        if ($json === null) {
            return [];
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

    /** @return ParsedFile[] */
    private static function readPrelude(): array
    {
        /** @var ParsedFile[] $out */
        $out = [];
        foreach (self::preludeFileNames() as $name) {
            $src = \Manticore\prelude_src_or_empty($name);
            if ($src === "") {
                continue;
            }
            // `prelude_src_or_empty` strips the opening `<?php` (it is built to append
            // after a prelude header); re-add it so this parses as a standalone file.
            try {
                $program = \Parser\Parser::parseSource("<?php\n" . $src);
                $out[] = new ParsedFile(self::LABEL_PREFIX . "prelude/" . $name, $program);
            } catch (\Throwable $e) {
                // A prelude file that does not parse standalone is simply skipped.
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
        ];
        return $names;
    }
}
