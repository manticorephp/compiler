<?php
/*
 * The 8.4 `Dom\` API over the legacy tree: the same nodes answer to both names.
 * ponytail: markers, not php's separate class tree, so a legacy DOMNode is also `instanceof Dom\Node`
 * and nodeName stays lower-case where php's HTML document upper-cases it. Split when a program tells them apart.
 */
namespace Dom {
    interface Node {}
    interface Document extends Node {}
    interface Element extends Node {}
    interface Attr extends Node {}
    interface Text extends Node {}
    interface CDATASection extends Text {}
    interface Comment extends Node {}
    interface ProcessingInstruction extends Node {}
}

namespace {
// ext/dom over the SAME __McXmlDoc node table SimpleXML uses — which is what
// makes simplexml_import_dom / dom_import_simplexml a zero-copy handover rather
// than a tree conversion. DEMAND-GATED separately from xml.php: the class tree
// is the larger half and most SimpleXML programs never name it.
//
// ⚠ SOURCE ORDER IS LOAD-BEARING — classes are built in the order they are
// parsed, and a subclass ahead of its parent inherits ZERO slots (its objects
// then walk off their own layout and smash the allocator). DOMNode comes first
// here, then DOMCharacterData, then the leaves.
//
// KNOWN DEVIATION: node identity is not preserved. `$n->firstChild ===
// $n->firstChild` is false because each read builds a fresh wrapper. Caching
// wrappers on the document would close a doc → node → doc cycle, and the cycle
// collector has no roots yet, so a document would leak whole. isSameNode() is
// the supported test and compares the node id.

class DOMException extends Exception
{
}

class DOMNode implements \Dom\Node
{
    /** Names are `__`-prefixed: the DOM property surface is served by __get, so
     *  a real property here would shadow one of its names. */
    public ?__McXmlDoc $__d = null;
    public int $__n = -1;

    public function __mcInit(__McXmlDoc $d, int $n): void
    {
        $this->__d = $d;
        $this->__n = $n;
    }

    public function __get(string $name): mixed
    {
        $d = $this->__d;
        $n = $this->__n;
        if ($d === null) {
            return null;
        }
        // A DOCUMENT carries node id -1: it is not a row in the table, its
        // children are docKids, and its own properties answer from the table
        // header. Everything below indexes $n, so the document is served first.
        if ($n < 0) {
            if ($name === 'nodeType') { return XML_DOCUMENT_NODE; }
            if ($name === 'nodeName') { return '#document'; }
            if ($name === 'nodeValue' || $name === 'prefix' || $name === 'localName') { return null; }
            if ($name === 'documentElement') {
                return $d->root < 0 ? null : \__mc_dom_wrap($d, $d->root);
            }
            if ($name === 'childNodes') { return new DOMNodeList($d, $d->docKids); }
            if ($name === 'firstChild') {
                return \count($d->docKids) === 0 ? null : \__mc_dom_wrap($d, $d->docKids[0]);
            }
            if ($name === 'lastChild') {
                $c = \count($d->docKids);
                return $c === 0 ? null : \__mc_dom_wrap($d, $d->docKids[$c - 1]);
            }
            if ($name === 'textContent') {
                $s = '';
                foreach ($d->docKids as $k) { $s = $s . $d->textContent($k); }
                return $s;
            }
            if ($name === 'ownerDocument' || $name === 'parentNode'
                || $name === 'nextSibling' || $name === 'previousSibling'
                || $name === 'attributes' || $name === 'namespaceURI') {
                return null;
            }
            if ($name === 'xmlVersion') { return $d->xmlVersion; }
            if ($name === 'encoding' || $name === 'xmlEncoding' || $name === 'actualEncoding') {
                return $d->encoding === '' ? null : $d->encoding;
            }
            if ($name === 'standalone' || $name === 'xmlStandalone') { return $d->standalone === 1; }
            if ($name === 'formatOutput') { return $d->formatOutput; }
            if ($name === 'preserveWhiteSpace') { return $d->preserveWhiteSpace; }
            if ($name === 'validateOnParse') { return $d->validateOnParse; }
            if ($name === 'documentURI' || $name === 'baseURI') {
                return $d->uriBase === '' ? null : $d->uriBase;
            }
            return null;
        }

        if ($name === 'nodeType') {
            return $d->type[$n];
        }
        if ($name === 'nodeName') {
            $t = $d->type[$n];
            if ($t === XML_TEXT_NODE) { return '#text'; }
            if ($t === XML_CDATA_SECTION_NODE) { return '#cdata-section'; }
            if ($t === XML_COMMENT_NODE) { return '#comment'; }
            if ($t === XML_DOCUMENT_NODE) { return '#document'; }
            return \__mc_xml_qual($d, $n);
        }
        if ($name === 'nodeValue') {
            $t = $d->type[$n];
            if ($t === XML_ELEMENT_NODE || $t === XML_DOCUMENT_NODE) {
                return $d->textContent($n);
            }
            return $d->value[$n];
        }
        if ($name === 'textContent') {
            return $d->textContent($n);
        }
        if ($name === 'localName') {
            return $d->name[$n];
        }
        if ($name === 'prefix') {
            return $d->prefix[$n];
        }
        if ($name === 'namespaceURI') {
            return $d->uri[$n] === '' ? null : $d->uri[$n];
        }
        if ($name === 'parentNode') {
            $p = $d->parent[$n];
            if ($p < 0) {
                return $n === $d->root ? \__mc_dom_document($d) : null;
            }
            return \__mc_dom_wrap($d, $p);
        }
        if ($name === 'childNodes') {
            return new DOMNodeList($d, $n === -1 ? $d->docKids : $d->kids[$n]);
        }
        if ($name === 'firstChild') {
            $ks = $d->kids[$n];
            return \count($ks) === 0 ? null : \__mc_dom_wrap($d, $ks[0]);
        }
        if ($name === 'lastChild') {
            $ks = $d->kids[$n];
            $c = \count($ks);
            return $c === 0 ? null : \__mc_dom_wrap($d, $ks[$c - 1]);
        }
        if ($name === 'nextSibling' || $name === 'previousSibling') {
            return \__mc_dom_sibling($d, $n, $name === 'nextSibling');
        }
        if ($name === 'attributes') {
            if ($d->type[$n] !== XML_ELEMENT_NODE) {
                return null;
            }
            return new DOMNamedNodeMap($d, $d->attrs[$n]);
        }
        if ($name === 'ownerDocument') {
            return \__mc_dom_document($d);
        }
        if ($name === 'baseURI') {
            return $d->uriBase === '' ? null : $d->uriBase;
        }
        // DOMElement
        if ($name === 'tagName') {
            return \__mc_xml_qual($d, $n);
        }
        // DOMAttr
        if ($name === 'name') {
            return \__mc_xml_qual($d, $n);
        }
        if ($name === 'value' || $name === 'data') {
            return $d->value[$n];
        }
        if ($name === 'length') {
            return \strlen($d->value[$n]);
        }
        // DOMDocument
        if ($name === 'documentElement') {
            return $d->root < 0 ? null : \__mc_dom_wrap($d, $d->root);
        }
        if ($name === 'xmlVersion') {
            return $d->xmlVersion;
        }
        if ($name === 'encoding' || $name === 'xmlEncoding' || $name === 'actualEncoding') {
            return $d->encoding === '' ? null : $d->encoding;
        }
        if ($name === 'standalone' || $name === 'xmlStandalone') {
            return $d->standalone === 1;
        }
        if ($name === 'formatOutput') {
            return $d->formatOutput;
        }
        if ($name === 'preserveWhiteSpace') {
            return $d->preserveWhiteSpace;
        }
        if ($name === 'documentURI') {
            return $d->uriBase === '' ? null : $d->uriBase;
        }
        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        $d = $this->__d;
        $n = $this->__n;
        if ($d === null) {
            return;
        }
        // The document-level knobs are settable on the DOCUMENT (node id -1),
        // which is exactly where `$doc->formatOutput = true` lands.
        if ($name === 'formatOutput') {
            $d->formatOutput = (bool) $value;
            return;
        }
        if ($name === 'preserveWhiteSpace') {
            $d->preserveWhiteSpace = (bool) $value;
            return;
        }
        if ($name === 'validateOnParse') {
            $d->validateOnParse = (bool) $value;
            return;
        }
        if ($name === 'encoding') {
            $d->encoding = (string) $value;
            return;
        }
        if ($name === 'xmlVersion') {
            $d->xmlVersion = (string) $value;
            return;
        }
        if ($n < 0) {
            return;
        }
        if ($name === 'nodeValue' || $name === 'value' || $name === 'data'
            || $name === 'textContent') {
            if ($d->type[$n] === XML_ELEMENT_NODE) {
                $d->kids[$n] = [];
                $t = $d->newNode(XML_TEXT_NODE, '#text', '', '', (string) $value);
                $d->appendKid($n, $t);
                return;
            }
            $d->value[$n] = (string) $value;
            return;
        }
    }

