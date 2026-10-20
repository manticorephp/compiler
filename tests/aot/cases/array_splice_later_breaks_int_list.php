<?php
// An int list passed to an int[] parameter reads wrong values when the same function later array_splices it (reduced from manticore-fmt).
final class Gap
{
    public const G_COMMENT = 4;
    public static function flags(string $g): int
    {
        $n = \strlen($g);
        if (\strspn($g, " \t\r\n") === $n) {
            $a = \strspn($g, " \t");
            if ($a === $n) {
            }
        }
        if (\strpos($g, "\r") !== false) {
        }
        while ($i < $n) {
            $c = \ord($g[$i]);
            if ($c === 35 || ($c === 47 && $i + 1 < $n && \ord($g[$i + 1]) === 47)) {
                $j = $i + \strcspn($g, "\r\n", $i);
            }
            $i = $j;
        }
    }
    /**
     */
    public static function splitPieces(array $p, int $off): array
    {
        $at = 0;
        foreach ($p as $k => $x) {
            $l = \strlen($x);
            if ($off > $at && $off < $at + $l && self::isSpace($x)) {
            }
        }
    }
}
final class FlatTokens
{
    public array $k = [];
    public array $i = [];
    public function load(Source $s, int $a, int $b, bool $tail = false): void
    {
        for ($t = $a; $t <= $b + 1; $t++) {
            if ($t > $a && ($t <= $b || $tail)) {
                $g = $s->gapBefore[$t];
                if ($g === '') {
                } elseif (($s->gapFlags[$t] & Gap::G_COMMENT) === 0) {
                } else {
                    foreach (Gap::pieces($g) as $p) {
                    }
                }
            }
            if ($t > $b) {
                break;
            }
            $k[] = $s->ct[$t] !== 0 ? $s->ct[$t] : $s->kind[$t];
            $ix[] = $t;
        }
        $this->k = $k;
        $this->i = $ix;
    }
    public function store(Source $s): void
    {
    }
    public function count(): int
    {
        return \count($this->k);
    }
    public function isWs(int $q): bool
    {
    }
    public function isEmpty(int $q): bool
    {
    }
    public function isId(int $q, int $id): bool
    {
    }
    public function meaningful(int $q, int $dir): int
    {
        $m = \count($this->k);
        for ($j = $q + $dir; $j >= 0 && $j < $m; $j += $dir) {
            if ($this->i[$j] !== -1) {
            }
        }
    }
    public function nonWhitespace(int $q, int $dir): int
    {
        $m = \count($this->k);
        for ($j = $q + $dir; $j >= 0 && $j < $m; $j += $dir) {
            if ($this->k[$j] !== self::WS) {
            }
        }
    }
    public function blockEnd(int $q, int $close): int
    {
    }
    public static function ws(string $text): array
    {
    }
    public function setWs(int $q, string $content): void
    {
    }
    public function insertAt(int $at, array $items): void
    {
    }
    public function nonEmptySibling(int $q, int $dir): int
    {
        $m = \count($this->k);
        for ($j = $q + $dir; $j >= 0 && $j < $m; $j += $dir) {
            if (!$this->isEmpty($j)) {
            }
        }
    }
}
/**
 */
