<?php
// XPath 1.0 (working subset) over the __McXmlDoc node table — shared by
// SimpleXMLElement::xpath() and DOMXPath::query()/evaluate(). Injected with
// xml.php, never on its own.
//
// libxml2's own xmlXPathEvalExpression is not usable here: getting the members
// out of the returned xmlNodeSet means dereferencing _xmlXPathObject and
// _xmlNodeSet, and this module deliberately touches no C struct (see xml.php).
//
// ── Shape of the implementation ────────────────────────────────────────────
// Everything is parallel int[] / string[] arrays, never arrays of mixed tuples:
// a heterogeneous tuple inside a list is a known compiler hazard, and the arena
// holds homogeneous arrays far better. A parsed location path is therefore a
// set of parallel step arrays plus flat (from,to) token ranges for predicates,
// which are re-evaluated against the token stream per candidate node.

/** NCName start: letter, `_`, and — because XPath names reach element names —
 *  any high byte, so a UTF-8 element name tokenizes as one name. */
function __mc_xp_is_name_start(string $c): bool
{
    return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || $c === '_' || $c >= "\x80";
}

function __mc_xp_is_name_char(string $c): bool
{
    return \__mc_xp_is_name_start($c) || ($c >= '0' && $c <= '9') || $c === '-' || $c === '.';
}

const __MC_XP_NAME    = 'name';
const __MC_XP_STAR    = 'star';
const __MC_XP_NSSTAR  = 'nsstar';
const __MC_XP_TEXT    = 'text';
const __MC_XP_NODE    = 'node';
const __MC_XP_COMMENT = 'comment';
const __MC_XP_PI      = 'pi';

class __McXPath
{
    /** @var string[] */
    private array $t = [];
    private int $p = 0;
    private __McXmlDoc $d;
    /** @var array<string,string> prefix => URI */
    private array $ns = [];
    /** @var int[] every node id, in document order */
    private array $seq = [];

    public bool $err = false;

    // ── the parsed path, as parallel arrays ────────────────────────────────
    /** @var string[] */
    private array $stepAxis = [];
    /** @var string[] */
    private array $stepKind = [];
    /** @var string[] */
    private array $stepPrefix = [];
    /** @var string[] */
    private array $stepLocal = [];
    /** @var int[] index of this step's first predicate in predFrom */
    private array $stepPredFirst = [];
    /** @var int[] how many predicates this step has */
    private array $stepPredCount = [];
    /** @var int[] token index where predicate i starts */
    private array $predFrom = [];
    /** @var int[] token index one past predicate i */
    private array $predTo = [];

    // Scratch for the predicate evaluator: what the last primary produced.
    private string $lastKind = 'str';
    private int $lastCount = 0;

    public function __construct(__McXmlDoc $d, array $ns)
    {
        $this->d = $d;
        $this->ns = $ns;
        $this->seq = \__mc_xpath_docorder($d);
    }

    // ── Tokenizer ──────────────────────────────────────────────────────────
    //
    // A string literal keeps its quotes so the parser can tell 'a' from the
    // name a without a second table.