    public function __isset(string $name): bool
    {
        return $this->__get($name) !== null;
    }

    public function hasChildNodes(): bool
    {
        $d = $this->__d;
        if ($d === null) {
            return false;
        }
        if ($this->__n < 0) {
            return \count($d->docKids) > 0;
        }
        return \count($d->kids[$this->__n]) > 0;
    }

    public function hasAttributes(): bool
    {
        $d = $this->__d;
        return $d !== null && $this->__n >= 0 && \count($d->attrs[$this->__n]) > 0;
    }

    /** Node identity, since `===` on two wrappers of one node is false here. */
    public function isSameNode(?DOMNode $other): bool
    {
        return $other !== null && $other->__n === $this->__n && $other->__d === $this->__d;
    }

    public function appendChild(DOMNode $node): ?DOMNode
    {
        $d = $this->__d;
        if ($d === null) {
            return null;
        }
        $id = \__mc_dom_adopt($d, $node);
        $d->detach($id);
        // Appending to the DOCUMENT: the node joins docKids, and the first
        // element to land there becomes documentElement — `$doc->appendChild(
        // $doc->createElement('root'))` is the canonical way a document is built.
        if ($this->__n < 0) {
            $dk = $d->docKids;
            $dk[] = $id;
            $d->docKids = $dk;
            $d->parent[$id] = -1;
            if ($d->root < 0 && $d->type[$id] === XML_ELEMENT_NODE) {
                $d->root = $id;
            }
            return \__mc_dom_wrap($d, $id);
        }
        $d->appendKid($this->__n, $id);
        return \__mc_dom_wrap($d, $id);
    }

    public function insertBefore(DOMNode $node, ?DOMNode $ref = null): ?DOMNode
    {
        $d = $this->__d;
        if ($d === null || $this->__n < 0) {
            return null;
        }
        if ($ref === null) {
            return $this->appendChild($node);
        }
        $id = \__mc_dom_adopt($d, $node);
        $d->detach($id);
        $out = [];
        foreach ($d->kids[$this->__n] as $k) {
            if ($k === $ref->__n) {
                $out[] = $id;
            }
            $out[] = $k;
        }
        $d->kids[$this->__n] = $out;
        $d->parent[$id] = $this->__n;
        return \__mc_dom_wrap($d, $id);
    }

    public function removeChild(DOMNode $node): ?DOMNode
    {
        $d = $this->__d;
        if ($d === null) {
            return null;
        }
        $d->detach($node->__n);
        return $node;
    }

    public function replaceChild(DOMNode $node, DOMNode $old): ?DOMNode
    {
        $d = $this->__d;
        if ($d === null || $this->__n < 0) {
            return null;
        }
        $id = \__mc_dom_adopt($d, $node);
        $d->detach($id);
        $out = [];
        foreach ($d->kids[$this->__n] as $k) {
            $out[] = $k === $old->__n ? $id : $k;
        }
        $d->kids[$this->__n] = $out;
        $d->parent[$id] = $this->__n;
        $d->parent[$old->__n] = -1;
        return $old;
    }

    public function cloneNode(bool $deep = false): ?DOMNode
    {
        $d = $this->__d;
        if ($d === null || $this->__n < 0) {
            return null;
        }
        if ($deep) {
            return \__mc_dom_wrap($d, $d->copyNode($this->__n, $d));
        }
        $id = $d->newNode($d->type[$this->__n], $d->name[$this->__n], $d->prefix[$this->__n],
            $d->uri[$this->__n], $d->value[$this->__n]);
        $d->nsDecl[$id] = $d->nsDecl[$this->__n];
        foreach ($d->attrs[$this->__n] as $a) {
            $na = $d->newNode(XML_ATTRIBUTE_NODE, $d->name[$a], $d->prefix[$a],
                $d->uri[$a], $d->value[$a]);
            $d->addAttrNode($id, $na);
        }
        return \__mc_dom_wrap($d, $id);
    }

    public function lookupNamespaceUri(?string $prefix): ?string
    {
        $d = $this->__d;
        if ($d === null || $this->__n < 0) {
            return null;
        }
        $u = $d->lookupUri($this->__n, $prefix === null ? '' : $prefix);
        return $u === '' ? null : $u;
    }

    public function getNodePath(): ?string
    {
        $d = $this->__d;
        if ($d === null || $this->__n < 0) {
            return null;
        }
        return \__mc_dom_path($d, $this->__n);
    }

    /** The C14N-ish serialization of this node — DOMDocument::saveXML($node). */
    public function __mcXml(): string
    {
        return \__mc_xml_dump_node($this->__d, $this->__n, 0);
    }
}

class DOMCharacterData extends DOMNode
{
    public function appendData(string $data): bool
    {
        $this->__d->value[$this->__n] = $this->__d->value[$this->__n] . $data;
        return true;
    }
}

class DOMText extends DOMCharacterData implements \Dom\Text
{
    public function __construct(string $data = '')
    {
        if ($data === __MC_SXE_RAW) {
            return;
        }
        $d = new __McXmlDoc();
        $this->__mcInit($d, $d->newNode(XML_TEXT_NODE, '#text', '', '', $data));
    }

