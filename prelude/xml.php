<?php
// ext/simplexml + ext/libxml — DEMAND-GATED (Main.php): compiled only when a
// program mentions SimpleXMLElement / DOM* / a libxml_* function.
//
// ── Why the prelude and not src/Runtime/Stdlib ──────────────────────────────
// The stdlib `.o.sig` carries FUNCTIONS ONLY — it cannot name a class — so a
// SimpleXMLElement living in the stdlib .o would be invisible to a user program
// (`instanceof` false, properties read as raw bits). Same reason Http\ and
// Buffer\ are wholly here.
//
// ── Why libxml2's xmlTextReader and not its tree ────────────────────────────
// `\Ffi\Ptr` has no `read*` family (docs/ffi.md) and binding the xmlDoc tree
// would mean hard-coding the 64-bit offsets of _xmlNode/_xmlAttr/_xmlNs plus a
// __destruct-driven xmlFreeDoc behind every wrapper object. The xmlTextReader
// pull API is entirely functions returning `int` or `const char *`: zero struct
// dereference, and the reader dies at the end of the parse call, so NO live C
// pointer ever reaches PHP. libxml2 still owns encodings, entities, DTDs,
// namespace resolution and schema validation; the tree, the serializer and
// XPath are ours (see xml_xpath.php / xml_dom.php).
//
// Attributes are fully qualified — the prelude is one concatenated blob with no
// place for a `use`.