    private function tokenize(string $s): void
    {
        $this->t = [];
        $this->p = 0;
        $n = \strlen($s);
        $i = 0;
        while ($i < $n) {
            $c = $s[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i = $i + 1;
                continue;
            }
            if ($c === "'" || $c === '"') {
                $j = \strpos($s, $c, $i + 1);
                if ($j === false) {
                    $this->err = true;
                    return;
                }
                $this->t[] = \substr($s, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }
            if ($c === '/' && $i + 1 < $n && $s[$i + 1] === '/') {
                $this->t[] = '//';
                $i = $i + 2;
                continue;
            }
            if ($c === ':' && $i + 1 < $n && $s[$i + 1] === ':') {
                $this->t[] = '::';
                $i = $i + 2;
                continue;
            }
            if ($c === '!' && $i + 1 < $n && $s[$i + 1] === '=') {
                $this->t[] = '!=';
                $i = $i + 2;
                continue;
            }
            if (($c === '<' || $c === '>') && $i + 1 < $n && $s[$i + 1] === '=') {
                $this->t[] = $c . '=';
                $i = $i + 2;
                continue;
            }
            if ($c === '.' && $i + 1 < $n && $s[$i + 1] === '.') {
                $this->t[] = '..';
                $i = $i + 2;
                continue;
            }
            if ($c >= '0' && $c <= '9') {
                $j = $i;
                while ($j < $n && (($s[$j] >= '0' && $s[$j] <= '9') || $s[$j] === '.')) {
                    $j = $j + 1;
                }
                $this->t[] = \substr($s, $i, $j - $i);
                $i = $j;
                continue;
            }
            if (\__mc_xp_is_name_start($c)) {
                $j = $i;
                while ($j < $n && \__mc_xp_is_name_char($s[$j])) {
                    $j = $j + 1;
                }
                $this->t[] = \substr($s, $i, $j - $i);
                $i = $j;
                continue;
            }
            $this->t[] = $c;
            $i = $i + 1;
        }
    }

    private function peek(): string
    {
        if ($this->p >= \count($this->t)) {
            return '';
        }
        return $this->t[$this->p];
    }

    private function at(int $k): string
    {
        if ($k >= \count($this->t)) {
            return '';
        }
        return $this->t[$k];
    }

    // ── Public entry points ────────────────────────────────────────────────

    /**
     * Evaluate a union of location paths against $ctx.
     * @return int[]|null null on a parse error
     */
    public function nodes(string $expr, int $ctx): ?array
    {
        $this->tokenize($expr);
        if ($this->err) {
            return null;
        }
        $acc = [];
        while (true) {
            $one = $this->onePath($ctx);
            if ($this->err) {
                return null;
            }
            foreach ($one as $x) {
                $acc[] = $x;
            }
            if ($this->peek() !== '|') {
                break;
            }
            $this->p = $this->p + 1;
        }
        return $this->docOrder($acc);
    }

    /** One location path (no union), evaluated against $ctx. @return int[] */
    private function onePath(int $ctx): array
    {
        $this->stepAxis = [];
        $this->stepKind = [];
        $this->stepPrefix = [];
        $this->stepLocal = [];
        $this->stepPredFirst = [];
        $this->stepPredCount = [];
        $this->predFrom = [];
        $this->predTo = [];

        $cur = [$ctx];
        $tok = $this->peek();
        if ($tok === '/') {
            $this->p = $this->p + 1;
            $cur = [$this->d->root];
            // A lone `/` is the document root itself.
            if ($this->peek() === '' || $this->peek() === '|') {
                return $cur;
            }
            // `/a` selects the root element only when it matches, so run the
            // first step against the root's PARENT view: model that by making
            // the context the document and letting the step's child axis see
            // the root element.
            $this->parseSteps();
            return $this->runSteps([-1]);
        }
        if ($tok === '//') {
            $this->p = $this->p + 1;
            $this->parseSteps(true);
            return $this->runSteps([-1]);
        }
        $this->parseSteps();
        return $this->runSteps($cur);
    }

    /** Parse the step list; $leadingDescendant folds a leading `//` in. */
    private function parseSteps(bool $leadingDescendant = false): void
    {
        $first = true;
        while (true) {
            $axis = 'child';
            if ($first && $leadingDescendant) {
                $axis = 'descendant-or-self';
            }
            if (!$first) {
                $sep = $this->peek();
                if ($sep === '/') {
                    $this->p = $this->p + 1;
                } elseif ($sep === '//') {
                    $this->p = $this->p + 1;
                    $axis = 'descendant-or-self';
                } else {
                    return;
                }
            }
            $first = false;
            $this->parseStep($axis);
            if ($this->err) {
                return;
            }
            $nx = $this->peek();
            if ($nx !== '/' && $nx !== '//') {
                return;
            }
        }
    }

    private function parseStep(string $axis): void
    {
        $tok = $this->peek();

        if ($tok === '@') {
            $this->p = $this->p + 1;
            $axis = 'attribute';
            $tok = $this->peek();
        } elseif ($this->at($this->p + 1) === '::') {
            $named = $tok;
            $this->p = $this->p + 2;
            // A leading `//` already means descendant-or-self; an explicit axis
            // after it would be a second step, which the grammar above cannot
            // produce, so the explicit name simply wins.
            $axis = $named;
            $tok = $this->peek();
        }

        if ($tok === '.') {
            $this->p = $this->p + 1;
            $this->pushStep($axis === 'child' ? 'self' : $axis, __MC_XP_NODE, '', '');
            $this->parsePredicates();
            return;
        }
        if ($tok === '..') {
            $this->p = $this->p + 1;
            $this->pushStep('parent', __MC_XP_NODE, '', '');
            $this->parsePredicates();
            return;
        }

        // node test
        $prefix = '';
        $local = '';
        $kind = __MC_XP_NAME;

        if ($tok === '*') {
            $this->p = $this->p + 1;
            $kind = __MC_XP_STAR;
        } elseif ($tok === '') {
            $this->err = true;
            return;
        } else {
            $this->p = $this->p + 1;
            if ($this->peek() === '(') {
                // text() / node() / comment() / processing-instruction()
                $this->p = $this->p + 1;
                if ($this->peek() !== ')') {
                    // processing-instruction('target') — the target is ignored.
                    $this->p = $this->p + 1;
                }
                if ($this->peek() === ')') {
                    $this->p = $this->p + 1;
                }
                if ($tok === 'text') {
                    $kind = __MC_XP_TEXT;
                } elseif ($tok === 'comment') {
                    $kind = __MC_XP_COMMENT;
                } elseif ($tok === 'processing-instruction') {
                    $kind = __MC_XP_PI;
                } else {
                    $kind = __MC_XP_NODE;
                }
            } elseif ($this->peek() === ':') {
                $this->p = $this->p + 1;
                $prefix = $tok;
                $nx = $this->peek();
                $this->p = $this->p + 1;
                if ($nx === '*') {
                    $kind = __MC_XP_NSSTAR;
                } else {
                    $local = $nx;
                }
            } else {
                $local = $tok;
            }
        }
        $this->pushStep($axis, $kind, $prefix, $local);
        $this->parsePredicates();
    }

    private function pushStep(string $axis, string $kind, string $prefix, string $local): void
    {
        $this->stepAxis[] = $axis;
        $this->stepKind[] = $kind;
        $this->stepPrefix[] = $prefix;
        $this->stepLocal[] = $local;
        $this->stepPredFirst[] = \count($this->predFrom);
        $this->stepPredCount[] = 0;
    }

    /** Record each `[...]` as a token range; balance brackets so a nested
     *  predicate inside a function argument does not close the outer one. */
    private function parsePredicates(): void
    {
        $si = \count($this->stepAxis) - 1;
        $n = 0;
        while ($this->peek() === '[') {
            $this->p = $this->p + 1;
            $from = $this->p;
            $depth = 1;
            while ($this->p < \count($this->t)) {
                $c = $this->t[$this->p];
                if ($c === '[') {
                    $depth = $depth + 1;
                } elseif ($c === ']') {
                    $depth = $depth - 1;
                    if ($depth === 0) {
                        break;
                    }
                }
                $this->p = $this->p + 1;
            }
            if ($depth !== 0) {
                $this->err = true;
                return;
            }
            $this->predFrom[] = $from;
            $this->predTo[] = $this->p;
            $this->p = $this->p + 1;
            $n = $n + 1;
        }
        $this->stepPredCount[$si] = $n;
    }

    // ── Step execution ─────────────────────────────────────────────────────

    /** @param int[] $ctx  @return int[] */
    private function runSteps(array $ctx): array
    {
        $cur = $ctx;
        $ns = \count($this->stepAxis);
        for ($s = 0; $s < $ns; $s = $s + 1) {
            $next = [];
            foreach ($cur as $c) {
                $cands = $this->axisNodes($c, $this->stepAxis[$s], $s);
                // Predicates run per context node: position() is relative to
                // the set this ONE context node produced.
                $pc = $this->stepPredCount[$s];
                $pf = $this->stepPredFirst[$s];
                for ($k = 0; $k < $pc; $k = $k + 1) {
                    $kept = [];
                    $size = \count($cands);
                    $pos = 0;
                    foreach ($cands as $cand) {
                        $pos = $pos + 1;
                        if ($this->predBool($this->predFrom[$pf + $k], $this->predTo[$pf + $k],
                                $cand, $pos, $size)) {
                            $kept[] = $cand;
                        }
                    }
                    $cands = $kept;
                }
                foreach ($cands as $x) {
                    $next[] = $x;
                }
            }
            $cur = $this->docOrder($next);
        }
        return $cur;
    }

    /** Candidates on $axis from $c that pass step $s's node test. @return int[] */
    private function axisNodes(int $c, string $axis, int $s): array
    {
        $d = $this->d;
        $out = [];

        if ($axis === 'attribute') {
            if ($c < 0) {
                return $out;
            }
            foreach ($d->attrs[$c] as $a) {
                if ($this->testNode($a, $s)) {
                    $out[] = $a;
                }
            }
            return $out;
        }
        if ($axis === 'self') {
            if ($c >= 0 && $this->testNode($c, $s)) {
                $out[] = $c;
            }
            return $out;
        }
        if ($axis === 'parent') {
            if ($c < 0) {
                return $out;
            }
            $p = $d->parent[$c];
            if ($p >= 0 && $this->testNode($p, $s)) {
                $out[] = $p;
            }
            return $out;
        }
        if ($axis === 'ancestor' || $axis === 'ancestor-or-self') {
            $n = $axis === 'ancestor-or-self' ? $c : ($c < 0 ? -1 : $d->parent[$c]);
            while ($n >= 0) {
                if ($this->testNode($n, $s)) {
                    $out[] = $n;
                }
                $n = $d->parent[$n];
            }
            return $out;
        }
        if ($axis === 'following-sibling' || $axis === 'preceding-sibling') {
            if ($c < 0 || $d->parent[$c] < 0) {
                return $out;
            }
            $sibs = $d->kids[$d->parent[$c]];
            $seen = false;
            foreach ($sibs as $sib) {
                if ($sib === $c) {
                    $seen = true;
                    continue;
                }
                $want = $axis === 'following-sibling' ? $seen : !$seen;
                if ($want && $this->testNode($sib, $s)) {
                    $out[] = $sib;
                }
            }
            return $out;
        }

        // child / descendant / descendant-or-self — a -1 context is the
        // document, whose children are the document-level nodes.
        $kids = $c < 0 ? $d->docKids : $d->kids[$c];

        if ($axis === 'child') {
            foreach ($kids as $k) {
                if ($this->testNode($k, $s)) {
                    $out[] = $k;
                }
            }
            return $out;
        }
        if ($axis === 'descendant-or-self' && $c >= 0 && $this->testNode($c, $s)) {
            $out[] = $c;
        }
        foreach ($kids as $k) {
            $this->collectDesc($k, $s, $out);
        }
        return $out;
    }

    /** @param int[] $out */
    private function collectDesc(int $n, int $s, array &$out): void
    {
        if ($this->testNode($n, $s)) {
            $out[] = $n;
        }
        foreach ($this->d->kids[$n] as $k) {
            $this->collectDesc($k, $s, $out);
        }
    }

    private function testNode(int $n, int $s): bool
    {
        $d = $this->d;
        $kind = $this->stepKind[$s];
        $t = $d->type[$n];

        if ($kind === __MC_XP_NODE) {
            return true;
        }
        if ($kind === __MC_XP_TEXT) {
            return $t === XML_TEXT_NODE || $t === XML_CDATA_SECTION_NODE;
        }
        if ($kind === __MC_XP_COMMENT) {
            return $t === XML_COMMENT_NODE;
        }
        if ($kind === __MC_XP_PI) {
            return $t === XML_PI_NODE;
        }
        // A name test only ever matches the principal node type of the axis:
        // attributes on the attribute axis, elements everywhere else.
        if ($t !== XML_ELEMENT_NODE && $t !== XML_ATTRIBUTE_NODE) {
            return false;
        }
        if ($kind === __MC_XP_STAR) {
            return true;
        }
        $want = $this->resolvePrefix($this->stepPrefix[$s]);
        if ($kind === __MC_XP_NSSTAR) {
            return $d->uri[$n] === $want;
        }
        if ($d->name[$n] !== $this->stepLocal[$s]) {
            return false;
        }
        // XPath 1.0: an UNPREFIXED name test matches the no-namespace only.
        // This is exactly why symfony registers `xliff` before //xliff:file.
        return $d->uri[$n] === $want;
    }

    private function resolvePrefix(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }
        if ($prefix === 'xml') {
            return __MC_XML_URI;
        }
        if (isset($this->ns[$prefix])) {
            return $this->ns[$prefix];
        }
        return '';
    }