    public function isWhitespaceInElementContent(): bool
    {
        return \trim($this->__d->value[$this->__n]) === '';
    }
}

class DOMCdataSection extends DOMText implements \Dom\CDATASection
{
    public function __construct(string $data = '')
    {
        parent::__construct(__MC_SXE_RAW);
        if ($data === __MC_SXE_RAW) {
            return;
        }
        $d = new __McXmlDoc();
        $this->__mcInit($d, $d->newNode(XML_CDATA_SECTION_NODE, '#cdata-section', '', '', $data));
    }
}

class DOMComment extends DOMCharacterData implements \Dom\Comment
{
    public function __construct(string $data = '')
    {
        if ($data === __MC_SXE_RAW) {
            return;
        }
        $d = new __McXmlDoc();
        $this->__mcInit($d, $d->newNode(XML_COMMENT_NODE, '#comment', '', '', $data));
    }
}

class DOMProcessingInstruction extends DOMNode implements \Dom\ProcessingInstruction
{
    public function __construct(string $name = '', string $value = '')
    {
        if ($name === __MC_SXE_RAW) {
            return;
        }
        $d = new __McXmlDoc();
        $this->__mcInit($d, $d->newNode(XML_PI_NODE, $name, '', '', $value));
    }
}

class DOMAttr extends DOMNode implements \Dom\Attr
{
    public function __construct(string $name = '', string $value = '')
    {
        if ($name === __MC_SXE_RAW) {
            return;
        }
        $d = new __McXmlDoc();
        $this->__mcInit($d, $d->newNode(XML_ATTRIBUTE_NODE, $name, '', '', $value));
    }
}

class DOMElement extends DOMNode implements \Dom\Element
{
    public function __construct(string $qualifiedName = '', ?string $value = null,
                                string $namespace = '')
    {
        if ($qualifiedName === __MC_SXE_RAW) {
            return;
        }
        $d = new __McXmlDoc();
        $this->__mcInit($d, \__mc_dom_mkelem($d, $qualifiedName, $namespace));
        if ($value !== null) {
            $t = $d->newNode(XML_TEXT_NODE, '#text', '', '', $value);
            $d->appendKid($this->__n, $t);
        }
        $d->root = $this->__n;
        $d->docKids = [$this->__n];
    }

    public function getAttribute(string $qualifiedName): string
    {
        $a = $this->__d->findAttr($this->__n, \__mc_dom_local($qualifiedName), '', false);
        return $a < 0 ? '' : $this->__d->value[$a];
    }

    public function hasAttribute(string $qualifiedName): bool
    {
        return $this->__d->findAttr($this->__n, \__mc_dom_local($qualifiedName), '', false) >= 0;
    }

    public function setAttribute(string $qualifiedName, string $value): bool
    {
        $d = $this->__d;
        $a = $d->findAttr($this->__n, \__mc_dom_local($qualifiedName), '', false);
        if ($a >= 0) {
            $d->value[$a] = $value;
            return true;
        }
        $prefix = '';
        $local = $qualifiedName;
        $c = \strpos($qualifiedName, ':');
        if ($c !== false) {
            $prefix = \substr($qualifiedName, 0, $c);
            $local = \substr($qualifiedName, $c + 1);
        }
        $na = $d->newNode(XML_ATTRIBUTE_NODE, $local, $prefix,
            $prefix === '' ? '' : $d->lookupUri($this->__n, $prefix), $value);
        $d->addAttrNode($this->__n, $na);
        return true;
    }

    public function removeAttribute(string $qualifiedName): bool
    {
        $a = $this->__d->findAttr($this->__n, \__mc_dom_local($qualifiedName), '', false);
        if ($a < 0) {
            return false;
        }
        $this->__d->detachAttr($a);
        return true;
    }

    public function getAttributeNode(string $qualifiedName): ?DOMAttr
    {
        $a = $this->__d->findAttr($this->__n, \__mc_dom_local($qualifiedName), '', false);
        if ($a < 0) {
            return null;
        }
        $o = new DOMAttr(__MC_SXE_RAW);
        $o->__mcInit($this->__d, $a);
        return $o;
    }

    public function getAttributeNS(?string $namespace, string $localName): string
    {
        $a = $this->__d->findAttr($this->__n, $localName, $namespace === null ? '' : $namespace, true);
        return $a < 0 ? '' : $this->__d->value[$a];
    }

    public function hasAttributeNS(?string $namespace, string $localName): bool
    {
        return $this->__d->findAttr($this->__n, $localName,
            $namespace === null ? '' : $namespace, true) >= 0;
    }

    public function setAttributeNS(?string $namespace, string $qualifiedName, string $value): void
    {
        $d = $this->__d;
        $prefix = '';
        $local = $qualifiedName;
        $c = \strpos($qualifiedName, ':');
        if ($c !== false) {
            $prefix = \substr($qualifiedName, 0, $c);
            $local = \substr($qualifiedName, $c + 1);
        }
        $uri = $namespace === null ? '' : $namespace;
        $a = $d->findAttr($this->__n, $local, $uri, true);
        if ($a >= 0) {
            $d->value[$a] = $value;
            return;
        }
        if ($uri !== '' && $prefix !== '' && $d->lookupUri($this->__n, $prefix) !== $uri) {
            $decl = $d->nsDecl[$this->__n];
            $decl[$prefix] = $uri;
            $d->nsDecl[$this->__n] = $decl;
        }
        $na = $d->newNode(XML_ATTRIBUTE_NODE, $local, $prefix, $uri, $value);
        $d->addAttrNode($this->__n, $na);
    }

    public function removeAttributeNS(?string $namespace, string $localName): void
    {
        $a = $this->__d->findAttr($this->__n, $localName,
            $namespace === null ? '' : $namespace, true);
        if ($a >= 0) {
            $this->__d->detachAttr($a);
        }
    }

    public function getElementsByTagName(string $qualifiedName): DOMNodeList
    {
        $hits = [];
        \__mc_dom_by_tag($this->__d, $this->__n, $qualifiedName, '', false, $hits);
        return new DOMNodeList($this->__d, $hits);
    }

    public function getElementsByTagNameNS(?string $namespace, string $localName): DOMNodeList
    {
        $hits = [];
        \__mc_dom_by_tag($this->__d, $this->__n, $localName,
            $namespace === null ? '' : $namespace, true, $hits);
        return new DOMNodeList($this->__d, $hits);
    }
}

class DOMDocumentFragment extends DOMNode
{
    public function __construct()
    {
        $d = new __McXmlDoc();
        $this->__mcInit($d, $d->newNode(XML_DOCUMENT_FRAG_NODE, '#document-fragment', '', '', ''));
    }
}

class DOMDocument extends DOMNode
{
    public function __construct(string $version = '1.0', string $encoding = '')
    {
        if ($version === __MC_SXE_RAW) {
            return;
        }
        $d = new __McXmlDoc();
        $d->xmlVersion = $version;
        $d->encoding = $encoding;
        // -1 is the DOCUMENT context: its children are docKids, which is what
        // the XPath engine's root axis walks too.
        $this->__mcInit($d, -1);
    }

    public function loadXML(string $source, int $options = 0): bool
    {
        return $this->__mcLoad($source, $options, false, '');
    }

    public function load(string $filename, int $options = 0): bool
    {
        return $this->__mcLoad($filename, $options, true, $filename);
    }