// ── libxml2 bindings ───────────────────────────────────────────────────────
//
// ⚠ EVERY int-returning bind carries #[Ffi\CType('int')]. Without it the C
// callee writes only w0/eax and the wrapper reads the whole 64-bit register, so
// a -1 comes back as 4294967295. On Darwin many libc entry points are asm stubs
// that happen to write all of x0 and the bug is INVISIBLE; in libxml2 it is a
// plain C function on both hosts, and xmlTextReaderRead's -1 IS the error path.
// It must NOT go on a bind that returns a POINTER (it would truncate to 32 bits).

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlReaderForMemory'), \Ffi\Weak]
function __mc_xml_reader_for_memory(string $buffer, #[\Ffi\CType('int')] int $size,
                                    \Ffi\Ptr $url, \Ffi\Ptr $encoding,
                                    #[\Ffi\CType('int')] int $options): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlReaderForFile'), \Ffi\Weak]
function __mc_xml_reader_for_file(string $filename, \Ffi\Ptr $encoding,
                                  #[\Ffi\CType('int')] int $options): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlFreeTextReader'), \Ffi\Weak]
function __mc_xml_reader_free(\Ffi\Ptr $reader): void {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderRead'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_read(\Ffi\Ptr $reader): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderNodeType'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_node_type(\Ffi\Ptr $reader): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderDepth'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_depth(\Ffi\Ptr $reader): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderIsEmptyElement'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_is_empty(\Ffi\Ptr $reader): int {}

// The Const* family hands back libxml2's OWN buffer — valid only until the next
// Read. Copy it with cstr_to_str immediately (see __mc_xml_cstr) and never free.
#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderConstLocalName'), \Ffi\Weak]
function __mc_xml_local_name(\Ffi\Ptr $reader): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderConstName'), \Ffi\Weak]
function __mc_xml_qname(\Ffi\Ptr $reader): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderConstPrefix'), \Ffi\Weak]
function __mc_xml_prefix(\Ffi\Ptr $reader): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderConstNamespaceUri'), \Ffi\Weak]
function __mc_xml_ns_uri(\Ffi\Ptr $reader): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderConstValue'), \Ffi\Weak]
function __mc_xml_value(\Ffi\Ptr $reader): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderConstXmlVersion'), \Ffi\Weak]
function __mc_xml_version(\Ffi\Ptr $reader): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderConstEncoding'), \Ffi\Weak]
function __mc_xml_encoding(\Ffi\Ptr $reader): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderStandalone'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_standalone(\Ffi\Ptr $reader): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderAttributeCount'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_attr_count(\Ffi\Ptr $reader): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderMoveToFirstAttribute'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_first_attr(\Ffi\Ptr $reader): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderMoveToNextAttribute'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_next_attr(\Ffi\Ptr $reader): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderMoveToElement'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_to_element(\Ffi\Ptr $reader): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderGetParserLineNumber'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_err_line(\Ffi\Ptr $reader): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderGetParserColumnNumber'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_err_col(\Ffi\Ptr $reader): int {}

// ── Validation (still function-only: no struct is ever dereferenced) ────────

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlSchemaNewMemParserCtxt'), \Ffi\Weak]
function __mc_xml_schema_mem_ctxt(string $buffer, #[\Ffi\CType('int')] int $size): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlSchemaParse'), \Ffi\Weak]
function __mc_xml_schema_parse(\Ffi\Ptr $ctxt): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlSchemaFreeParserCtxt'), \Ffi\Weak]
function __mc_xml_schema_free_ctxt(\Ffi\Ptr $ctxt): void {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlSchemaFree'), \Ffi\Weak]
function __mc_xml_schema_free(\Ffi\Ptr $schema): void {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderSetSchema'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_reader_set_schema(\Ffi\Ptr $reader, \Ffi\Ptr $schema): int {}

// The FILE form — DOMDocument::schemaValidate($path). Takes the .xsd path
// directly, so no parser context is built on our side.
#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderSchemaValidate'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_reader_schema_validate(\Ffi\Ptr $reader, string $xsd): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlRelaxNGNewMemParserCtxt'), \Ffi\Weak]
function __mc_xml_rng_mem_ctxt(string $buffer, #[\Ffi\CType('int')] int $size): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlRelaxNGParse'), \Ffi\Weak]
function __mc_xml_rng_parse(\Ffi\Ptr $ctxt): \Ffi\Ptr {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlRelaxNGFreeParserCtxt'), \Ffi\Weak]
function __mc_xml_rng_free_ctxt(\Ffi\Ptr $ctxt): void {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlRelaxNGFree'), \Ffi\Weak]
function __mc_xml_rng_free(\Ffi\Ptr $rng): void {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderRelaxNGSetSchema'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_reader_set_rng(\Ffi\Ptr $reader, \Ffi\Ptr $rng): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderSetParserProp'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_set_parser_prop(\Ffi\Ptr $reader, #[\Ffi\CType('int')] int $prop,
                                  #[\Ffi\CType('int')] int $value): int {}

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlTextReaderIsValid'), \Ffi\CType('int'), \Ffi\Weak]
function __mc_xml_is_valid(\Ffi\Ptr $reader): int {}

// ── Silencing libxml2's own diagnostics ────────────────────────────────────
//
// XML_PARSE_NOERROR|NOWARNING covers the PARSER, but SCHEMA validity errors are
// raised through the global error handler and print to stderr regardless. php
// suppresses them by installing an xmlStructuredErrorFunc, and so do we, now
// that a C library can call back into PHP ({@see fn_to_ptr}, docs/ffi.md).
//
// __xmlRaiseError consults the STRUCTURED channel first and returns without its
// default print the moment one is set, so an empty sink is the whole mechanism —
// the handler does not have to read the xmlError it is handed, which is what
// keeps this module free of C struct offsets.
//
// The GENERIC handler (xmlGenericErrorFunc) is `void(*)(void*, const char*, ...)`
// — a C VARARGS callback, which a PHP function cannot be. The structured half is
// the usable one, and it is the half that matters.

#[\Ffi\Library('xml2'), \Ffi\Symbol('xmlSetStructuredErrorFunc'), \Ffi\Weak]
function __mc_xml_set_structured_error(\Ffi\Ptr $ctx, \Ffi\Ptr $handler): void {}

/**
 * The sink. Deliberately empty: its ONLY job is to exist, so libxml2 stops
 * writing to stderr.
 *
 * ⚠ It must never throw. A throw here would longjmp to the nearest enclosing PHP
 * `try`, which sits ABOVE libxml2's frames — abandoning whatever the parser or
 * the schema validator was in the middle of.
 */
function __mc_xml_err_sink(\Ffi\Ptr $ctx, \Ffi\Ptr $err): void
{
}

/** Install the sink once per process. */
function __mc_xml_silence(): void
{
    static $done = 0;
    if ($done === 1) {
        return;
    }
    $done = 1;
    \__mc_xml_set_structured_error(\int_to_ptr(0), \fn_to_ptr('__mc_xml_err_sink'));
}

// ── LIBXML_* — libxml2's own option bits, which is why php's constants and
//    xmlParserOption share values and the option word passes straight through.

const LIBXML_VERSION        = 20913;
const LIBXML_DOTTED_VERSION = '2.9.13';
const LIBXML_LOADED_VERSION = 20913;

const LIBXML_RECOVER    = 1;
const LIBXML_NOENT      = 2;
const LIBXML_DTDLOAD    = 4;
const LIBXML_DTDATTR    = 8;
const LIBXML_DTDVALID   = 16;
const LIBXML_NOERROR    = 32;
const LIBXML_NOWARNING  = 64;
const LIBXML_PEDANTIC   = 128;
const LIBXML_NOBLANKS   = 256;
const LIBXML_XINCLUDE   = 1024;
const LIBXML_NONET      = 2048;
const LIBXML_NSCLEAN    = 8192;
const LIBXML_NOCDATA    = 16384;
const LIBXML_NOXMLDECL  = 2;
const LIBXML_COMPACT    = 65536;
const LIBXML_NOBASEFIX  = 262144;
const LIBXML_PARSEHUGE  = 524288;
const LIBXML_BIGLINES   = 4194304;
const LIBXML_NOEMPTYTAG = 4;
const LIBXML_SCHEMA_CREATE = 1;
const LIBXML_HTML_NOIMPLIED = 8192;
const LIBXML_HTML_NODEFDTD  = 4;

const LIBXML_ERR_NONE    = 0;
const LIBXML_ERR_WARNING = 1;
const LIBXML_ERR_ERROR   = 2;
const LIBXML_ERR_FATAL   = 3;

// Our node-table types are the DOM nodeType numbers, so DOMNode::$nodeType is a
// straight read and SimpleXML's element/attribute tests are the same constants.
const XML_ELEMENT_NODE                = 1;
const XML_ATTRIBUTE_NODE              = 2;
const XML_TEXT_NODE                   = 3;
const XML_CDATA_SECTION_NODE          = 4;
const XML_ENTITY_REF_NODE             = 5;
const XML_PI_NODE                     = 7;
const XML_COMMENT_NODE                = 8;
const XML_DOCUMENT_NODE               = 9;
const XML_DOCUMENT_TYPE_NODE          = 10;
const XML_DOCUMENT_FRAG_NODE          = 11;

// xmlTextReader node types (libxml2's own enum) — local to the builder loop.
const __MC_XR_ELEMENT     = 1;
const __MC_XR_TEXT        = 3;
const __MC_XR_CDATA       = 4;
const __MC_XR_ENTITY_REF  = 5;
const __MC_XR_PI          = 7;
const __MC_XR_COMMENT     = 8;
const __MC_XR_DOCTYPE     = 10;
const __MC_XR_WHITESPACE  = 13;
const __MC_XR_SIG_WS      = 14;
const __MC_XR_END_ELEMENT = 15;

const __MC_XMLNS_URI = 'http://www.w3.org/2000/xmlns/';
const __MC_XML_URI   = 'http://www.w3.org/XML/1998/namespace';

// XML_PARSER_VALIDATE, for xmlTextReaderSetParserProp (DOMDocument::validate).
const __MC_XML_PROP_VALIDATE = 1;

/** A `const xmlChar *` out of libxml2 → an owned, rc-headered PHP string.
 *
 * `\Ffi\Ptr` is the compiler's OWN class — it is not injected into a user
 * module — so a null pointer is built and tested through the int_to_ptr /
 * ptr_to_int builtins, exactly as io_poll.php and resource.php do. */
function __mc_xml_cstr(\Ffi\Ptr $p): string
{
    if (\ptr_to_int($p) === 0) {
        return '';
    }
    return \cstr_to_str($p);
}

// ── The libxml error registry ──────────────────────────────────────────────
//
// State lives in ONE function's statics (the proven Pcre::__preg_compile
// pattern) and every slot is a flat string[]: an error is packed
// "level\x01code\x01line\x01col\x01file\x01message" so no array in here is ever
// heterogeneous. libxml_get_errors() unpacks and builds the objects on demand.

/**
 * op 1 set-internal ($iv) → previous as ['0'|'1'];  op 2 get-internal;
 * op 3 push $sv;  op 4 clear;  op 5 read all.
 * @return string[]
 */
function __mc_libxml_reg(int $op, int $iv = 0, string $sv = ''): array
{
    static $internal = 0;
    static $errors = [];

    if ($op === 1) {
        $prev = $internal;
        $internal = $iv;
        return [(string) $prev];
    }
    if ($op === 2) {
        return [(string) $internal];
    }
    if ($op === 3) {
        $errors[] = $sv;
        return [];
    }
    if ($op === 4) {
        $errors = [];
        return [];
    }
    return $errors;
}

/** Record one parse diagnostic. Always recorded; the internal-errors flag only
 *  decides whether the caller ALSO surfaces it. */
function __mc_libxml_push(int $level, int $code, int $line, int $col,
                          string $file, string $message): void
{
    \__mc_libxml_reg(3, 0, $level . "\x01" . $code . "\x01" . $line . "\x01"
        . $col . "\x01" . $file . "\x01" . $message);
}

class LibXMLError
{
    public int $level = 0;
    public int $code = 0;
    public int $column = 0;
    public string $message = '';
    public string $file = '';
    public int $line = 0;
}

function libxml_use_internal_errors(?bool $use_errors = null): bool
{
    if ($use_errors === null) {
        $cur = \__mc_libxml_reg(2);
        return $cur[0] === '1';
    }
    $prev = \__mc_libxml_reg(1, $use_errors ? 1 : 0);
    return $prev[0] === '1';
}

/**
 * Split on a single-byte separator.
 *
 * NOT `explode`: that lives in prelude/array_fns.php, which is gated on what
 * the USER's source calls — a prelude-only caller would link against nothing.
 * Same reason spl_arrays.php rebuilds its key list with a foreach.
 *
 * @return string[]
 */
function __mc_xml_split(string $s, string $sep): array
{
    $out = [];
    $at = 0;
    while (true) {
        $hit = \strpos($s, $sep, $at);
        if ($hit === false) {
            $out[] = \substr($s, $at, \strlen($s) - $at);
            return $out;
        }
        $out[] = \substr($s, $at, $hit - $at);
        $at = $hit + \strlen($sep);
    }
}

/** @return LibXMLError[] */
function libxml_get_errors(): array
{
    $raw = \__mc_libxml_reg(5);
    $out = [];
    foreach ($raw as $packed) {
        $f = \__mc_xml_split($packed, "\x01");
        $e = new LibXMLError();
        $e->level = (int) $f[0];
        $e->code = (int) $f[1];
        $e->line = (int) $f[2];
        $e->column = (int) $f[3];
        $e->file = $f[4];
        $e->message = $f[5];
        $out[] = $e;
    }
    return $out;
}

function libxml_get_last_error(): LibXMLError|false
{
    $all = \libxml_get_errors();
    $n = \count($all);
    if ($n === 0) {
        return false;
    }
    return $all[$n - 1];
}

function libxml_clear_errors(): void
{
    \__mc_libxml_reg(4);
}

/** Removed from php in 8.0 and a no-op here — this toolchain never resolves an
 *  external entity (XML_PARSE_NONET is forced on every reader). */
function libxml_disable_entity_loader(bool $disable = true): bool
{
    return true;
}

function libxml_set_streams_context(mixed $context): void
{
}

// ── The node table ─────────────────────────────────────────────────────────
//
// Flat parallel arrays keyed by an int node id. Deliberately NOT an object
// graph: a parent POINTER would close a cycle and the collector has no roots
// yet, so a document would leak whole. Ints also keep every array homogeneous,
// which is what lets the arena hold them.

class __McXmlDoc
{
    /** @var int[] node id => nodeType (XML_*_NODE) */
    public array $type = [];
    /** @var string[] node id => local name / PI target / attribute name */
    public array $name = [];
    /** @var string[] node id => namespace prefix ('' = none) */
    public array $prefix = [];
    /** @var string[] node id => resolved namespace URI ('' = none) */
    public array $uri = [];
    /** @var int[] node id => parent id (-1 at the document level) */
    public array $parent = [];
    /** @var array<int,int[]> node id => child ids, DOCUMENT ORDER, mixed content included */
    public array $kids = [];
    /** @var string[] node id => text / cdata / comment / PI / attribute value */
    public array $value = [];
    /** @var array<int,int[]> node id => attribute node ids */
    public array $attrs = [];
    /** @var array<int,array<string,string>> node id => prefix ('' = default) => URI DECLARED here */
    public array $nsDecl = [];

    public int $root = -1;
    /** @var int[] comments / PIs sitting outside the root element */
    public array $docKids = [];

    public string $xmlVersion = '1.0';
    public string $encoding = '';
    public int $standalone = -1;
    public string $doctype = '';
    public string $uriBase = '';

    /** Formatting knobs DOMDocument exposes; the serializer reads them. */
    public bool $formatOutput = false;
    public bool $preserveWhiteSpace = true;
    /** `$dom->validateOnParse` — DTD-validate while loading. symfony's
     *  Config\Util\XmlUtils::parse sets it before every loadXML(), so it is the
     *  one parse knob that has to be real rather than accepted-and-dropped. */
    public bool $validateOnParse = false;

    public function newNode(int $type, string $name, string $prefix, string $uri, string $value): int
    {
        $id = \count($this->type);
        $this->type[$id] = $type;
        $this->name[$id] = $name;
        $this->prefix[$id] = $prefix;
        $this->uri[$id] = $uri;
        $this->value[$id] = $value;
        $this->parent[$id] = -1;
        $this->kids[$id] = [];
        $this->attrs[$id] = [];
        $this->nsDecl[$id] = [];
        return $id;
    }

    public function appendKid(int $parent, int $child): void
    {
        $this->parent[$child] = $parent;
        $ks = $this->kids[$parent];
        $ks[] = $child;
        $this->kids[$parent] = $ks;
    }

    public function addAttrNode(int $owner, int $attr): void
    {
        $this->parent[$attr] = $owner;
        $as = $this->attrs[$owner];
        $as[] = $attr;
        $this->attrs[$owner] = $as;
    }

    /** Element children of $id, optionally filtered by namespace URI. */
    public function elementKids(int $id, string $uri, bool $filter): array
    {
        $out = [];
        foreach ($this->kids[$id] as $k) {
            if ($this->type[$k] !== XML_ELEMENT_NODE) {
                continue;
            }
            if ($filter && $this->uri[$k] !== $uri) {
                continue;
            }
            $out[] = $k;
        }
        return $out;
    }

    /** Concatenated DIRECT text/cdata children — what `(string)$sxe` yields.
     *  Descendant text is deliberately NOT included; that is SimpleXML's rule. */
    public function directText(int $id): string
    {
        if ($this->type[$id] !== XML_ELEMENT_NODE) {
            return $this->value[$id];
        }
        $s = '';
        foreach ($this->kids[$id] as $k) {
            $t = $this->type[$k];
            if ($t === XML_TEXT_NODE || $t === XML_CDATA_SECTION_NODE) {
                $s .= $this->value[$k];
            }
        }
        return $s;
    }

    /** All descendant text — DOM's `textContent`. */
    public function textContent(int $id): string
    {
        $t = $this->type[$id];
        if ($t === XML_TEXT_NODE || $t === XML_CDATA_SECTION_NODE
            || $t === XML_COMMENT_NODE || $t === XML_PI_NODE || $t === XML_ATTRIBUTE_NODE) {
            return $this->value[$id];
        }
        $s = '';
        foreach ($this->kids[$id] as $k) {
            $kt = $this->type[$k];
            if ($kt === XML_COMMENT_NODE || $kt === XML_PI_NODE) {
                continue;
            }
            $s .= $this->textContent($k);
        }
        return $s;
    }

    /** The attribute node named $name (in namespace $uri when $filter), or -1. */
    public function findAttr(int $id, string $name, string $uri, bool $filter): int
    {
        foreach ($this->attrs[$id] as $a) {
            if ($this->name[$a] !== $name) {
                continue;
            }
            if ($filter && $this->uri[$a] !== $uri) {
                continue;
            }
            return $a;
        }
        return -1;
    }

    /** The URI bound to $prefix at $id, walking up the declarations. '' if unbound. */
    public function lookupUri(int $id, string $prefix): string
    {
        if ($prefix === 'xml') {
            return __MC_XML_URI;
        }
        $n = $id;
        while ($n >= 0) {
            $d = $this->nsDecl[$n];
            if (isset($d[$prefix])) {
                return $d[$prefix];
            }
            $n = $this->parent[$n];
        }
        return '';
    }

    /** Every prefix=>URI in scope at $id (nearest declaration wins). */
    public function nsInScope(int $id): array
    {
        $out = [];
        $chain = [];
        $n = $id;
        while ($n >= 0) {
            $chain[] = $n;
            $n = $this->parent[$n];
        }
        // Outermost first, so an inner redeclaration overwrites it.
        for ($i = \count($chain) - 1; $i >= 0; $i = $i - 1) {
            foreach ($this->nsDecl[$chain[$i]] as $p => $u) {
                $out[$p] = $u;
            }
        }
        return $out;
    }

    /** Detach $child from its parent's child list. */
    public function detach(int $child): void
    {
        $p = $this->parent[$child];
        if ($p < 0) {
            return;
        }
        $out = [];
        foreach ($this->kids[$p] as $k) {
            if ($k !== $child) {
                $out[] = $k;
            }
        }
        $this->kids[$p] = $out;
        $this->parent[$child] = -1;
    }

    /** Detach attribute node $attr from its owner. */
    public function detachAttr(int $attr): void
    {
        $p = $this->parent[$attr];
        if ($p < 0) {
            return;
        }
        $out = [];
        foreach ($this->attrs[$p] as $a) {
            if ($a !== $attr) {
                $out[] = $a;
            }
        }
        $this->attrs[$p] = $out;
        $this->parent[$attr] = -1;
    }

    /** Deep copy of $id (and its subtree) into this document; returns the new id. */
    public function copyNode(int $src, __McXmlDoc $from): int
    {
        $id = $this->newNode($from->type[$src], $from->name[$src], $from->prefix[$src],
            $from->uri[$src], $from->value[$src]);
        $this->nsDecl[$id] = $from->nsDecl[$src];
        foreach ($from->attrs[$src] as $a) {
            $na = $this->newNode($from->type[$a], $from->name[$a], $from->prefix[$a],
                $from->uri[$a], $from->value[$a]);
            $this->addAttrNode($id, $na);
        }
        foreach ($from->kids[$src] as $k) {
            $nk = $this->copyNode($k, $from);
            $this->appendKid($id, $nk);
        }
        return $id;
    }
}

// ── Reader → node table ────────────────────────────────────────────────────

/**
 * Parse $xml into a fresh __McXmlDoc, or null on a fatal parse error (the
 * diagnostic is pushed onto the libxml registry either way).
 *
 * $isFile picks xmlReaderForFile over xmlReaderForMemory.
 */
function __mc_xml_parse(string $src, int $options, bool $isFile, string $uri): ?__McXmlDoc
{
    // NOERROR|NOWARNING|NONET are forced on: libxml2 writes its diagnostics
    // straight to stderr otherwise, which would poison every test's output and
    // is not what php's users see (Zend intercepts them into the same registry
    // this module keeps).
    $opts = $options | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET;
    \__mc_xml_silence();

    $nul = \int_to_ptr(0);
    if ($isFile) {
        $reader = \__mc_xml_reader_for_file($src, $nul, $opts);
    } else {
        $reader = \__mc_xml_reader_for_memory($src, \strlen($src), $nul, $nul, $opts);
    }
    if (\ptr_to_int($reader) === 0) {
        \__mc_libxml_push(LIBXML_ERR_FATAL, 0, 0, 0, $uri,
            $isFile ? "Failed to load external entity \"" . $src . "\"" : 'Could not create reader');
        return null;
    }

    $doc = new __McXmlDoc();
    $doc->uriBase = $uri;
    $ok = \__mc_xml_drain($reader, $doc, $uri);
    \__mc_xml_reader_free($reader);
    if (!$ok) {
        return null;
    }
    return $doc;
}

/**
 * Drive the pull loop, filling $doc. Returns false on a fatal parse error.
 *
 * ⚠ An empty element (`<b/>`) fires NO END_ELEMENT — xmlTextReaderIsEmptyElement
 * is the only signal, and missing it desynchronises every depth after it.
 */
function __mc_xml_drain(\Ffi\Ptr $reader, __McXmlDoc $doc, string $uri): bool
{
    /** @var int[] open element ids, innermost last */
    $stack = [];
    $depth = 0;
    $cur = -1;
    $seenRoot = false;

    while (true) {
        $rc = \__mc_xml_read($reader);
        if ($rc === 0) {
            break;
        }
        if ($rc < 0) {
            \__mc_libxml_push(LIBXML_ERR_FATAL, 0, \__mc_xml_err_line($reader),
                \__mc_xml_err_col($reader), $uri,
                'Premature end of data or not well-formed XML');
            return false;
        }

        $t = \__mc_xml_node_type($reader);

        if ($t === __MC_XR_ELEMENT) {
            $empty = \__mc_xml_is_empty($reader) === 1;
            $id = $doc->newNode(XML_ELEMENT_NODE,
                \__mc_xml_cstr(\__mc_xml_local_name($reader)),
                \__mc_xml_cstr(\__mc_xml_prefix($reader)),
                \__mc_xml_cstr(\__mc_xml_ns_uri($reader)), '');

            \__mc_xml_read_attrs($reader, $doc, $id);

            if ($cur < 0) {
                if (!$seenRoot) {
                    $doc->root = $id;
                    $seenRoot = true;
                }
                $dk = $doc->docKids;
                $dk[] = $id;
                $doc->docKids = $dk;
            } else {
                $doc->appendKid($cur, $id);
            }

            if (!$empty) {
                $stack[$depth] = $id;
                $depth = $depth + 1;
                $cur = $id;
            }
            continue;
        }

        if ($t === __MC_XR_END_ELEMENT) {
            if ($depth > 0) {
                $depth = $depth - 1;
                $cur = $depth > 0 ? $stack[$depth - 1] : -1;
            }
            continue;
        }

        if ($t === __MC_XR_TEXT || $t === __MC_XR_WHITESPACE || $t === __MC_XR_SIG_WS
            || $t === __MC_XR_ENTITY_REF) {
            if ($cur < 0) {
                continue;
            }
            $id = $doc->newNode(XML_TEXT_NODE, '#text', '', '',
                \__mc_xml_cstr(\__mc_xml_value($reader)));
            $doc->appendKid($cur, $id);
            continue;
        }

        if ($t === __MC_XR_CDATA) {
            if ($cur < 0) {
                continue;
            }
            $id = $doc->newNode(XML_CDATA_SECTION_NODE, '#cdata-section', '', '',
                \__mc_xml_cstr(\__mc_xml_value($reader)));
            $doc->appendKid($cur, $id);
            continue;
        }

        if ($t === __MC_XR_COMMENT) {
            $id = $doc->newNode(XML_COMMENT_NODE, '#comment', '', '',
                \__mc_xml_cstr(\__mc_xml_value($reader)));
            if ($cur < 0) {
                $dk = $doc->docKids;
                $dk[] = $id;
                $doc->docKids = $dk;
            } else {
                $doc->appendKid($cur, $id);
            }
            continue;
        }

        if ($t === __MC_XR_PI) {
            $id = $doc->newNode(XML_PI_NODE, \__mc_xml_cstr(\__mc_xml_qname($reader)), '', '',
                \__mc_xml_cstr(\__mc_xml_value($reader)));
            if ($cur < 0) {
                $dk = $doc->docKids;
                $dk[] = $id;
                $doc->docKids = $dk;
            } else {
                $doc->appendKid($cur, $id);
            }
            continue;
        }

        if ($t === __MC_XR_DOCTYPE) {
            $doc->doctype = \__mc_xml_cstr(\__mc_xml_qname($reader));
            continue;
        }
    }

    $v = \__mc_xml_cstr(\__mc_xml_version($reader));
    if ($v !== '') {
        $doc->xmlVersion = $v;
    }
    $doc->encoding = \__mc_xml_cstr(\__mc_xml_encoding($reader));
    $doc->standalone = \__mc_xml_standalone($reader);

    if ($doc->root < 0) {
        \__mc_libxml_push(LIBXML_ERR_FATAL, 4, 1, 1, $uri, 'Start tag expected, \'<\' not found');
        return false;
    }
    return true;
}

/**
 * Read the attribute cursor of the current element into $doc.
 *
 * ⚠ `xmlns` / `xmlns:p` arrive through this same cursor. They are namespace
 * DECLARATIONS, not attributes — php lists neither in attributes(), so they go
 * to nsDecl and nowhere else.
 */
function __mc_xml_read_attrs(\Ffi\Ptr $reader, __McXmlDoc $doc, int $owner): void
{
    if (\__mc_xml_first_attr($reader) !== 1) {
        return;
    }
    $decls = [];
    while (true) {
        $ln = \__mc_xml_cstr(\__mc_xml_local_name($reader));
        $px = \__mc_xml_cstr(\__mc_xml_prefix($reader));
        $ns = \__mc_xml_cstr(\__mc_xml_ns_uri($reader));
        $vl = \__mc_xml_cstr(\__mc_xml_value($reader));

        if ($px === 'xmlns') {
            $decls[$ln] = $vl;
        } elseif ($px === '' && $ln === 'xmlns') {
            $decls[''] = $vl;
        } else {
            $a = $doc->newNode(XML_ATTRIBUTE_NODE, $ln, $px, $ns, $vl);
            $doc->addAttrNode($owner, $a);
        }

        if (\__mc_xml_next_attr($reader) !== 1) {
            break;
        }
    }
    $doc->nsDecl[$owner] = $decls;
    \__mc_xml_to_element($reader);
}

// ── Serializer ─────────────────────────────────────────────────────────────

/**
 * Decode the XML entity references in a value handed to addChild / addAttribute
 * / `$x->tag = …`.
 *
 * php runs those values through libxml's entity parser, so `addChild('a',
 * '&amp;')` stores a single `&` and asXML() writes `&amp;` back. Storing the
 * text verbatim instead double-escaped it to `&amp;amp;` on the way out.
 *
 * A `&` that begins no valid reference is left ALONE. php errors there
 * ("unterminated entity reference") and drops the whole value; accepting it is
 * the deliberate difference, and the direction the sentinel→exception epic
 * takes everywhere else.
 */
function __mc_xml_decode_ents(string $s): string
{
    if (\strpos($s, '&') === false) {
        return $s;
    }
    $out = '';
    $n = \strlen($s);
    $i = 0;
    while ($i < $n) {
        $c = $s[$i];
        if ($c !== '&') {
            $out .= $c;
            $i = $i + 1;
            continue;
        }
        $semi = \strpos($s, ';', $i + 1);
        if ($semi === false || $semi - $i > 12) {
            $out .= $c;
            $i = $i + 1;
            continue;
        }
        $name = \substr($s, $i + 1, $semi - $i - 1);
        $rep = '';
        if ($name === 'amp') { $rep = '&'; }
        elseif ($name === 'lt') { $rep = '<'; }
        elseif ($name === 'gt') { $rep = '>'; }
        elseif ($name === 'quot') { $rep = '"'; }
        elseif ($name === 'apos') { $rep = "'"; }
        elseif ($name !== '' && $name[0] === '#') {
            $cp = ($name[1] ?? '') === 'x' || ($name[1] ?? '') === 'X'
                ? \intval(\substr($name, 2, \strlen($name) - 2), 16)
                : (int) \substr($name, 1, \strlen($name) - 1);
            $rep = $cp > 0 ? \__mc_xml_utf8($cp) : '';
        }
        if ($rep === '') {
            $out .= $c;
            $i = $i + 1;
            continue;
        }
        $out .= $rep;
        $i = $semi + 1;
    }
    return $out;
}

/** A code point as UTF-8 bytes. */
function __mc_xml_utf8(int $cp): string
{
    if ($cp < 0x80) {
        return \chr($cp);
    }
    if ($cp < 0x800) {
        return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
    }
    if ($cp < 0x10000) {
        return \chr(0xE0 | ($cp >> 12)) . \chr(0x80 | (($cp >> 6) & 0x3F))
             . \chr(0x80 | ($cp & 0x3F));
    }
    return \chr(0xF0 | ($cp >> 18)) . \chr(0x80 | (($cp >> 12) & 0x3F))
         . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
}

/** Escape for element content, as libxml2's serializer does. */
function __mc_xml_esc_text(string $s): string
{
    return \str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
}

/** Escape for an attribute value; libxml2 also escapes the whitespace that a
 *  parser would otherwise normalise away. */
function __mc_xml_esc_attr(string $s): string
{
    return \str_replace(['&', '<', '>', '"', "\n", "\r", "\t"],
        ['&amp;', '&lt;', '&gt;', '&quot;', '&#10;', '&#13;', '&#9;'], $s);
}

/** `prefix:local` for a node, or just `local`. */
function __mc_xml_qual(__McXmlDoc $doc, int $id): string
{
    $p = $doc->prefix[$id];
    if ($p === '') {
        return $doc->name[$id];
    }
    return $p . ':' . $doc->name[$id];
}

/** Serialize one node and its subtree. */
function __mc_xml_dump_node(__McXmlDoc $doc, int $id, int $indent): string
{
    $t = $doc->type[$id];

    if ($t === XML_TEXT_NODE) {
        return \__mc_xml_esc_text($doc->value[$id]);
    }
    if ($t === XML_CDATA_SECTION_NODE) {
        return '<![CDATA[' . $doc->value[$id] . ']]>';
    }
    if ($t === XML_COMMENT_NODE) {
        return '<!--' . $doc->value[$id] . '-->';
    }
    if ($t === XML_PI_NODE) {
        $v = $doc->value[$id];
        return '<?' . $doc->name[$id] . ($v === '' ? '' : ' ' . $v) . '?>';
    }
    if ($t === XML_ATTRIBUTE_NODE) {
        return \__mc_xml_qual($doc, $id) . '="' . \__mc_xml_esc_attr($doc->value[$id]) . '"';
    }

    $out = '<' . \__mc_xml_qual($doc, $id);
    foreach ($doc->nsDecl[$id] as $p => $u) {
        $out .= $p === '' ? ' xmlns="' : ' xmlns:' . $p . '="';
        $out .= \__mc_xml_esc_attr($u) . '"';
    }
    foreach ($doc->attrs[$id] as $a) {
        $out .= ' ' . \__mc_xml_qual($doc, $a) . '="' . \__mc_xml_esc_attr($doc->value[$a]) . '"';
    }

    $kids = $doc->kids[$id];
    if (\count($kids) === 0) {
        return $out . '/>';
    }
    $out .= '>';

    $pretty = $doc->formatOutput && !\__mc_xml_has_text($doc, $id);
    $pad = '';
    if ($pretty) {
        $pad = \str_repeat('  ', $indent + 1);
    }
    foreach ($kids as $k) {
        if ($pretty) {
            $out .= "\n" . $pad;
        }
        $out .= \__mc_xml_dump_node($doc, $k, $indent + 1);
    }
    if ($pretty) {
        $out .= "\n" . \str_repeat('  ', $indent);
    }
    return $out . '</' . \__mc_xml_qual($doc, $id) . '>';
}

/** Whether $id holds any non-blank text child — pretty-printing must leave
 *  mixed content alone or it changes the document's meaning. */
function __mc_xml_has_text(__McXmlDoc $doc, int $id): bool
{
    foreach ($doc->kids[$id] as $k) {
        $t = $doc->type[$k];
        if ($t === XML_CDATA_SECTION_NODE) {
            return true;
        }
        if ($t === XML_TEXT_NODE && \trim($doc->value[$k]) !== '') {
            return true;
        }
    }
    return false;
}

/** The `<?xml ...?>` header php emits for a whole document. */
function __mc_xml_decl(__McXmlDoc $doc): string
{
    $s = '<?xml version="' . $doc->xmlVersion . '"';
    if ($doc->encoding !== '') {
        $s .= ' encoding="' . $doc->encoding . '"';
    }
    if ($doc->standalone === 1) {
        $s .= ' standalone="yes"';
    }
    return $s . '?>' . "\n";
}

/** Whole-document serialization: declaration + every document-level node. */
function __mc_xml_dump_doc(__McXmlDoc $doc): string
{
    $out = \__mc_xml_decl($doc);
    foreach ($doc->docKids as $k) {
        $out .= \__mc_xml_dump_node($doc, $k, 0) . "\n";
    }
    return $out;
}

// ── SimpleXMLElement ───────────────────────────────────────────────────────
//
// A SimpleXMLElement is NOT one node — it is a NODE SET with a context, which is
// the whole reason its semantics look strange from the outside:
//
//   $x->foo   is the SET of `foo` children, and `$isList` marks it as such;
//             iterating it walks the SET, whereas iterating a plain element
//             walks that element's CHILDREN. That flag is libxml's `iter` bit.
//   (string)$x is the DIRECT text of the first node — descendant text is not
//             included, which is why <a>x<b>y</b>z</a> casts to "xz".
//
// The sentinel below is how a wrapper is built without re-parsing: the public
// constructor stays faithful (it parses, and throws on malformed input), while
// __mcWrap takes the internal path. A user cannot collide with it — the value
// starts with a NUL.
const __MC_SXE_RAW = "\x00mcxml";

class SimpleXMLElement implements Iterator, ArrayAccess, Countable, Stringable
{
    /** Names are `__`-prefixed so a child element called `name` or `value`
     *  still reaches __get instead of hitting one of these. */
    private ?__McXmlDoc $__d = null;
    /** @var int[] the node set */
    private array $__n = [];
    private bool $__list = false;
    private bool $__attr = false;
    private string $__nsUri = '';
    private bool $__nsFilter = false;
    /** @var int[] materialised at rewind() */
    private array $__it = [];
    private int $__i = 0;
    /** @var array<string,string> prefixes registered for xpath() */
    private array $__xpns = [];

    public function __construct(string $data = '', int $options = 0, bool $dataIsURL = false,
                                string $namespaceOrPrefix = '', bool $isPrefix = false)
    {
        if ($data === __MC_SXE_RAW) {
            return;
        }
        $doc = \__mc_xml_parse($data, $options, $dataIsURL, $dataIsURL ? $data : '');
        if ($doc === null) {
            throw new Exception('String could not be parsed as XML');
        }
        $this->__d = $doc;
        $this->__n = [$doc->root];
        if ($namespaceOrPrefix !== '') {
            $this->__nsFilter = true;
            $this->__nsUri = $isPrefix ? $doc->lookupUri($doc->root, $namespaceOrPrefix)
                                       : $namespaceOrPrefix;
        }
    }

    /** @param int[] $nodes */
    public static function __mcWrap(__McXmlDoc $d, array $nodes, bool $isList, bool $isAttr,
                                    string $nsUri, bool $nsFilter, array $xpns): SimpleXMLElement
    {
        $o = new SimpleXMLElement(__MC_SXE_RAW);
        $o->__d = $d;
        $o->__n = $nodes;
        $o->__list = $isList;
        $o->__attr = $isAttr;
        $o->__nsUri = $nsUri;
        $o->__nsFilter = $nsFilter;
        $o->__xpns = $xpns;
        return $o;
    }

    /** The node this object speaks for, or -1 for an empty set. */
    private function __first(): int
    {
        if (\count($this->__n) === 0) {
            return -1;
        }
        return $this->__n[0];
    }

    /**
     * The attribute node `$name` addresses on this object, or -1.
     *
     * An ATTRIBUTE SET (what attributes() returns) IS the list of attributes, so
     * the name is looked up INSIDE the set — its first node is an attribute, not
     * the element, and running findAttr against it searched an attribute's own
     * (empty) attribute list. Any other object addresses the attributes of its
     * first node.
     */
    private function __attrNode(string $name): int
    {
        $d = $this->__d;
        if ($d === null) {
            return -1;
        }
        if ($this->__attr) {
            foreach ($this->__n as $a) {
                if ($d->name[$a] === $name) {
                    return $a;
                }
            }
            return -1;
        }
        $f = $this->__first();
        if ($f < 0) {
            return -1;
        }
        return $d->findAttr($f, $name, $this->__nsUri, $this->__nsFilter);
    }

    private function __doc(): __McXmlDoc
    {
        return $this->__d;
    }

    // ── Reading ────────────────────────────────────────────────────────────

    public function __get(string $name): SimpleXMLElement
    {
        $d = $this->__d;
        $f = $this->__first();
        // php hands back an EMPTY SimpleXMLElement for a missing child, never
        // null — `$x->nope === null` is false and `(string)$x->nope` is ''.
        if ($d === null || $f < 0) {
            return SimpleXMLElement::__mcWrap(new __McXmlDoc(), [], true, false, '', false, $this->__xpns);
        }
        $hits = [];
        foreach ($d->kids[$f] as $k) {
            if ($d->type[$k] !== XML_ELEMENT_NODE || $d->name[$k] !== $name) {
                continue;
            }
            if ($this->__nsFilter && $d->uri[$k] !== $this->__nsUri) {
                continue;
            }
            $hits[] = $k;
        }
        return SimpleXMLElement::__mcWrap($d, $hits, true, false,
            $this->__nsUri, $this->__nsFilter, $this->__xpns);
    }

    public function __isset(string $name): bool
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return false;
        }
        foreach ($d->kids[$f] as $k) {
            if ($d->type[$k] !== XML_ELEMENT_NODE || $d->name[$k] !== $name) {
                continue;
            }
            if ($this->__nsFilter && $d->uri[$k] !== $this->__nsUri) {
                continue;
            }
            return true;
        }
        return false;
    }

    public function __toString(): string
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return '';
        }
        return $d->directText($f);
    }

    public function getName(): string
    {
        $f = $this->__first();
        if ($this->__d === null || $f < 0) {
            return '';
        }
        return $this->__d->name[$f];
    }

    public function count(): int
    {
        if ($this->__list || $this->__attr) {
            return \count($this->__n);
        }
        $f = $this->__first();
        if ($this->__d === null || $f < 0) {
            return 0;
        }
        return \count($this->__d->elementKids($f, $this->__nsUri, $this->__nsFilter));
    }

    /** A container view of $this's children, optionally namespace-filtered. */
    public function children(?string $namespaceOrPrefix = null, bool $isPrefix = false): ?SimpleXMLElement
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return null;
        }
        $uri = '';
        $filter = false;
        if ($namespaceOrPrefix !== null) {
            $filter = true;
            $uri = $isPrefix ? $d->lookupUri($f, $namespaceOrPrefix) : $namespaceOrPrefix;
        }
        return SimpleXMLElement::__mcWrap($d, [$f], false, false, $uri, $filter, $this->__xpns);
    }

    public function attributes(?string $namespaceOrPrefix = null, bool $isPrefix = false): ?SimpleXMLElement
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return null;
        }
        $uri = '';
        $filter = false;
        if ($namespaceOrPrefix !== null) {
            $filter = true;
            $uri = $isPrefix ? $d->lookupUri($f, $namespaceOrPrefix) : $namespaceOrPrefix;
        }
        $hits = [];
        foreach ($d->attrs[$f] as $a) {
            if ($filter && $d->uri[$a] !== $uri) {
                continue;
            }
            $hits[] = $a;
        }
        return SimpleXMLElement::__mcWrap($d, $hits, false, true, $uri, $filter, $this->__xpns);
    }

    /**
     * Namespaces this element (and, when recursive, its descendants) USES.
     *
     * ⚠ Built INLINE over a worklist rather than through a recursive helper with
     * an `array &$out`: a bare `array` hint erases its element type to UNKNOWN,
     * so the map came back with unknown values and `echo $ns[$p]` printed the
     * string POINTER (4345359728) instead of the URI. Keeping every store in
     * this body lets it type as string=>string.
     *
     * @return array<string,string>
     */
    public function getNamespaces(bool $recursive = false): array
    {
        $out = [];
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return $out;
        }
        $work = [$f];
        $at = 0;
        while ($at < \count($work)) {
            $n = $work[$at];
            $at = $at + 1;
            if ($d->uri[$n] !== '') {
                $out[$d->prefix[$n]] = $d->uri[$n];
            }
            foreach ($d->attrs[$n] as $a) {
                if ($d->uri[$a] !== '') {
                    $out[$d->prefix[$a]] = $d->uri[$a];
                }
            }
            if (!$recursive) {
                continue;
            }
            foreach ($d->kids[$n] as $k) {
                if ($d->type[$k] === XML_ELEMENT_NODE) {
                    $work[] = $k;
                }
            }
        }
        return $out;
    }

    /**
     * Namespaces DECLARED in the document (or in this subtree). Same inline
     * worklist, same reason as {@see getNamespaces}.
     *
     * @return array<string,string>
     */
    public function getDocNamespaces(bool $recursive = false, bool $fromRoot = true): array
    {
        $out = [];
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return $out;
        }
        $work = [$fromRoot ? $d->root : $f];
        $at = 0;
        while ($at < \count($work)) {
            $n = $work[$at];
            $at = $at + 1;
            foreach ($d->nsDecl[$n] as $p => $u) {
                $out[$p] = $u;
            }
            if (!$recursive) {
                continue;
            }
            foreach ($d->kids[$n] as $k) {
                if ($d->type[$k] === XML_ELEMENT_NODE) {
                    $work[] = $k;
                }
            }
        }
        return $out;
    }

    public function asXML(?string $filename = null): string|bool
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return false;
        }
        // The DOCUMENT element serializes as a document (declaration + trailing
        // newline); any other node serializes as just that element.
        if ($f === $d->root && !$this->__attr) {
            $xml = \__mc_xml_dump_doc($d);
        } else {
            $xml = \__mc_xml_dump_node($d, $f, 0);
        }
        if ($filename === null) {
            return $xml;
        }
        return \file_put_contents($filename, $xml) !== false;
    }

    public function saveXML(?string $filename = null): string|bool
    {
        return $this->asXML($filename);
    }

    // ── Writing ────────────────────────────────────────────────────────────

    public function __set(string $name, mixed $value): void
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return;
        }
        $target = -1;
        $hits = 0;
        foreach ($d->kids[$f] as $k) {
            if ($d->type[$k] === XML_ELEMENT_NODE && $d->name[$k] === $name) {
                if ($target < 0) {
                    $target = $k;
                }
                $hits = $hits + 1;
            }
        }
        // php REFUSES to assign through a name matching several children
        // ("Cannot assign to an array of nodes") and leaves the document alone.
        if ($hits > 1) {
            return;
        }
        if ($target < 0) {
            $this->addChild($name, (string) $value, null);
            return;
        }
        // Replacing an element's text drops its whole content, as php does — and
        // stores the value VERBATIM (no entity decode; that is addChild's
        // behaviour alone, checked against the interpreter).
        $d->kids[$target] = [];
        $t = $d->newNode(XML_TEXT_NODE, '#text', '', '', (string) $value);
        $d->appendKid($target, $t);
    }

    public function __unset(string $name): void
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return;
        }
        $keep = [];
        foreach ($d->kids[$f] as $k) {
            if ($d->type[$k] === XML_ELEMENT_NODE && $d->name[$k] === $name) {
                $d->parent[$k] = -1;
                continue;
            }
            $keep[] = $k;
        }
        $d->kids[$f] = $keep;
    }

    public function addChild(string $qualifiedName, ?string $value = null,
                             ?string $namespace = null): ?SimpleXMLElement
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return null;
        }
        $prefix = '';
        $local = $qualifiedName;
        $c = \strpos($qualifiedName, ':');
        if ($c !== false) {
            $prefix = \substr($qualifiedName, 0, $c);
            $local = \substr($qualifiedName, $c + 1);
        }

        $uri = '';
        $declare = false;
        if ($namespace !== null) {
            $uri = $namespace;
            // Reuse an in-scope prefix bound to this URI; otherwise the new
            // element carries the declaration itself.
            if ($prefix === '') {
                $found = false;
                foreach ($d->nsInScope($f) as $p => $u) {
                    if ($u === $namespace) {
                        $prefix = $p;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $declare = true;
                }
            } else {
                $declare = $d->lookupUri($f, $prefix) !== $namespace;
            }
        } elseif ($prefix !== '') {
            $uri = $d->lookupUri($f, $prefix);
        } else {
            $uri = $d->lookupUri($f, '');
        }

        $id = $d->newNode(XML_ELEMENT_NODE, $local, $prefix, $uri, '');
        if ($declare) {
            $d->nsDecl[$id] = [$prefix => $uri];
        }
        $d->appendKid($f, $id);
        if ($value !== null) {
            $t = $d->newNode(XML_TEXT_NODE, '#text', '', '',
                \__mc_xml_decode_ents($value));
            $d->appendKid($id, $t);
        }
        return SimpleXMLElement::__mcWrap($d, [$id], false, false, '', false, $this->__xpns);
    }

    public function addAttribute(string $qualifiedName, ?string $value = null,
                                 ?string $namespace = null): void
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return;
        }
        $prefix = '';
        $local = $qualifiedName;
        $c = \strpos($qualifiedName, ':');
        if ($c !== false) {
            $prefix = \substr($qualifiedName, 0, $c);
            $local = \substr($qualifiedName, $c + 1);
        }
        $uri = '';
        if ($namespace !== null) {
            $uri = $namespace;
            if ($prefix === '') {
                foreach ($d->nsInScope($f) as $p => $u) {
                    if ($u === $namespace && $p !== '') {
                        $prefix = $p;
                        break;
                    }
                }
            }
            if ($d->lookupUri($f, $prefix) !== $namespace) {
                $decl = $d->nsDecl[$f];
                $decl[$prefix] = $namespace;
                $d->nsDecl[$f] = $decl;
            }
        }
        // NOT entity-decoded: php decodes addChild's VALUE but stores an
        // attribute verbatim, so `addAttribute('n','a &lt; b')` serializes as
        // `a &amp;lt; b`. Verified against the interpreter, asymmetric as it looks.
        $a = $d->newNode(XML_ATTRIBUTE_NODE, $local, $prefix, $uri,
            $value === null ? '' : $value);
        $d->addAttrNode($f, $a);
    }

    // ── ArrayAccess: int indexes the node set, string reaches an attribute ──

    public function offsetExists(mixed $offset): bool
    {
        $d = $this->__d;
        if ($d === null) {
            return false;
        }
        if (\is_int($offset)) {
            return $offset >= 0 && $offset < \count($this->__n);
        }
        return $this->__attrNode((string) $offset) >= 0;
    }

    public function offsetGet(mixed $offset): mixed
    {
        $d = $this->__d;
        if ($d === null) {
            return null;
        }
        if (\is_int($offset)) {
            if ($offset < 0 || $offset >= \count($this->__n)) {
                return null;
            }
            return SimpleXMLElement::__mcWrap($d, [$this->__n[$offset]], false, $this->__attr,
                $this->__nsUri, $this->__nsFilter, $this->__xpns);
        }
        $a = $this->__attrNode((string) $offset);
        if ($a < 0) {
            return null;
        }
        return SimpleXMLElement::__mcWrap($d, [$a], false, true, '', false, $this->__xpns);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0 || \is_int($offset)) {
            return;
        }
        $a = $this->__attrNode((string) $offset);
        if ($a >= 0) {
            $d->value[$a] = (string) $value;
            return;
        }
        $this->addAttribute((string) $offset, (string) $value, null);
    }

    public function offsetUnset(mixed $offset): void
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0 || \is_int($offset)) {
            return;
        }
        $a = $this->__attrNode((string) $offset);
        if ($a >= 0) {
            $d->detachAttr($a);
        }
    }

    // ── Iterator ───────────────────────────────────────────────────────────
    //
    // A list or attribute set walks ITSELF; a plain element walks its children.

    public function rewind(): void
    {
        $d = $this->__d;
        $this->__i = 0;
        if ($d === null) {
            $this->__it = [];
            return;
        }
        if ($this->__list || $this->__attr) {
            $this->__it = $this->__n;
            return;
        }
        $f = $this->__first();
        $this->__it = $f < 0 ? [] : $d->elementKids($f, $this->__nsUri, $this->__nsFilter);
    }

    public function valid(): bool
    {
        return $this->__i < \count($this->__it);
    }

    public function key(): string
    {
        if (!$this->valid()) {
            return '';
        }
        return $this->__d->name[$this->__it[$this->__i]];
    }

    public function current(): SimpleXMLElement
    {
        $node = $this->__it[$this->__i];
        return SimpleXMLElement::__mcWrap($this->__d, [$node], false, $this->__attr,
            $this->__nsUri, $this->__nsFilter, $this->__xpns);
    }

    public function next(): void
    {
        $this->__i = $this->__i + 1;
    }

    // ── XPath ──────────────────────────────────────────────────────────────

    public function registerXPathNamespace(string $prefix, string $namespace): bool
    {
        $ns = $this->__xpns;
        $ns[$prefix] = $namespace;
        $this->__xpns = $ns;
        return true;
    }

    /** @return SimpleXMLElement[]|false */
    public function xpath(string $expression): array|false
    {
        $d = $this->__d;
        $f = $this->__first();
        if ($d === null || $f < 0) {
            return false;
        }
        $hits = \__mc_xpath_nodes($d, $f, $expression, $this->__xpns);
        if ($hits === null) {
            return false;
        }
        $out = [];
        foreach ($hits as $n) {
            $out[] = SimpleXMLElement::__mcWrap($d, [$n], false,
                $d->type[$n] === XML_ATTRIBUTE_NODE, '', false, $this->__xpns);
        }
        return $out;
    }

    /** The node table + node id behind this object — how simplexml_import_dom /
     *  dom_import_simplexml hand one tree to the other API. */
    public function __mcDoc(): __McXmlDoc
    {
        return $this->__d;
    }

    public function __mcNode(): int
    {
        return $this->__first();
    }
}