    /**
     * Put a candidate set into document order and drop duplicates.
     *
     * Done by walking the precomputed document sequence and keeping what is in
     * the set — NOT by sorting. `asort`/`ksort` live in prelude/array_fns.php,
     * which is gated on the USER's calls, so a prelude-only caller would link
     * against an undefined symbol.
     *
     * @param int[] $in
     * @return int[]
     */
    private function docOrder(array $in): array
    {
        if (\count($in) < 2) {
            return $in;
        }
        $want = [];
        foreach ($in as $n) {
            $want[$n] = true;
        }
        $out = [];
        foreach ($this->seq as $n) {
            if (isset($want[$n])) {
                $out[] = $n;
            }
        }
        return $out;
    }

    // ── Predicate evaluation ───────────────────────────────────────────────
    //
    // Values travel as strings; $lastKind says how the last primary should be
    // read when no comparison operator follows it (a number is a position test,
    // a node set is an existence test, a string is a non-empty test).

    private function predBool(int $from, int $to, int $node, int $pos, int $size): bool
    {
        $save = $this->p;
        $this->p = $from;
        $r = $this->pOr($to, $node, $pos, $size);
        $this->p = $save;
        return $r;
    }

    private function pOr(int $to, int $node, int $pos, int $size): bool
    {
        $r = $this->pAnd($to, $node, $pos, $size);
        while ($this->p < $to && $this->peek() === 'or') {
            $this->p = $this->p + 1;
            $r2 = $this->pAnd($to, $node, $pos, $size);
            $r = $r || $r2;
        }
        return $r;
    }