    /** Shared by loadXML/load so `validateOnParse` — set on the DOCUMENT before
     *  the call, which is what symfony's XmlUtils::parse does — actually reaches
     *  the parser as LIBXML_DTDVALID. The flag survives the reload because it
     *  belongs to the wrapper's intent, not to the table being replaced. */
    private function __mcLoad(string $src, int $options, bool $isFile, string $uri): bool
    {
        $keepValidate = $this->__d !== null && $this->__d->validateOnParse;
        $keepFormat = $this->__d !== null && $this->__d->formatOutput;
        $keepWs = $this->__d === null || $this->__d->preserveWhiteSpace;
        if ($keepValidate) {
            $options = $options | LIBXML_DTDVALID;
        }
        $doc = \__mc_xml_parse($src, $options, $isFile, $uri);
        if ($doc === null) {
            return false;
        }
        $doc->validateOnParse = $keepValidate;
        $doc->formatOutput = $keepFormat;
        $doc->preserveWhiteSpace = $keepWs;
        $this->__d = $doc;
        $this->__n = -1;
        return true;
    }

    /**
     * Merge adjacent text nodes and drop empty ones, depth first.
     *
     * symfony's XmlUtils::parse calls this straight after loadXML, so every DI
     * config / translation / validator mapping it loads goes through it.
     */
    public function normalizeDocument(): void
    {
        foreach ($this->__d->docKids as $k) {
            \__mc_dom_normalize($this->__d, $k);
        }
    }

    public function saveXML(?DOMNode $node = null, int $options = 0): string|bool
    {
        if ($node === null) {
            return \__mc_xml_dump_doc($this->__d);
        }
        return \__mc_xml_dump_node($node->__d, $node->__n, 0);
    }

    public function saveHTML(?DOMNode $node = null): string|false
    {
        $xml = $this->saveXML($node);
        if (!\is_string($xml)) { return false; }
        if ($node === null) { $xml = \preg_replace('/^<\?xml[^>]*\?>\s*/', '', $xml); }
        // HTML has no self-closing form: a void element stands alone, any other closes.
        $xml = \preg_replace('~<(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)((?:\s[^<>]*?)?)\s*/>~i', '<$1$2>', $xml);
        return \preg_replace('~<([A-Za-z_][\w:.-]*)((?:\s[^<>]*?)?)\s*/>~', '<$1$2></$1>', $xml);
    }

    public function save(string $filename, int $options = 0): int|bool
    {
        $xml = \__mc_xml_dump_doc($this->__d);
        $n = \file_put_contents($filename, $xml);
        return $n;
    }

    public function createElement(string $localName, string $value = ''): DOMElement|false
    {
        $d = $this->__d;
        $id = \__mc_dom_mkelem($d, $localName, '');
        if ($value !== '') {
            $t = $d->newNode(XML_TEXT_NODE, '#text', '', '', $value);
            $d->appendKid($id, $t);
        }
        $o = new DOMElement(__MC_SXE_RAW);
        $o->__mcInit($d, $id);
        return $o;
    }

    public function createElementNS(?string $namespace, string $qualifiedName,
                                    string $value = ''): DOMElement|false
    {
        $d = $this->__d;
        $id = \__mc_dom_mkelem($d, $qualifiedName, $namespace === null ? '' : $namespace);
        if ($value !== '') {
            $t = $d->newNode(XML_TEXT_NODE, '#text', '', '', $value);
            $d->appendKid($id, $t);
        }
        $o = new DOMElement(__MC_SXE_RAW);
        $o->__mcInit($d, $id);
        return $o;
    }

    public function createTextNode(string $data): DOMText
    {
        $o = new DOMText(__MC_SXE_RAW);
        $o->__mcInit($this->__d, $this->__d->newNode(XML_TEXT_NODE, '#text', '', '', $data));
        return $o;
    }

    public function createCDATASection(string $data): DOMCdataSection
    {
        $o = new DOMCdataSection(__MC_SXE_RAW);
        $o->__mcInit($this->__d,
            $this->__d->newNode(XML_CDATA_SECTION_NODE, '#cdata-section', '', '', $data));
        return $o;
    }

    public function createComment(string $data): DOMComment
    {
        $o = new DOMComment(__MC_SXE_RAW);
        $o->__mcInit($this->__d, $this->__d->newNode(XML_COMMENT_NODE, '#comment', '', '', $data));
        return $o;
    }

    public function createAttribute(string $localName): DOMAttr|false
    {
        $o = new DOMAttr(__MC_SXE_RAW);
        $o->__mcInit($this->__d,
            $this->__d->newNode(XML_ATTRIBUTE_NODE, $localName, '', '', ''));
        return $o;
    }

    /** Copy `$node` (and, when deep, its subtree) into THIS document's table. */
    public function importNode(DOMNode $node, bool $deep = false): DOMNode|false
    {
        $d = $this->__d;
        if ($deep) {
            return \__mc_dom_wrap($d, $d->copyNode($node->__n, $node->__d));
        }
        $src = $node->__d;
        $id = $d->newNode($src->type[$node->__n], $src->name[$node->__n],
            $src->prefix[$node->__n], $src->uri[$node->__n], $src->value[$node->__n]);
        $d->nsDecl[$id] = $src->nsDecl[$node->__n];
        return \__mc_dom_wrap($d, $id);
    }

    public function getElementsByTagName(string $qualifiedName): DOMNodeList
    {
        $hits = [];
        foreach ($this->__d->docKids as $k) {
            \__mc_dom_by_tag($this->__d, $k, $qualifiedName, '', false, $hits);
            if ($this->__d->type[$k] === XML_ELEMENT_NODE
                && \__mc_dom_tag_match($this->__d, $k, $qualifiedName, '', false)) {
                $hits[] = $k;
            }
        }
        return new DOMNodeList($this->__d, \__mc_dom_sorted($this->__d, $hits));
    }

    public function getElementsByTagNameNS(?string $namespace, string $localName): DOMNodeList
    {
        $uri = $namespace === null ? '' : $namespace;
        $hits = [];
        foreach ($this->__d->docKids as $k) {
            \__mc_dom_by_tag($this->__d, $k, $localName, $uri, true, $hits);
            if ($this->__d->type[$k] === XML_ELEMENT_NODE
                && \__mc_dom_tag_match($this->__d, $k, $localName, $uri, true)) {
                $hits[] = $k;
            }
        }
        return new DOMNodeList($this->__d, \__mc_dom_sorted($this->__d, $hits));
    }

    // ── Validation ─────────────────────────────────────────────────────────
    //
    // The tree is re-serialized and drained through a fresh validating reader.
    // That is the only shape available without a libxml2 tree, and it is still
    // entirely function calls: xmlSchemaNewMemParserCtxt / xmlSchemaParse /
    // xmlTextReaderSetSchema / xmlTextReaderIsValid.

    public function schemaValidate(string $filename, int $flags = 0): bool
    {
        return \__mc_xml_validate(\__mc_xml_dump_doc($this->__d), $filename, 1, true);
    }

    public function schemaValidateSource(string $source, int $flags = 0): bool
    {
        return \__mc_xml_validate(\__mc_xml_dump_doc($this->__d), $source, 1, false);
    }

    public function relaxNGValidate(string $filename): bool
    {
        return \__mc_xml_validate(\__mc_xml_dump_doc($this->__d), $filename, 2, true);
    }

    public function relaxNGValidateSource(string $source): bool
    {
        return \__mc_xml_validate(\__mc_xml_dump_doc($this->__d), $source, 2, false);
    }