/** SimpleXMLIterator — the recursive flavour php ships alongside. */
class SimpleXMLIterator extends SimpleXMLElement
{
    public function hasChildren(): bool
    {
        $c = $this->current();
        return $c->count() > 0;
    }

    public function getChildren(): ?SimpleXMLElement
    {
        return $this->current()->children(null, false);
    }
}

// ── Entry points ───────────────────────────────────────────────────────────
//
// ⚠ `$class_name` is accepted for signature parity and IGNORED: instantiating a
// class named by a runtime STRING is not something this toolchain does (the
// class table is closed-world and the name never reaches it). A program passing
// a SimpleXMLElement subclass gets a plain SimpleXMLElement back.

function simplexml_load_string(string $data, ?string $class_name = 'SimpleXMLElement',
                               int $options = 0, string $namespace_or_prefix = '',
                               bool $is_prefix = false): SimpleXMLElement|false
{
    $doc = \__mc_xml_parse($data, $options, false, '');
    if ($doc === null) {
        return false;
    }
    return \__mc_sxe_root($doc, $namespace_or_prefix, $is_prefix);
}

function simplexml_load_file(string $filename, ?string $class_name = 'SimpleXMLElement',
                             int $options = 0, string $namespace_or_prefix = '',
                             bool $is_prefix = false): SimpleXMLElement|false
{
    $doc = \__mc_xml_parse($filename, $options, true, $filename);
    if ($doc === null) {
        return false;
    }
    return \__mc_sxe_root($doc, $namespace_or_prefix, $is_prefix);
}

function __mc_sxe_root(__McXmlDoc $doc, string $nsOrPrefix, bool $isPrefix): SimpleXMLElement
{
    $uri = '';
    $filter = false;
    if ($nsOrPrefix !== '') {
        $filter = true;
        $uri = $isPrefix ? $doc->lookupUri($doc->root, $nsOrPrefix) : $nsOrPrefix;
    }
    return SimpleXMLElement::__mcWrap($doc, [$doc->root], false, false, $uri, $filter, []);
}