    private function pAnd(int $to, int $node, int $pos, int $size): bool
    {
        $r = $this->pCmp($to, $node, $pos, $size);
        while ($this->p < $to && $this->peek() === 'and') {
            $this->p = $this->p + 1;
            $r2 = $this->pCmp($to, $node, $pos, $size);
            $r = $r && $r2;
        }
        return $r;
    }

    private function pCmp(int $to, int $node, int $pos, int $size): bool
    {
        $lhs = $this->pPrimary($to, $node, $pos, $size);
        $lk = $this->lastKind;
        $lc = $this->lastCount;

        $op = $this->p < $to ? $this->peek() : '';
        if ($op !== '=' && $op !== '!=' && $op !== '<' && $op !== '>'
            && $op !== '<=' && $op !== '>=') {
            if ($lk === 'num') {
                return $pos === (int) $lhs;
            }
            if ($lk === 'set') {
                return $lc > 0;
            }
            if ($lk === 'bool') {
                return $lhs === '1';
            }
            return $lhs !== '';
        }
        $this->p = $this->p + 1;
        $rhs = $this->pPrimary($to, $node, $pos, $size);

        if ($op === '=') {
            return $lhs === $rhs;
        }
        if ($op === '!=') {
            return $lhs !== $rhs;
        }
        $a = (float) $lhs;
        $b = (float) $rhs;
        if ($op === '<') {
            return $a < $b;
        }
        if ($op === '>') {
            return $a > $b;
        }
        if ($op === '<=') {
            return $a <= $b;
        }
        return $a >= $b;
    }