final class Preg
{
    public static function utf8(string $s): bool
    {
    }
    public static function replace(string $pattern, string $to, string $subject, int $limit = -1): string
    {
    }
    public static function matchAll(string $pattern, string $subject, ?array &$m = null, int $flags = \PREG_PATTERN_ORDER): int
    {
    }
}
final class Source
{
    public int $n = 0;
    public array $kind = [];
    public array $ct = [];
    public array $gapBefore = [];
    public array $gapFlags = [];
    public array $pair = [];
    public function text(int $i): string
    {
    }
}
final class Inv
{
    public static function eff(Source $s, int $i): int
    {
        return $s->ct[$i] !== 0 ? $s->ct[$i] : $s->kind[$i];
    }
}
final class R
{
    private string $broken = '';
    private Source $s;
    public function __construct(Source $s) { $this->s = $s; }
    public function go(int $t): void { $this->fixAttribute($t); echo 'broken=', $this->broken, "\n"; }
    private function fixAttribute(int $t): void
    {
        $s = $this->s;
        $close = $s->pair[$t];
        if ($close < 0) {
        }
        $limit = \min($s->n - 1, $close + ($close - $t) + 4);
        $ft = new FlatTokens();
        $ft->load($s, $t, $limit);
        /** @var int[] $kind */
        $kind = [];
        $m = $ft->count();
        for ($q = 0; $q < $m; $q++) {
            $tok = $ft->i[$q];
            if ($tok < 0) {
                $kind[] = -1;
                continue;
            }
            if (isset($this->over[$tok])) {
                foreach (self::nameTexts($this->over[$tok][1]) as $nt) {
                    $kind[] = $nt === '\\' ? 380 : 262;
                }
            }
            $kind[] = Inv::eff($s, $tok);
        }
        $closeQ = -1;
        $depth = 0;
        foreach ($kind as $q => $k) {
            if ($k === 355) {
                if ($depth === 0) {
                }
            }
        }
        $starts = [];
        $index = 0;
        do {
            $index = self::nextOfKinds($kind, $index + 1, true);
            if ($index < 0) {
                $this->broken = 'line 650 ' . \implode(',', $kind) . ' f=' . self::nextOfKinds($kind, 1, true) . ' k5=' . \var_export($kind[5] === 40, true) . ' t=' . \gettype($kind[5]) . ' m=' . self::meaningful($kind, 0, 1);
            }
            if ($kind[$index] === 40) {
                for ($x = $index; $x < \count($kind); $x++) {
                    if ($kind[$x] === 40) {
                    }
                }
            }
            $next = $index;
            if ($next < $closeQ) {
            }
        } while ($next >= 0 && $next < $closeQ);
        foreach ($starts as $start) {
            $idx = $start;
            if ($idx >= \count($kind)) {
            }
            $a = -1;
            $b = -1;
            for ($j = self::meaningful($kind, $idx, 1); $j >= 0 && ($kind[$j] === 262 || $kind[$j] === 380); $j = self::meaningful($kind, $j, 1)) {
            }
            if ($a < 0) {
            }
            for ($x = $a; $x <= $b; $x++) {
            }
            $new = $this->determineShortType($content);
            if ($new === null) {
            }
            $srcA = -1;
            $fresh = true;
            if ($fresh) {
                if ($srcA < 0) {
                }
                foreach ($this->over as $oa => [$ob, $old]) {
                    if ($old === $content && $srcA < 0) {
                    }
                }
            }
            $nk = [];
            foreach (self::nameTexts($new) as $tx) {
            }
            \array_splice($kind, $a, $b - $a + 1, $nk);
        }
    }
    private static function meaningful(array $kind, int $q, int $dir): int
    {
        $m = \count($kind);
        for ($j = $q + $dir; $j >= 0 && $j < $m; $j += $dir) {
            if ($kind[$j] !== -1) {
            }
        }
    }
    /**
     * @param int[] $kind
     */
    private static function nextOfKinds(array $kind, int $q, bool $paren): int
    {
        $m = \count($kind);
        for ($j = $q + 1; $j < $m; $j++) {
            $k = $kind[$j];
            if (($paren && $k === 40) || $k === 44 || $k === 10031) {
                return $j;
            }
        }
        return -1;
    }
}
$s = new Source();
$s->kind = [355, 262, 40, 260, 41, 44, 262, 93];
$s->ct = [0, 0, 0, 0, 0, 0, 0, 10031];
$s->gapBefore = ['', '', '', '', '', '', ' ', '', ''];
$s->gapFlags = [0, 0, 0, 0, 0, 0, 0, 0, 0];
$s->n = 8;
$s->pair = [7, -1, 4, -1, 2, -1, -1, 0];
(new R($s))->go(0);