    public function validate(): bool
    {
        return \__mc_xml_validate(\__mc_xml_dump_doc($this->__d), '', 0, false);
    }

    /** php's HTML parser is libxml2's OWN, with a decade of Zend-side quirks on
     *  top; nothing here approximates it, so say so rather than return a tree
     *  that silently differs. */
    public function loadHTML(string $source, int $options = 0): bool
    {
        throw new DOMException('DOMDocument::loadHTML is not supported by this runtime');
    }

    public function loadHTMLFile(string $filename, int $options = 0): bool
    {
        throw new DOMException('DOMDocument::loadHTMLFile is not supported by this runtime');
    }
}

class DOMNodeList implements Countable, Iterator
{
    public ?__McXmlDoc $__d = null;
    /** @var int[] */
    public array $__items = [];
    private int $__i = 0;

    public function __construct(?__McXmlDoc $d = null, array $items = [])
    {
        $this->__d = $d;
        $this->__items = $items;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'length') {
            return \count($this->__items);
        }
        return null;
    }

    public function count(): int
    {
        return \count($this->__items);
    }

    public function item(int $index): ?DOMNode
    {
        if ($index < 0 || $index >= \count($this->__items)) {
            return null;
        }
        return \__mc_dom_wrap($this->__d, $this->__items[$index]);
    }

    public function rewind(): void
    {
        $this->__i = 0;
    }

    public function valid(): bool
    {
        return $this->__i < \count($this->__items);
    }

    public function key(): int
    {
        return $this->__i;
    }

    public function current(): ?DOMNode
    {
        return \__mc_dom_wrap($this->__d, $this->__items[$this->__i]);
    }

    public function next(): void
    {
        $this->__i = $this->__i + 1;
    }
}

class DOMNamedNodeMap implements Countable, Iterator
{
    public function getIterator(): Iterator
    {
        return $this;
    }

    public ?__McXmlDoc $__d = null;
    /** @var int[] */
    public array $__items = [];
    private int $__i = 0;

    public function __construct(?__McXmlDoc $d = null, array $items = [])
    {
        $this->__d = $d;
        $this->__items = $items;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'length') {
            return \count($this->__items);
        }
        return null;
    }

    public function count(): int
    {
        return \count($this->__items);
    }

    public function item(int $index): ?DOMNode
    {
        if ($index < 0 || $index >= \count($this->__items)) {
            return null;
        }
        return \__mc_dom_wrap($this->__d, $this->__items[$index]);
    }

    public function getNamedItem(string $qualifiedName): ?DOMNode
    {
        foreach ($this->__items as $a) {
            if (\__mc_xml_qual($this->__d, $a) === $qualifiedName
                || $this->__d->name[$a] === $qualifiedName) {
                return \__mc_dom_wrap($this->__d, $a);
            }
        }
        return null;
    }

    public function getNamedItemNS(?string $namespace, string $localName): ?DOMNode
    {
        $uri = $namespace === null ? '' : $namespace;
        foreach ($this->__items as $a) {
            if ($this->__d->name[$a] === $localName && $this->__d->uri[$a] === $uri) {
                return \__mc_dom_wrap($this->__d, $a);
            }
        }
        return null;
    }

    public function rewind(): void
    {
        $this->__i = 0;
    }

    public function valid(): bool
    {
        return $this->__i < \count($this->__items);
    }

    public function key(): string
    {
        return $this->__d->name[$this->__items[$this->__i]];
    }

    public function current(): ?DOMNode
    {
        return \__mc_dom_wrap($this->__d, $this->__items[$this->__i]);
    }

    public function next(): void
    {
        $this->__i = $this->__i + 1;
    }
}

class DOMXPath
{
    public ?__McXmlDoc $__d = null;
    /** @var array<string,string> */
    private array $__ns = [];

    public function __construct(DOMDocument $document, bool $registerNodeNS = true)
    {
        $this->__d = $document->__d;
    }

    public function registerNamespace(string $prefix, string $namespace): bool
    {
        $ns = $this->__ns;
        $ns[$prefix] = $namespace;
        $this->__ns = $ns;
        return true;
    }

    public function query(string $expression, ?DOMNode $contextNode = null,
                          bool $registerNodeNS = true): DOMNodeList|false
    {
        $ctx = $contextNode === null ? -1 : $contextNode->__n;
        $hits = \__mc_xpath_nodes($this->__d, $ctx, $expression, $this->__ns);
        if ($hits === null) {
            return false;
        }
        return new DOMNodeList($this->__d, $hits);
    }