    /** Returns the primary's string value; $lastKind/$lastCount describe it. */
    private function pPrimary(int $to, int $node, int $pos, int $size): string
    {
        $this->lastKind = 'str';
        $this->lastCount = 0;
        $tok = $this->p < $to ? $this->peek() : '';

        if ($tok === '') {
            return '';
        }
        if ($tok[0] === "'" || $tok[0] === '"') {
            $this->p = $this->p + 1;
            return \substr($tok, 1, \strlen($tok) - 2);
        }
        if ($tok[0] >= '0' && $tok[0] <= '9') {
            $this->p = $this->p + 1;
            $this->lastKind = 'num';
            return $tok;
        }
        if ($tok === '(') {
            $this->p = $this->p + 1;
            $r = $this->pOr($to, $node, $pos, $size);
            if ($this->peek() === ')') {
                $this->p = $this->p + 1;
            }
            $this->lastKind = 'bool';
            return $r ? '1' : '';
        }

        // Function calls, recognised only when a `(` actually follows.
        if ($this->at($this->p + 1) === '(') {
            $fn = $tok;
            if ($fn === 'last') {
                $this->p = $this->p + 3;
                $this->lastKind = 'str';
                return (string) $size;
            }
            if ($fn === 'position') {
                $this->p = $this->p + 3;
                $this->lastKind = 'str';
                return (string) $pos;
            }
            if ($fn === 'not') {
                $this->p = $this->p + 2;
                $r = $this->pOr($to, $node, $pos, $size);
                if ($this->peek() === ')') {
                    $this->p = $this->p + 1;
                }
                $this->lastKind = 'bool';
                return $r ? '' : '1';
            }
            if ($fn === 'contains' || $fn === 'starts-with') {
                $this->p = $this->p + 2;
                $a = $this->pPrimary($to, $node, $pos, $size);
                if ($this->peek() === ',') {
                    $this->p = $this->p + 1;
                }
                $b = $this->pPrimary($to, $node, $pos, $size);
                if ($this->peek() === ')') {
                    $this->p = $this->p + 1;
                }
                $this->lastKind = 'bool';
                $hit = $fn === 'contains' ? \str_contains($a, $b) : \str_starts_with($a, $b);
                return $hit ? '1' : '';
            }
            if ($fn === 'string-length' || $fn === 'normalize-space'
                || $fn === 'string' || $fn === 'number' || $fn === 'count'
                || $fn === 'local-name' || $fn === 'name') {
                $this->p = $this->p + 2;
                $inner = '';
                $cnt = 0;
                if ($this->peek() === ')') {
                    $inner = $this->d->textContent($node);
                    $cnt = 1;
                } else {
                    $inner = $this->pPrimary($to, $node, $pos, $size);
                    $cnt = $this->lastCount;
                }
                if ($this->peek() === ')') {
                    $this->p = $this->p + 1;
                }
                $this->lastKind = 'str';
                if ($fn === 'string-length') {
                    return (string) \strlen($inner);
                }
                if ($fn === 'normalize-space') {
                    return \trim($inner);
                }
                if ($fn === 'count') {
                    return (string) $cnt;
                }
                if ($fn === 'local-name' || $fn === 'name') {
                    return $this->d->name[$node];
                }
                return $inner;
            }
            // Unknown function — consume its call and yield nothing.
            $this->p = $this->p + 2;
            while ($this->p < $to && $this->peek() !== ')') {
                $this->p = $this->p + 1;
            }
            if ($this->peek() === ')') {
                $this->p = $this->p + 1;
            }
            return '';
        }

        // Otherwise it is a relative location path evaluated at $node.
        $start = $this->p;
        $end = $this->pathEnd($to);
        $sub = '';
        for ($i = $start; $i < $end; $i = $i + 1) {
            $sub .= $this->t[$i];
        }
        $this->p = $end;

        $inner = new __McXPath($this->d, $this->ns);
        $hits = $inner->nodes($sub, $node);
        $this->lastKind = 'set';
        if ($hits === null) {
            $this->lastCount = 0;
            return '';
        }
        $this->lastCount = \count($hits);
        if ($this->lastCount === 0) {
            return '';
        }
        return $this->d->textContent($hits[0]);
    }

    /** Token index one past the relative path starting at $this->p. */
    private function pathEnd(int $to): int
    {
        $i = $this->p;
        $depth = 0;
        while ($i < $to) {
            $c = $this->t[$i];
            if ($c === '[') {
                $depth = $depth + 1;
            } elseif ($c === ']') {
                $depth = $depth - 1;
            } elseif ($depth === 0) {
                if ($c === '=' || $c === '!=' || $c === '<' || $c === '>' || $c === '<='
                    || $c === '>=' || $c === 'and' || $c === 'or' || $c === ',' || $c === ')') {
                    return $i;
                }
            }
            $i = $i + 1;
        }
        return $to;
    }
}

/**
 * Every node id in document order, by one depth-first walk.
 *
 * Node ids alone will NOT do: addChild() hands a freshly allocated (high) id to
 * a node inserted anywhere in the tree, so id order and document order diverge
 * the moment a document is mutated.
 *
 * @return int[]
 */
function __mc_xpath_docorder(__McXmlDoc $doc): array
{
    $seq = [];
    foreach ($doc->docKids as $k) {
        \__mc_xpath_walk($doc, $k, $seq);
    }
    return $seq;
}

/** @param int[] $seq */
function __mc_xpath_walk(__McXmlDoc $doc, int $id, array &$seq): void
{
    $seq[] = $id;
    // Attributes precede an element's children in document order.
    foreach ($doc->attrs[$id] as $a) {
        $seq[] = $a;
    }
    foreach ($doc->kids[$id] as $k) {
        \__mc_xpath_walk($doc, $k, $seq);
    }
}

/**
 * The single entry point both APIs call.
 * @return int[]|null null on a malformed expression
 */
function __mc_xpath_nodes(__McXmlDoc $doc, int $ctx, string $expr, array $nsMap): ?array
{
    $xp = new __McXPath($doc, $nsMap);
    $hits = $xp->nodes($expr, $ctx);
    if ($xp->err) {
        return null;
    }
    return $hits;
}