    /** A node-set expression yields a DOMNodeList; count()/string()/number()/
     *  boolean() yield the scalar. */
    public function evaluate(string $expression, ?DOMNode $contextNode = null,
                             bool $registerNodeNS = true): mixed
    {
        $ctx = $contextNode === null ? -1 : $contextNode->__n;
        $fn = \__mc_xpath_scalar_fn($expression);
        if ($fn === '') {
            return $this->query($expression, $contextNode, $registerNodeNS);
        }
        $inner = \substr($expression, \strlen($fn) + 1,
            \strlen($expression) - \strlen($fn) - 2);
        $hits = \__mc_xpath_nodes($this->__d, $ctx, $inner, $this->__ns);
        if ($hits === null) {
            return false;
        }
        if ($fn === 'count') {
            return \count($hits);
        }
        if ($fn === 'boolean') {
            return \count($hits) > 0;
        }
        $s = \count($hits) === 0 ? '' : $this->__d->textContent($hits[0]);
        if ($fn === 'number') {
            return (float) $s;
        }
        return $s;
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────

/** `count(...)` / `string(...)` / `number(...)` / `boolean(...)` wrapping the
 *  WHOLE expression → the function name, else ''. */
function __mc_xpath_scalar_fn(string $expr): string
{
    $e = \trim($expr);
    foreach (['count', 'string', 'number', 'boolean'] as $fn) {
        if (\str_starts_with($e, $fn . '(') && \str_ends_with($e, ')')) {
            return $fn;
        }
    }
    return '';
}

/** The wrapper class matching a node's type. */
function __mc_dom_wrap(__McXmlDoc $d, int $n): DOMNode
{
    if ($n < 0) {
        return \__mc_dom_document($d);
    }
    $t = $d->type[$n];
    if ($t === XML_ELEMENT_NODE) {
        $o = new DOMElement(__MC_SXE_RAW);
        $o->__mcInit($d, $n);
        return $o;
    }
    if ($t === XML_ATTRIBUTE_NODE) {
        $a = new DOMAttr(__MC_SXE_RAW);
        $a->__mcInit($d, $n);
        return $a;
    }
    if ($t === XML_CDATA_SECTION_NODE) {
        $c = new DOMCdataSection(__MC_SXE_RAW);
        $c->__mcInit($d, $n);
        return $c;
    }
    if ($t === XML_TEXT_NODE) {
        $x = new DOMText(__MC_SXE_RAW);
        $x->__mcInit($d, $n);
        return $x;
    }
    if ($t === XML_COMMENT_NODE) {
        $m = new DOMComment(__MC_SXE_RAW);
        $m->__mcInit($d, $n);
        return $m;
    }
    if ($t === XML_PI_NODE) {
        $p = new DOMProcessingInstruction(__MC_SXE_RAW);
        $p->__mcInit($d, $n);
        return $p;
    }
    $g = new DOMNode();
    $g->__mcInit($d, $n);
    return $g;
}

function __mc_dom_document(__McXmlDoc $d): DOMDocument
{
    $o = new DOMDocument(__MC_SXE_RAW);
    $o->__mcInit($d, -1);
    return $o;
}

/** The id `$node` has (or gets) inside `$d` — a node from ANOTHER document is
 *  deep-copied first, which is what php's implicit adoption amounts to. */
function __mc_dom_adopt(__McXmlDoc $d, DOMNode $node): int
{
    if ($node->__d === $d) {
        return $node->__n;
    }
    return $d->copyNode($node->__n, $node->__d);
}

function __mc_dom_local(string $qualifiedName): string
{
    $c = \strpos($qualifiedName, ':');
    if ($c === false) {
        return $qualifiedName;
    }
    return \substr($qualifiedName, $c + 1);
}

/** Create an element node for `prefix:local` bound to `$uri`. */
function __mc_dom_mkelem(__McXmlDoc $d, string $qualifiedName, string $uri): int
{
    $prefix = '';
    $local = $qualifiedName;
    $c = \strpos($qualifiedName, ':');
    if ($c !== false) {
        $prefix = \substr($qualifiedName, 0, $c);
        $local = \substr($qualifiedName, $c + 1);
    }
    $id = $d->newNode(XML_ELEMENT_NODE, $local, $prefix, $uri, '');
    if ($uri !== '') {
        $d->nsDecl[$id] = [$prefix => $uri];
    }
    return $id;
}

function __mc_dom_tag_match(__McXmlDoc $d, int $n, string $name, string $uri, bool $ns): bool
{
    if ($ns) {
        if ($uri !== '*' && $d->uri[$n] !== $uri) {
            return false;
        }
        return $name === '*' || $d->name[$n] === $name;
    }
    return $name === '*' || \__mc_xml_qual($d, $n) === $name || $d->name[$n] === $name;
}

/** @param int[] $hits */
function __mc_dom_by_tag(__McXmlDoc $d, int $n, string $name, string $uri, bool $ns, array &$hits): void
{
    foreach ($d->kids[$n] as $k) {
        if ($d->type[$k] !== XML_ELEMENT_NODE) {
            continue;
        }
        if (\__mc_dom_tag_match($d, $k, $name, $uri, $ns)) {
            $hits[] = $k;
        }
        \__mc_dom_by_tag($d, $k, $name, $uri, $ns, $hits);
    }
}

/** Document order for a hit list built root-then-descend. @param int[] $in @return int[] */
function __mc_dom_sorted(__McXmlDoc $d, array $in): array
{
    if (\count($in) < 2) {
        return $in;
    }
    $want = [];
    foreach ($in as $n) {
        $want[$n] = true;
    }
    $out = [];
    foreach (\__mc_xpath_docorder($d) as $n) {
        if (isset($want[$n])) {
            $out[] = $n;
        }
    }
    return $out;
}

/** Depth-first text normalisation: runs of adjacent TEXT nodes collapse into the
 *  first, and a text node left empty is removed. CDATA is a distinct node type
 *  and is never merged into a text run — php does not merge it either. */
function __mc_dom_normalize(__McXmlDoc $d, int $n): void
{
    if ($d->type[$n] !== XML_ELEMENT_NODE) {
        return;
    }
    $out = [];
    $run = -1;
    foreach ($d->kids[$n] as $k) {
        if ($d->type[$k] === XML_TEXT_NODE) {
            if ($run >= 0) {
                $d->value[$run] = $d->value[$run] . $d->value[$k];
                $d->parent[$k] = -1;
                continue;
            }
            $run = $k;
            $out[] = $k;
            continue;
        }
        $run = -1;
        $out[] = $k;
    }
    $keep = [];
    foreach ($out as $k) {
        if ($d->type[$k] === XML_TEXT_NODE && $d->value[$k] === '') {
            $d->parent[$k] = -1;
            continue;
        }
        $keep[] = $k;
    }
    $d->kids[$n] = $keep;
    foreach ($keep as $k) {
        \__mc_dom_normalize($d, $k);
    }
}

function __mc_dom_sibling(__McXmlDoc $d, int $n, bool $forward): ?DOMNode
{
    $p = $d->parent[$n];
    $sibs = $p < 0 ? $d->docKids : $d->kids[$p];
    $prev = -1;
    $take = false;
    foreach ($sibs as $s) {
        if ($take) {
            return \__mc_dom_wrap($d, $s);
        }
        if ($s === $n) {
            if (!$forward) {
                return $prev < 0 ? null : \__mc_dom_wrap($d, $prev);
            }
            $take = true;
        }
        $prev = $s;
    }
    return null;
}

/** `/a/b[2]` — the positional path php's getNodePath() returns. */
function __mc_dom_path(__McXmlDoc $d, int $n): string
{
    if ($d->type[$n] === XML_ATTRIBUTE_NODE) {
        return \__mc_dom_path($d, $d->parent[$n]) . '/@' . \__mc_xml_qual($d, $n);
    }
    $p = $d->parent[$n];
    $head = $p < 0 ? '' : \__mc_dom_path($d, $p);
    $sibs = $p < 0 ? $d->docKids : $d->kids[$p];
    $idx = 0;
    $seen = 0;
    foreach ($sibs as $s) {
        if ($d->type[$s] !== $d->type[$n] || $d->name[$s] !== $d->name[$n]) {
            continue;
        }
        $seen = $seen + 1;
        if ($s === $n) {
            $idx = $seen;
        }
    }
    $tail = '/' . \__mc_xml_qual($d, $n);
    if ($seen > 1) {
        $tail = $tail . '[' . $idx . ']';
    }
    return $head . $tail;
}

/**
 * Drain `$xml` through a validating reader.
 * $kind: 0 = DTD (XML_PARSER_VALIDATE), 1 = XSD, 2 = RelaxNG.
 * $schema is a FILE PATH when $isFile, else the schema source itself.
 */
function __mc_xml_validate(string $xml, string $schema, int $kind, bool $isFile): bool
{
    \__mc_xml_silence();
    $nul = \int_to_ptr(0);
    $reader = \__mc_xml_reader_for_memory($xml, \strlen($xml), $nul, $nul,
        LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    if (\ptr_to_int($reader) === 0) {
        return false;
    }

    $sch = $nul;
    $ctx = $nul;
    if ($kind === 0) {
        \__mc_xml_set_parser_prop($reader, __MC_XML_PROP_VALIDATE, 1);
    } elseif ($isFile) {
        if (\__mc_xml_reader_schema_validate($reader, $schema) !== 0) {
            \__mc_xml_reader_free($reader);
            \__mc_libxml_push(LIBXML_ERR_FATAL, 0, 0, 0, $schema, 'Invalid schema');
            return false;
        }
    } else {
        if ($kind === 1) {
            $ctx = \__mc_xml_schema_mem_ctxt($schema, \strlen($schema));
            if (\ptr_to_int($ctx) !== 0) {
                $sch = \__mc_xml_schema_parse($ctx);
                \__mc_xml_schema_free_ctxt($ctx);
            }
        } else {
            $ctx = \__mc_xml_rng_mem_ctxt($schema, \strlen($schema));
            if (\ptr_to_int($ctx) !== 0) {
                $sch = \__mc_xml_rng_parse($ctx);
                \__mc_xml_rng_free_ctxt($ctx);
            }
        }
        if (\ptr_to_int($sch) === 0) {
            \__mc_xml_reader_free($reader);
            \__mc_libxml_push(LIBXML_ERR_FATAL, 0, 0, 0, '', 'Invalid schema');
            return false;
        }
        if ($kind === 1) {
            \__mc_xml_reader_set_schema($reader, $sch);
        } else {
            \__mc_xml_reader_set_rng($reader, $sch);
        }
    }

    // No fd juggling around the drain: the structured error sink installed by
    // __mc_xml_silence() is what keeps libxml2's schema diagnostics off stderr.
    while (\__mc_xml_read($reader) === 1) {
    }
    $ok = \__mc_xml_is_valid($reader) === 1;
    \__mc_xml_reader_free($reader);
    if (\ptr_to_int($sch) !== 0) {
        if ($kind === 1) {
            \__mc_xml_schema_free($sch);
        } else {
            \__mc_xml_rng_free($sch);
        }
    }
    if (!$ok) {
        \__mc_libxml_push(LIBXML_ERR_ERROR, 1845, 0, 0, '',
            'Document does not validate against the schema');
    }
    return $ok;
}

// ── The bridge: one node table, two APIs ───────────────────────────────────

function simplexml_import_dom(DOMNode $node, ?string $class_name = 'SimpleXMLElement'): ?SimpleXMLElement
{
    $d = $node->__d;
    if ($d === null) {
        return null;
    }
    $n = $node->__n < 0 ? $d->root : $node->__n;
    if ($n < 0) {
        return null;
    }
    return SimpleXMLElement::__mcWrap($d, [$n], false,
        $d->type[$n] === XML_ATTRIBUTE_NODE, '', false, []);
}

function dom_import_simplexml(SimpleXMLElement $node): DOMElement
{
    $d = $node->__mcDoc();
    $n = $node->__mcNode();
    $o = new DOMElement(__MC_SXE_RAW);
    $o->__mcInit($d, $n);
    return $o;
}

}

namespace Dom {
    /**
     * `Dom\HTMLDocument`: a tokenizer and the tree-construction rules ordinary markup needs: implied
     * html/head/body/tbody, void and raw-text elements, the auto-closing of p, li, dd/dt, table parts
     * and option.
     * ponytail: no doctype node, no adoption agency or foster parenting, so misnested formatting (`<b><p></b>`) and text
     * directly inside a table shape differently from php. Add those insertion modes for scraped HTML.
     */
    final class HTMLDocument extends \DOMDocument implements Document
    {
        private const VOID = ['area' => 1, 'base' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1, 'img' => 1, 'input' => 1, 'link' => 1, 'meta' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1];
        private const HEAD = ['base' => 1, 'link' => 1, 'meta' => 1, 'noscript' => 1, 'script' => 1, 'style' => 1, 'template' => 1, 'title' => 1];
        private const CLOSES_P = ['address' => 1, 'article' => 1, 'aside' => 1, 'blockquote' => 1, 'center' => 1, 'details' => 1, 'dir' => 1, 'div' => 1, 'dl' => 1, 'fieldset' => 1, 'figcaption' => 1, 'figure' => 1, 'footer' => 1, 'form' => 1, 'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1, 'header' => 1, 'hgroup' => 1, 'hr' => 1, 'li' => 1, 'dd' => 1, 'dt' => 1, 'listing' => 1, 'main' => 1, 'menu' => 1, 'nav' => 1, 'ol' => 1, 'p' => 1, 'pre' => 1, 'section' => 1, 'summary' => 1, 'table' => 1, 'ul' => 1];

        /** @var \DOMElement[] */
        private array $__open = [];
        /** @var string[] */
        private array $__names = [];
        private string $__pending = '';
        private ?\DOMElement $__html = null;
        private ?\DOMElement $__head = null;
        private ?\DOMElement $__body = null;
        private bool $__inBody = false;

        public static function createFromString(string $source, int $options = 0, ?string $overrideEncoding = null): static
        {
            $d = new self();
            $d->__mcParseHtml($source);
            return $d;
        }

        private function __mcParseHtml(string $s): void
        {
            $this->__html = $this->createElement('html');
            $this->__head = $this->createElement('head');
            $this->__body = $this->createElement('body');
            $this->appendChild($this->__html);
            $this->__html->appendChild($this->__head);
            $this->__html->appendChild($this->__body);
            $this->__open = [$this->__html, $this->__head];
            $this->__names = ['html', 'head'];
            $n = \strlen($s);
            $i = 0;
            while ($i < $n) {
                $lt = \strpos($s, '<', $i);
                if ($lt === false) { $this->__pending .= \substr($s, $i); break; }
                if ($lt > $i) { $this->__pending .= \substr($s, $i, $lt - $i); }
                $c = $lt + 1 < $n ? $s[$lt + 1] : '';
                if (\substr($s, $lt, 4) === '<!--') {
                    $e = \strpos($s, '-->', $lt + 4);
                    $this->__mcFlush();
                    $text = $e === false ? \substr($s, $lt + 4) : \substr($s, $lt + 4, $e - $lt - 4);
                    $this->__mcTop()->appendChild($this->createComment($text));
                    $i = $e === false ? $n : $e + 3;
                } elseif ($c === '!' || $c === '?') {
                    $e = \strpos($s, '>', $lt);
                    $i = $e === false ? $n : $e + 1;
                } elseif ($c === '/' && $lt + 2 < $n && \ctype_alpha($s[$lt + 2])) {
                    $e = \strpos($s, '>', $lt);
                    if ($e === false) { break; }
                    $this->__mcEnd(\strtolower(\rtrim(\substr($s, $lt + 2, \strcspn($s, " \t\r\n\f/>", $lt + 2)))));
                    $i = $e + 1;
                } elseif ($c !== '' && \ctype_alpha($c)) {
                    $i = $this->__mcStart($s, $lt + 1, $n);
                } else {
                    $this->__pending .= '<';
                    $i = $lt + 1;
                }
            }
            $this->__mcFlush();
        }

        private function __mcTop(): \DOMElement
        {
            return $this->__open[\count($this->__open) - 1];
        }

        private function __mcToBody(): void
        {
            if (!$this->__inBody) {
                $this->__inBody = true;
                $this->__open = [$this->__html, $this->__body];
                $this->__names = ['html', 'body'];
            }
        }

        private function __mcFlush(): void
        {
            if ($this->__pending === '') { return; }
            $t = $this->__pending;
            $this->__pending = '';
            if (!$this->__inBody) {
                $t = \ltrim($t, " \t\r\n\f");
                if ($t === '') { return; }
                $this->__mcToBody();
            }
            $this->__mcTop()->appendChild($this->createTextNode(\html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }

        private function __mcPop(): void
        {
            \array_pop($this->__open);
            \array_pop($this->__names);
        }

        /** Close back through the innermost `$name`, stopping at a `$stop` element. */
        private function __mcCloseOpen(array $names, array $stop): void
        {
            for ($k = \count($this->__names) - 1; $k >= 2; $k = $k - 1) {
                $cur = $this->__names[$k];
                if (isset($stop[$cur])) { return; }
                if (isset($names[$cur])) {
                    while (\count($this->__names) > $k) { $this->__mcPop(); }
                    return;
                }
            }
        }

        private function __mcStart(string $s, int $p, int $n): int
        {
            $len = \strcspn($s, " \t\r\n\f/>", $p);
            $name = \strtolower(\substr($s, $p, $len));
            $p = $p + $len;
            /** @var array<string, string> $attrs */
            $attrs = [];
            while ($p < $n) {
                $p = $p + \strspn($s, " \t\r\n\f/", $p);
                if ($p >= $n) { break; }
                if ($s[$p] === '>') { $p = $p + 1; break; }
                $l = \strcspn($s, " \t\r\n\f/>=", $p);
                if ($l === 0) { $l = 1; }
                $an = \strtolower(\substr($s, $p, $l));
                $p = $p + $l;
                $p = $p + \strspn($s, " \t\r\n\f", $p);
                $v = '';
                if ($p < $n && $s[$p] === '=') {
                    $p = $p + 1;
                    $p = $p + \strspn($s, " \t\r\n\f", $p);
                    if ($p < $n && ($s[$p] === '"' || $s[$p] === "'")) {
                        $e = \strpos($s, $s[$p], $p + 1);
                        if ($e === false) { $e = $n; }
                        $v = \substr($s, $p + 1, $e - $p - 1);
                        $p = $e + 1;
                    } else {
                        $l = \strcspn($s, " \t\r\n\f>", $p);
                        $v = \substr($s, $p, $l);
                        $p = $p + $l;
                    }
                }
                if (!isset($attrs[$an])) { $attrs[$an] = \html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
            }
            $this->__mcFlush();
            if ($name === 'html' || $name === 'head' || $name === 'body') {
                $tgt = $name === 'html' ? $this->__html : ($name === 'head' ? $this->__head : $this->__body);
                foreach ($attrs as $k => $v) {
                    if (!$tgt->hasAttribute((string)$k)) { $tgt->setAttribute((string)$k, $v); }
                }
                if ($name === 'body') { $this->__mcToBody(); }
                return $p;
            }
            if (!$this->__inBody && !isset(self::HEAD[$name])) { $this->__mcToBody(); }
            if ($this->__inBody) { $this->__mcAutoClose($name); }
            $el = $this->createElement($name);
            foreach ($attrs as $k => $v) { $el->setAttribute((string)$k, $v); }
            $this->__mcTop()->appendChild($el);
            if ($name === 'script' || $name === 'style' || $name === 'title' || $name === 'textarea') {
                return $this->__mcRawText($s, $p, $n, $name, $el);
            }
            if (isset(self::VOID[$name])) { return $p; }
            $this->__open[] = $el;
            $this->__names[] = $name;
            if (($name === 'pre' || $name === 'listing') && $p < $n && $s[$p] === "\n") { $p = $p + 1; }
            return $p;
        }

        private function __mcAutoClose(string $name): void
        {
            $scope = ['table' => 1, 'td' => 1, 'th' => 1, 'caption' => 1, 'button' => 1, 'object' => 1, 'template' => 1];
            if ($name === 'li') {
                $this->__mcCloseOpen(['li' => 1], $scope + ['ul' => 1, 'ol' => 1]);
            } elseif ($name === 'dd' || $name === 'dt') {
                $this->__mcCloseOpen(['dd' => 1, 'dt' => 1], $scope + ['dl' => 1]);
            } elseif ($name === 'option') {
                $this->__mcCloseOpen(['option' => 1], ['select' => 1]);
            } elseif ($name === 'optgroup') {
                $this->__mcCloseOpen(['option' => 1, 'optgroup' => 1], ['select' => 1]);
            } elseif ($name === 'td' || $name === 'th') {
                $this->__mcCloseOpen(['td' => 1, 'th' => 1], ['table' => 1, 'tr' => 1]);
                $this->__mcTableChild('tr', ['tr' => 1]);
            } elseif ($name === 'tr') {
                $this->__mcCloseOpen(['tr' => 1], ['table' => 1, 'tbody' => 1, 'thead' => 1, 'tfoot' => 1]);
                $this->__mcTableChild('tbody', ['tbody' => 1, 'thead' => 1, 'tfoot' => 1]);
            } elseif ($name === 'tbody' || $name === 'thead' || $name === 'tfoot') {
                $this->__mcCloseOpen(['tbody' => 1, 'thead' => 1, 'tfoot' => 1], ['table' => 1]);
            }
            if (isset(self::CLOSES_P[$name])) { $this->__mcCloseOpen(['p' => 1], $scope); }
            if ($name[0] === 'h' && \strlen($name) === 2 && \ctype_digit($name[1])) {
                $top = $this->__names[\count($this->__names) - 1];
                if ($top[0] === 'h' && \strlen($top) === 2 && \ctype_digit($top[1])) { $this->__mcPop(); }
            }
        }

        /** `<table><tr>` implies the tbody, and a cell its tr. */
        private function __mcTableChild(string $make, array $have): void
        {
            $top = $this->__names[\count($this->__names) - 1];
            if (isset($have[$top])) { return; }
            if ($top === 'table' || ($make === 'tr' && ($top === 'tbody' || $top === 'thead' || $top === 'tfoot'))) {
                if ($make === 'tr' && $top === 'table') { $this->__mcTableChild('tbody', ['tbody' => 1]); }
                $el = $this->createElement($make);
                $this->__mcTop()->appendChild($el);
                $this->__open[] = $el;
                $this->__names[] = $make;
            }
        }

        private function __mcRawText(string $s, int $p, int $n, string $name, \DOMElement $el): int
        {
            $e = $p;
            $l = \strlen($name);
            while (true) {
                $e = \strpos($s, '</', $e);
                if ($e === false) { $e = $n; break; }
                if (\strtolower(\substr($s, $e + 2, $l)) === $name) { break; }
                $e = $e + 2;
            }
            $t = \substr($s, $p, $e - $p);
            if ($name === 'textarea' && $t !== '' && $t[0] === "\n") { $t = \substr($t, 1); }
            if ($name === 'title' || $name === 'textarea') { $t = \html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
            if ($t !== '') { $el->appendChild($this->createTextNode($t)); }
            if ($e >= $n) { return $n; }
            $g = \strpos($s, '>', $e);
            return $g === false ? $n : $g + 1;
        }

        private function __mcEnd(string $name): void
        {
            $this->__mcFlush();
            if ($name === 'html' || $name === 'body') { return; }
            if ($name === 'head') { $this->__mcToBody(); return; }
            if ($name === 'br') {
                $this->__mcToBody();
                $this->__mcTop()->appendChild($this->createElement('br'));
                return;
            }
            for ($k = \count($this->__names) - 1; $k >= 1; $k = $k - 1) {
                if ($this->__names[$k] === $name) {
                    while (\count($this->__names) > $k) { $this->__mcPop(); }
                    return;
                }
            }
            if ($name === 'p') {
                $this->__mcToBody();
                $this->__mcTop()->appendChild($this->createElement('p'));
            }
        }
    }
}
