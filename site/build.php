<?php
// Builds the website: copies site/ static files into _site/ and renders docs/*.md into _site/docs/.
// Runs under php and under a compiled manticore binary:
//   php site/build.php [outdir]

const REPO = 'https://github.com/manticorephp/compiler';

final class Md
{
    /** @var array<string,int> */
    private array $slugs = [];
    /** @var string[] */
    public array $headings = [];
    public string $title = '';
    public string $excerpt = '';
    private string $dir;
    /** @var array<string,bool> */
    private array $docs;
    private string $root;

    /** @param array<string,bool> $docs */
    public function __construct(string $dir, array $docs, string $root)
    {
        $this->dir = $dir;
        $this->docs = $docs;
        $this->root = $root;
    }

    public function render(string $src): string
    {
        $src = str_replace("\r\n", "\n", $src);
        $this->slugs = [];
        $this->headings = [];
        return $this->blocks(explode("\n", $src), false);
    }

    /**
     * @param string[] $lines
     */
    private function blocks(array $lines, bool $tight): string
    {
        $out = '';
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }
            if (preg_match('/^\s*(```+|~~~+)\s*([\w+-]*)[^`]*$/', $line, $m)) {
                $fence = $m[1];
                $indent = strlen($line) - strlen(ltrim($line));
                $i++;
                $code = [];
                while ($i < $n && !preg_match('/^\s*' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $lines[$i])) {
                    $l = $lines[$i];
                    $code[] = (strlen($l) - strlen(ltrim($l)) >= $indent) ? substr($l, $indent) : ltrim($l);
                    $i++;
                }
                $i++;
                $cls = $m[2] !== '' ? ' class="lang-' . $m[2] . '"' : '';
                $out .= '<div class="code"><pre><code' . $cls . '>' . htmlspecialchars(implode("\n", $code)) . "</code></pre><button class=\"copy\">Copy</button></div>\n";
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
                $lvl = strlen($m[1]);
                $text = $m[2];
                $id = $this->slug($text);
                if ($lvl === 1 && $this->title === '') {
                    $this->title = html_entity_decode(strip_tags($this->inline($text)));
                }
                if ($lvl === 2 || $lvl === 3) {
                    $this->headings[] = $id . '|' . html_entity_decode(strip_tags($this->inline($text)));
                }
                $out .= "<h$lvl id=\"$id\">" . $this->inline($text) . ($lvl > 1 ? "<a class=\"anchor\" href=\"#$id\">#</a>" : '') . "</h$lvl>\n";
                $i++;
                continue;
            }
            if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line)) {
                $out .= "<hr>\n";
                $i++;
                continue;
            }
            if (preg_match('/^\s*<\/?(details|summary)\b.*$/', $line)) {
                $out .= trim($line) . "\n";
                $i++;
                continue;
            }
            if (strpos($line, '|') !== false && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$/', $lines[$i + 1]) && strpos($lines[$i + 1], '-') !== false) {
                $head = $this->cells($line);
                $al = [];
                foreach ($this->cells($lines[$i + 1]) as $c) {
                    $l = substr($c, 0, 1) === ':';
                    $r = substr($c, -1) === ':';
                    $al[] = ($l && $r) ? 'center' : ($r ? 'right' : ($l ? 'left' : ''));
                }
                $i += 2;
                $t = "<div class=\"tw\"><table>\n<thead><tr>";
                foreach ($head as $k => $c) {
                    $t .= '<th' . $this->align($al, $k) . '>' . $this->inline($c) . '</th>';
                }
                $t .= "</tr></thead>\n<tbody>\n";
                while ($i < $n && trim($lines[$i]) !== '' && strpos($lines[$i], '|') !== false) {
                    $t .= '<tr>';
                    foreach ($this->cells($lines[$i]) as $k => $c) {
                        $t .= '<td' . $this->align($al, $k) . '>' . $this->inline($c) . '</td>';
                    }
                    $t .= "</tr>\n";
                    $i++;
                }
                $out .= $t . "</tbody></table></div>\n";
                continue;
            }
            if (preg_match('/^\s{0,3}>/', $line)) {
                $q = [];
                while ($i < $n && preg_match('/^\s{0,3}>\s?(.*)$/', $lines[$i], $m)) {
                    $q[] = $m[1];
                    $i++;
                }
                $out .= "<blockquote>\n" . $this->blocks($q, false) . "</blockquote>\n";
                continue;
            }
            if (preg_match('/^( *)([-*+]|\d{1,9}[.)]) +\S/', $line)) {
                $out .= $this->listBlock($lines, $i, $n);
                continue;
            }
            $p = [];
            while ($i < $n && trim($lines[$i]) !== '' && !$this->startsBlock($lines[$i], $lines, $i, $n, count($p) > 0)) {
                $p[] = trim($lines[$i]);
                $i++;
            }
            if (count($p) === 0) {
                $p[] = trim($lines[$i]);
                $i++;
            }
            if ($this->excerpt === '') {
                $this->excerpt = html_entity_decode(strip_tags($this->inline(implode(' ', $p))));
            }
            $out .= $tight ? $this->inline(implode("\n", $p)) . "\n" : '<p>' . $this->inline(implode("\n", $p)) . "</p>\n";
        }
        return $out;
    }

    /** @param string[] $lines */
    private function startsBlock(string $l, array $lines, int $i, int $n, bool $inPara): bool
    {
        if (preg_match('/^\s*(```|~~~)/', $l) || preg_match('/^#{1,6}\s/', $l) || preg_match('/^\s{0,3}>/', $l)) {
            return true;
        }
        if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $l) || preg_match('/^\s*<\/?(details|summary)\b/', $l)) {
            return true;
        }
        if (preg_match('/^( *)([-*+]|\d{1,9}[.)]) +\S/', $l)) {
            return true;
        }
        return $inPara && strpos($l, '|') !== false && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[$i + 1]);
    }

    /** @param string[] $lines */
    private function listBlock(array $lines, int &$i, int $n): string
    {
        preg_match('/^( *)([-*+]|\d{1,9}[.)]) +/', $lines[$i], $f);
        $base = strlen($f[1]);
        $ordered = ctype_digit(substr($f[2], 0, 1));
        $html = '';
        while ($i < $n) {
            if (!preg_match('/^( *)([-*+]|\d{1,9}[.)])( +)(.*)$/', $lines[$i], $m) || strlen($m[1]) !== $base || ctype_digit(substr($m[2], 0, 1)) !== $ordered) {
                break;
            }
            $w = strlen($m[1]) + strlen($m[2]) + (strlen($m[3]) > 4 ? 1 : strlen($m[3]));
            $item = [$m[4]];
            $i++;
            $hadBlank = false;
            while ($i < $n) {
                $l = $lines[$i];
                if (trim($l) === '') {
                    $j = $i;
                    while ($j < $n && trim($lines[$j]) === '') {
                        $j++;
                    }
                    if ($j < $n && strlen($lines[$j]) - strlen(ltrim($lines[$j])) >= $w) {
                        for ($k = $i; $k < $j; $k++) {
                            $item[] = '';
                        }
                        $hadBlank = true;
                        $i = $j;
                        continue;
                    }
                    break;
                }
                if (strlen($l) - strlen(ltrim($l)) >= $w) {
                    $item[] = substr($l, $w);
                    $i++;
                    continue;
                }
                $prev = end($item);
                if ($prev !== '' && !preg_match('/^( *)([-*+]|\d{1,9}[.)]) +\S/', $l) && !preg_match('/^\s*(```|~~~|#{1,6}\s|>)/', $l)) {
                    $item[] = ltrim($l);
                    $i++;
                    continue;
                }
                break;
            }
            $box = '';
            if (preg_match('/^\[([ xX])\] (.*)$/', $item[0], $c)) {
                $box = '<input type="checkbox" disabled' . ($c[1] === ' ' ? '' : ' checked') . '> ';
                $item[0] = $c[2];
            }
            $html .= '<li>' . $box . rtrim($this->blocks($item, !$hadBlank)) . "</li>\n";
            $j = $i;
            while ($j < $n && trim($lines[$j]) === '') {
                $j++;
            }
            if ($j > $i && $j < $n && preg_match('/^( *)([-*+]|\d{1,9}[.)]) +\S/', $lines[$j]) && strlen($lines[$j]) - strlen(ltrim($lines[$j])) === $base) {
                $i = $j;
            }
        }
        $tag = $ordered ? 'ol' : 'ul';
        return "<$tag>\n$html</$tag>\n";
    }

    /** @return string[] */
    private function cells(string $row): array
    {
        $row = trim($row);
        if (substr($row, 0, 1) === '|') {
            $row = substr($row, 1);
        }
        if (substr($row, -1) === '|' && substr($row, -2) !== '\\|') {
            $row = substr($row, 0, -1);
        }
        $parts = preg_split('/(?<!\\\\)\|/', $row);
        $out = [];
        foreach ($parts as $p) {
            $out[] = trim(str_replace('\\|', '|', $p));
        }
        return $out;
    }

    /** @param string[] $al */
    private function align(array $al, int $k): string
    {
        return (isset($al[$k]) && $al[$k] !== '') ? ' style="text-align:' . $al[$k] . '"' : '';
    }

    private function slug(string $text): string
    {
        $s = strtolower(strip_tags($this->inline($text)));
        $s = html_entity_decode($s);
        $s = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $s);
        $s = preg_replace('/\s/u', '-', $s);
        if ($s === '') {
            $s = 'section';
        }
        $k = $s;
        if (isset($this->slugs[$k])) {
            $this->slugs[$k]++;
            $s = $k . '-' . $this->slugs[$k];
        } else {
            $this->slugs[$k] = 0;
        }
        return $s;
    }

    private function inline(string $t): string
    {
        $codes = [];
        $t = preg_replace_callback('/(`+)(.+?)(?<!`)\1(?!`)/s', function (array $m) use (&$codes): string {
            $codes[] = '<code>' . htmlspecialchars(trim($m[2])) . '</code>';
            return "\x01" . (count($codes) - 1) . "\x02";
        }, $t);
        $t = htmlspecialchars($t, ENT_NOQUOTES);
        $t = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;[^)]*&quot;|\s+"[^)]*")?\)/', function (array $m): string {
            return '<img src="' . $this->url($m[2], true) . '" alt="' . htmlspecialchars($m[1]) . '">';
        }, $t);
        $t = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"[^)]*")?\)/', function (array $m): string {
            return '<a href="' . $this->url($m[2], false) . '">' . $m[1] . '</a>';
        }, $t);
        $t = preg_replace('/&lt;(https?:\/\/[^\s&]+)&gt;/', '<a href="$1">$1</a>', $t);
        $t = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $t);
        $t = preg_replace('/(?<![\w*])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![\w*])/s', '<em>$1</em>', $t);
        $t = preg_replace('/(?<![\w])_(?=[^\s_])(.+?)(?<=[^\s_])_(?![\w])/s', '<em>$1</em>', $t);
        $t = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $t);
        $t = preg_replace_callback('/\x01(\d+)\x02/', function (array $m) use ($codes): string {
            return $codes[(int) $m[1]];
        }, $t);
        return $t;
    }

    private function url(string $u, bool $img): string
    {
        $u = html_entity_decode($u);
        if (preg_match('/^([a-z][a-z0-9+.-]*:|#|\/\/)/i', $u)) {
            return htmlspecialchars($u);
        }
        $frag = '';
        $p = strpos($u, '#');
        if ($p !== false) {
            $frag = substr($u, $p);
            $u = substr($u, 0, $p);
        }
        if ($u === '') {
            return htmlspecialchars($frag);
        }
        $path = $this->norm($this->dir === '' ? $u : $this->dir . '/' . $u);
        if (isset($this->docs[$path])) {
            return htmlspecialchars($this->root . substr($path, 5, -3) . '.html' . $frag);
        }
        $kind = is_dir(REPO_DIR . '/' . $path) ? 'tree' : ($img ? 'raw' : 'blob');
        $base = $kind === 'raw' ? 'https://raw.githubusercontent.com/manticorephp/compiler/main/' : REPO . '/' . $kind . '/main/';
        return htmlspecialchars($base . $path . $frag);
    }

    private function norm(string $p): string
    {
        $out = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return implode('/', $out);
    }
}

// ── API reference: extracted from the PHP sources with PhpToken ──────────────

final class RTag
{
    public string $type = '';
    public string $name = '';
    public string $text = '';
}

final class RDoc
{
    public string $summary = '';
    public string $body = '';
    /** @var RTag[] */
    public array $params = [];
    /** @var RTag[] */
    public array $throws = [];
    /** @var RTag[] */
    public array $templates = [];
    public string $returnType = '';
    public string $returnText = '';
    /** @var string[] */
    public array $examples = [];
    /** @var string[] */
    public array $sees = [];
    public bool $internal = false;
    public bool $deprecated = false;
    public string $deprecatedText = '';
    public bool $present = false;
}

final class RParam
{
    public string $type = '';
    public string $name = '';
    public string $default = '';
    public bool $hasDefault = false;
    public bool $variadic = false;
    public bool $byRef = false;
    public string $promoted = '';
}

final class RMember
{
    public string $kind = '';
    public string $name = '';
    public string $visibility = 'public';
    public bool $static = false;
    public bool $final = false;
    public bool $abstract = false;
    public bool $readonly = false;
    public string $type = '';
    public string $value = '';
    public bool $hasValue = false;
    /** @var RParam[] */
    public array $params = [];
    public RDoc $doc;
    public int $line = 0;
}

final class RItem
{
    public string $kind = '';
    public string $name = '';
    public string $ns = '';
    public bool $final = false;
    public bool $abstract = false;
    public bool $readonly = false;
    public string $extends = '';
    /** @var string[] */
    public array $implements = [];
    public string $backing = '';
    public string $returns = '';
    /** @var RParam[] */
    public array $params = [];
    /** @var RMember[] */
    public array $members = [];
    public RDoc $doc;
    public string $file = '';
    public int $line = 0;
    public string $attrs = '';

    public function fqn(): string
    {
        return $this->ns === '' ? $this->name : $this->ns . '\\' . $this->name;
    }
}

final class RefParser
{
    /** @var PhpToken[] */
    private array $t = [];
    private int $n = 0;
    private int $i = 0;
    private string $ns = '';
    private string $file = '';
    /** @var RItem[] */
    public array $items = [];

    /** @return RItem[] */
    public function parse(string $src, string $file): array
    {
        $this->file = $file;
        $this->items = [];
        $this->t = [];
        foreach (PhpToken::tokenize($src) as $tk) {
            if ($tk->id === T_WHITESPACE || $tk->id === T_COMMENT || $tk->id === T_OPEN_TAG || $tk->id === T_CLOSE_TAG) {
                continue;
            }
            $this->t[] = $tk;
        }
        $this->n = count($this->t);
        $this->i = 0;
        $this->ns = '';
        $this->scope(false);
        return $this->items;
    }

    private function text(int $i): string
    {
        return $i < $this->n ? $this->t[$i]->text : '';
    }

    private function id(int $i): int
    {
        return $i < $this->n ? $this->t[$i]->id : 0;
    }

    private function skipBalanced(string $open, string $close): void
    {
        $d = 0;
        while ($this->i < $this->n) {
            $x = $this->t[$this->i]->text;
            if ($this->t[$this->i]->id === T_CURLY_OPEN || $this->t[$this->i]->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $x = '{';
            }
            $this->i++;
            if ($x === $open) {
                $d++;
            } elseif ($x === $close) {
                $d--;
                if ($d === 0) {
                    return;
                }
            }
        }
    }

    private function skipTo(string $stop): void
    {
        while ($this->i < $this->n && $this->t[$this->i]->text !== $stop) {
            if ($this->t[$this->i]->text === '{') {
                $this->skipBalanced('{', '}');
                continue;
            }
            $this->i++;
        }
        $this->i++;
    }

    private function attribute(): string
    {
        $s = $this->i;
        $this->skipBalanced('[', ']');
        $out = '';
        for ($k = $s; $k < $this->i; $k++) {
            $out .= $this->t[$k]->text;
        }
        return $out;
    }

    private function scope(bool $braced): void
    {
        $doc = '';
        $attrs = '';
        $final = false;
        $abstract = false;
        $readonly = false;
        while ($this->i < $this->n) {
            $tk = $this->t[$this->i];
            $id = $tk->id;
            if ($braced && $tk->text === '}') {
                $this->i++;
                return;
            }
            if ($id === T_DOC_COMMENT) {
                $doc = $tk->text;
                $this->i++;
                continue;
            }
            if ($id === T_ATTRIBUTE) {
                $attrs .= $this->attribute();
                continue;
            }
            if ($id === T_FINAL) {
                $final = true;
                $this->i++;
                continue;
            }
            if ($id === T_ABSTRACT) {
                $abstract = true;
                $this->i++;
                continue;
            }
            if ($id === T_READONLY) {
                $readonly = true;
                $this->i++;
                continue;
            }
            if ($id === T_NAMESPACE) {
                $this->i++;
                $name = '';
                while ($this->i < $this->n && $this->t[$this->i]->text !== ';' && $this->t[$this->i]->text !== '{') {
                    $name .= $this->t[$this->i]->text;
                    $this->i++;
                }
                if ($this->text($this->i) === '{') {
                    $this->i++;
                    $prev = $this->ns;
                    $this->ns = $name;
                    $this->scope(true);
                    $this->ns = $prev;
                } else {
                    $this->ns = $name;
                    $this->i++;
                }
                $doc = '';
                $attrs = '';
                continue;
            }
            if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
                $this->classLike($doc, $attrs, $final, $abstract, $readonly);
            } elseif ($id === T_FUNCTION && ($this->id($this->i + 1) === T_STRING || $this->text($this->i + 1) === '&')) {
                $this->func($doc, $attrs);
            } elseif ($tk->text === '{') {
                $this->skipBalanced('{', '}');
            } elseif ($tk->text === ';' || $id === T_USE || $id === T_CONST) {
                $this->skipTo(';');
            } else {
                $this->i++;
                continue;
            }
            $doc = '';
            $attrs = '';
            $final = false;
            $abstract = false;
            $readonly = false;
        }
    }

    private function names(): string
    {
        $s = '';
        while ($this->i < $this->n) {
            $id = $this->t[$this->i]->id;
            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_RELATIVE || $id === T_NS_SEPARATOR || $id === T_STATIC || $id === T_ARRAY) {
                $s .= $this->t[$this->i]->text;
                $this->i++;
            } else {
                break;
            }
        }
        return $s;
    }

    private function classLike(string $doc, string $attrs, bool $final, bool $abstract, bool $readonly): void
    {
        $it = new RItem();
        $kw = $this->t[$this->i]->id;
        $it->kind = $kw === T_CLASS ? 'class' : ($kw === T_INTERFACE ? 'interface' : ($kw === T_TRAIT ? 'trait' : 'enum'));
        $it->line = $this->t[$this->i]->line;
        $this->i++;
        $it->name = $this->text($this->i);
        $this->i++;
        $it->ns = $this->ns;
        $it->final = $final;
        $it->abstract = $abstract;
        $it->readonly = $readonly;
        $it->file = $this->file;
        $it->attrs = $attrs;
        $it->doc = $this->parseDoc($doc);
        if ($it->kind === 'enum' && $this->text($this->i) === ':') {
            $this->i++;
            $it->backing = $this->names();
        }
        while ($this->i < $this->n && $this->text($this->i) !== '{') {
            $id = $this->id($this->i);
            if ($id === T_EXTENDS) {
                $this->i++;
                $list = [];
                $list[] = $this->names();
                while ($this->text($this->i) === ',') {
                    $this->i++;
                    $list[] = $this->names();
                }
                if ($it->kind === 'interface') {
                    $it->implements = $list;
                } else {
                    $it->extends = $list[0];
                }
            } elseif ($id === T_IMPLEMENTS) {
                $this->i++;
                $it->implements[] = $this->names();
                while ($this->text($this->i) === ',') {
                    $this->i++;
                    $it->implements[] = $this->names();
                }
            } else {
                $this->i++;
            }
        }
        $this->i++;
        $this->members($it);
        $this->items[] = $it;
    }

    private function members(RItem $it): void
    {
        $doc = '';
        $vis = 'public';
        $static = false;
        $final = false;
        $abstract = false;
        $readonly = false;
        while ($this->i < $this->n) {
            $tk = $this->t[$this->i];
            $id = $tk->id;
            if ($tk->text === '}') {
                $this->i++;
                return;
            }
            if ($id === T_DOC_COMMENT) {
                $doc = $tk->text;
                $this->i++;
                continue;
            }
            if ($id === T_ATTRIBUTE) {
                $this->attribute();
                continue;
            }
            if ($id === T_PUBLIC || $id === T_PROTECTED || $id === T_PRIVATE) {
                $vis = $tk->text;
                $this->i++;
                continue;
            }
            if ($id === T_STATIC && $this->id($this->i + 1) !== T_DOUBLE_COLON) {
                $static = true;
                $this->i++;
                continue;
            }
            if ($id === T_FINAL) {
                $final = true;
                $this->i++;
                continue;
            }
            if ($id === T_ABSTRACT) {
                $abstract = true;
                $this->i++;
                continue;
            }
            if ($id === T_READONLY) {
                $readonly = true;
                $this->i++;
                continue;
            }
            $m = new RMember();
            $m->visibility = $vis;
            $m->static = $static;
            $m->final = $final;
            $m->abstract = $abstract;
            $m->readonly = $readonly;
            $m->line = $tk->line;
            $m->doc = $this->parseDoc($doc);
            if ($id === T_USE) {
                $this->skipTo(';');
            } elseif ($id === T_CONST) {
                $this->i++;
                $m->kind = 'const';
                $last = '';
                $typeToks = '';
                while ($this->i < $this->n && $this->text($this->i) !== '=' && $this->text($this->i) !== ';') {
                    $typeToks .= $last === '' ? '' : $last . ' ';
                    $last = $this->text($this->i);
                    $this->i++;
                }
                $m->name = $last;
                $m->type = trim($typeToks);
                if ($this->text($this->i) === '=') {
                    $this->i++;
                    $m->value = $this->exprUntil([';', ',']);
                    $m->hasValue = true;
                }
                $this->skipTo(';');
                $it->members[] = $m;
            } elseif ($id === T_CASE) {
                $this->i++;
                $m->kind = 'case';
                $m->name = $this->text($this->i);
                $this->i++;
                if ($this->text($this->i) === '=') {
                    $this->i++;
                    $m->value = $this->exprUntil([';']);
                    $m->hasValue = true;
                }
                $this->skipTo(';');
                $it->members[] = $m;
            } elseif ($id === T_FUNCTION) {
                $this->i++;
                if ($this->text($this->i) === '&') {
                    $this->i++;
                }
                $m->kind = 'method';
                $m->name = $this->text($this->i);
                $this->i++;
                $m->params = $this->params();
                $m->type = $this->returnType();
                if ($this->text($this->i) === '{') {
                    $this->skipBalanced('{', '}');
                } else {
                    $this->skipTo(';');
                }
                $it->members[] = $m;
            } else {
                $type = '';
                while ($this->i < $this->n && $this->id($this->i) !== T_VARIABLE && $this->text($this->i) !== ';' && $this->text($this->i) !== '}') {
                    $type .= $this->text($this->i);
                    $this->i++;
                }
                if ($this->id($this->i) === T_VARIABLE) {
                    $m->kind = 'prop';
                    $m->name = substr($this->text($this->i), 1);
                    $m->type = $type;
                    $this->i++;
                    if ($this->text($this->i) === '=') {
                        $this->i++;
                        $m->value = $this->exprUntil([';', ',']);
                        $m->hasValue = true;
                    }
                    $this->skipTo(';');
                    $it->members[] = $m;
                } elseif ($this->text($this->i) === ';') {
                    $this->i++;
                }
            }
            $doc = '';
            $vis = 'public';
            $static = false;
            $final = false;
            $abstract = false;
            $readonly = false;
        }
    }

    /** @param string[] $stops */
    private function exprUntil(array $stops): string
    {
        $d = 0;
        $s = '';
        while ($this->i < $this->n) {
            $x = $this->t[$this->i]->text;
            if ($d === 0 && in_array($x, $stops, true)) {
                break;
            }
            if ($x === '(' || $x === '[' || $x === '{') {
                $d++;
            } elseif ($x === ')' || $x === ']' || $x === '}') {
                if ($d === 0) {
                    break;
                }
                $d--;
            }
            $s .= $x;
            if ($this->t[$this->i]->id === T_AS || $this->t[$this->i]->id === T_INSTANCEOF || $x === ',') {
                $s .= ' ';
            }
            $this->i++;
        }
        return trim($s);
    }

    private function returnType(): string
    {
        if ($this->text($this->i) !== ':') {
            return '';
        }
        $this->i++;
        $s = '';
        while ($this->i < $this->n && $this->text($this->i) !== '{' && $this->text($this->i) !== ';') {
            $s .= $this->text($this->i);
            $this->i++;
        }
        return $s;
    }

    /** @return RParam[] */
    private function params(): array
    {
        $out = [];
        if ($this->text($this->i) !== '(') {
            return $out;
        }
        $this->i++;
        while ($this->i < $this->n && $this->text($this->i) !== ')') {
            $p = new RParam();
            while ($this->i < $this->n) {
                $tk = $this->t[$this->i];
                if ($tk->id === T_ATTRIBUTE) {
                    $this->attribute();
                    continue;
                }
                if ($tk->id === T_PUBLIC || $tk->id === T_PROTECTED || $tk->id === T_PRIVATE) {
                    $p->promoted = $tk->text;
                    $this->i++;
                    continue;
                }
                if ($tk->id === T_READONLY) {
                    $this->i++;
                    continue;
                }
                if ($tk->text === '&') {
                    $p->byRef = true;
                    $this->i++;
                    continue;
                }
                if ($tk->id === T_ELLIPSIS) {
                    $p->variadic = true;
                    $this->i++;
                    continue;
                }
                if ($tk->id === T_VARIABLE) {
                    $p->name = substr($tk->text, 1);
                    $this->i++;
                    break;
                }
                $p->type .= $tk->text;
                $this->i++;
            }
            if ($this->text($this->i) === '=') {
                $this->i++;
                $p->default = $this->exprUntil([',']);
                $p->hasDefault = true;
            }
            $out[] = $p;
            if ($this->text($this->i) === ',') {
                $this->i++;
            }
        }
        $this->i++;
        return $out;
    }

    private function func(string $doc, string $attrs): void
    {
        $it = new RItem();
        $it->kind = 'function';
        $it->line = $this->t[$this->i]->line;
        $this->i++;
        if ($this->text($this->i) === '&') {
            $this->i++;
        }
        $it->name = $this->text($this->i);
        $this->i++;
        $it->ns = $this->ns;
        $it->file = $this->file;
        $it->attrs = $attrs;
        $it->doc = $this->parseDoc($doc);
        $it->params = $this->params();
        $it->returns = $this->returnType();
        if ($this->text($this->i) === '{') {
            $this->skipBalanced('{', '}');
        } else {
            $this->skipTo(';');
        }
        $this->items[] = $it;
    }

    public function parseDoc(string $raw): RDoc
    {
        $d = new RDoc();
        if ($raw === '') {
            return $d;
        }
        $d->present = true;
        $raw = str_replace("\r\n", "\n", $raw);
        $raw = preg_replace('/^\s*\/\*\*+/', '', $raw);
        $raw = preg_replace('/\*+\/\s*$/', '', $raw);
        $lines = [];
        foreach (explode("\n", $raw) as $l) {
            $lines[] = preg_replace('/^\s*\* ?/', '', $l);
        }
        $desc = [];
        /** @var string[] $tags */
        $tags = [];
        $cur = -1;
        $fence = false;
        foreach ($lines as $l) {
            if (preg_match('/^\s*(```|~~~)/', $l)) {
                $fence = !$fence;
            }
            if (!$fence && preg_match('/^\s*@(\w+)\s*(.*)$/', $l, $m)) {
                $tags[] = $m[1] . ' ' . $m[2];
                $cur = count($tags) - 1;
                continue;
            }
            if ($cur >= 0) {
                $tags[$cur] .= "\n" . $l;
            } else {
                $desc[] = $l;
            }
        }
        $text = trim(implode("\n", $desc));
        $paras = preg_split('/\n\s*\n/', $text, 2);
        $d->summary = trim(preg_replace('/\s+/', ' ', $paras[0]));
        $d->body = isset($paras[1]) ? trim($paras[1]) : '';
        foreach ($tags as $tg) {
            $sp = strpos($tg, ' ');
            $name = substr($tg, 0, $sp);
            $rest = trim(substr($tg, $sp + 1));
            if ($name === 'param') {
                $r = new RTag();
                if (preg_match('/^(.*?)\s*(?:\.\.\.)?\$(\w+)\s*(.*)$/s', $rest, $m)) {
                    $r->type = trim($m[1]);
                    $r->name = $m[2];
                    $r->text = trim($m[3]);
                }
                $d->params[] = $r;
            } elseif ($name === 'return') {
                $r = $this->typeAndText($rest);
                $d->returnType = $r->type;
                $d->returnText = $r->text;
            } elseif ($name === 'throws') {
                $d->throws[] = $this->typeAndText($rest);
            } elseif ($name === 'template') {
                $r = $this->typeAndText($rest);
                $r->name = $r->type;
                $r->type = '';
                $d->templates[] = $r;
            } elseif ($name === 'example') {
                $d->examples[] = $rest;
            } elseif ($name === 'see') {
                $d->sees[] = $rest;
            } elseif ($name === 'internal') {
                $d->internal = true;
            } elseif ($name === 'deprecated') {
                $d->deprecated = true;
                $d->deprecatedText = $rest;
            }
        }
        return $d;
    }

    private function typeAndText(string $s): RTag
    {
        $r = new RTag();
        $depth = 0;
        $n = strlen($s);
        $k = 0;
        while ($k < $n) {
            $c = $s[$k];
            if ($c === '<' || $c === '{' || $c === '(') {
                $depth++;
            } elseif ($c === '>' || $c === '}' || $c === ')') {
                $depth--;
            } elseif ($depth === 0 && ($c === ' ' || $c === "\n" || $c === "\t")) {
                break;
            }
            $k++;
        }
        $r->type = substr($s, 0, $k);
        $r->text = trim(substr($s, $k));
        return $r;
    }
}

const REF_SOURCES = [
    'prelude/ds.php', 'prelude/ds_map.php', 'prelude/async.php',
    'prelude/http.php', 'prelude/websocket.php', 'prelude/buffer.php', 'prelude/pcntl.php', 'prelude/io_poll.php', 'prelude/sapi.php',
    'src/Ffi/CType.php', 'src/Ffi/Library.php', 'src/Ffi/Ownership.php', 'src/Ffi/Ptr.php', 'src/Ffi/Symbol.php', 'src/Ffi/Variadic.php', 'src/Ffi/Weak.php',
];
const REF_NAMESPACES = ['Manticore\\Ds', 'Async', 'Http', 'Http\\WebSocket', 'Buffer', 'Process', 'Io\\Poll', 'Manticore\\Sapi', 'Ffi'];

function ref_public_member(RMember $m): bool
{
    if ($m->visibility !== 'public' || $m->doc->internal) {
        return false;
    }
    if (substr($m->name, 0, 2) === '__') {
        return in_array($m->name, ['__construct', '__toString', '__invoke', '__get', '__set', '__isset', '__unset', '__call', '__callStatic', '__clone'], true);
    }
    return true;
}

/**
 * @param string[] $files
 * @return RItem[] public items of the documented namespaces, in source order
 */
function ref_load(string $repo, array $files): array
{
    $out = [];
    foreach ($files as $f) {
        $p = new RefParser();
        foreach ($p->parse(file_get_contents($repo . '/' . $f), $f) as $it) {
            if (!in_array($it->ns, REF_NAMESPACES, true) || $it->doc->internal || substr($it->name, 0, 2) === '__') {
                continue;
            }
            $keep = [];
            foreach ($it->members as $m) {
                if (ref_public_member($m)) {
                    $keep[] = $m;
                }
            }
            $it->members = $keep;
            $out[] = $it;
        }
    }
    return $out;
}

/** @param RItem[] $items */
function ref_lint(array $items): int
{
    $bad = 0;
    foreach ($items as $it) {
        $where = $it->file . ':' . $it->line . ' ' . $it->fqn();
        if ($it->doc->summary === '') {
            echo "$where: no summary\n";
            $bad++;
        }
        foreach ($it->doc->examples as $ex) {
            $bad += ref_lint_example($where, trim($ex));
        }
        if ($it->kind === 'function') {
            $bad += ref_lint_callable($where, $it->params, $it->returns, $it->doc);
        }
        foreach ($it->members as $m) {
            $w = $it->file . ':' . $m->line . ' ' . $it->fqn() . '::' . $m->name;
            if ($m->doc->summary === '') {
                echo "$w: no summary\n";
                $bad++;
            }
            foreach ($m->doc->examples as $ex) {
                $bad += ref_lint_example($w, trim($ex));
            }
            if ($m->kind === 'method') {
                $bad += ref_lint_callable($w, $m->params, $m->type, $m->doc);
            }
        }
    }
    return $bad;
}

function ref_lint_example(string $where, string $name): int
{
    if (is_file(REPO_DIR . '/tests/aot/cases/' . $name . '.php') && is_file(REPO_DIR . '/tests/aot/expected/' . $name . '.out')) {
        return 0;
    }
    echo "$where: example $name has no tests/aot/cases/$name.php + expected/$name.out\n";
    return 1;
}

/** @param RParam[] $params */
function ref_lint_callable(string $where, array $params, string $ret, RDoc $doc): int
{
    $bad = 0;
    $have = [];
    foreach ($doc->params as $t) {
        $have[$t->name] = true;
    }
    foreach ($params as $p) {
        if (!isset($have[$p->name])) {
            echo "$where: no @param \$" . $p->name . "\n";
            $bad++;
        }
    }
    if ($ret !== '' && $ret !== 'void' && $ret !== 'never' && $doc->returnText === '') {
        echo "$where: no @return text\n";
        $bad++;
    }
    return $bad;
}

final class RefCtx
{
    /** @var array<string,bool> */
    public static array $docs = [];
}

const REF_BUILTIN = ['int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed', 'void', 'null', 'never', 'self', 'static', 'parent', 'true', 'false', 'list', 'resource', 'numeric', 'scalar', 'positive', 'non', 'empty', 'class', 'key', 'of', 'value'];

function ref_dir(RItem $it): string
{
    return 'reference/' . str_replace('\\', '/', $it->ns);
}

function ref_url(RItem $it): string
{
    return ref_dir($it) . '/' . ($it->kind === 'function' ? 'fn.' : '') . $it->name . '.html';
}

/** @param array<string,string> $index */
function ref_type(string $t, string $ns, array $index, string $root): string
{
    if ($t === '') {
        return '';
    }
    $parts = preg_split('/(\\\\?[A-Za-z_][\w\\\\]*)/', $t, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $k => $w) {
        if ($k % 2 === 0) {
            $out .= htmlspecialchars($w);
            continue;
        }
        if (in_array(strtolower($w), REF_BUILTIN, true)) {
            $out .= '<span class="pt">' . $w . '</span>';
            continue;
        }
        $fq = strtolower($w[0] === '\\' ? substr($w, 1) : ($ns !== '' ? $ns . '\\' . $w : $w));
        if (isset($index[$fq])) {
            $out .= '<a class="t" href="' . $root . $index[$fq] . '">' . htmlspecialchars($w) . '</a>';
        } else {
            $out .= '<span class="t">' . htmlspecialchars($w) . '</span>';
        }
    }
    return $out;
}

function ref_md(string $text, string $root): string
{
    if (trim($text) === '') {
        return '';
    }
    $md = new Md('prelude', RefCtx::$docs, $root . 'docs/');
    return $md->render($text);
}

function ref_inline(string $text): string
{
    $md = new Md('prelude', RefCtx::$docs, '');
    return preg_replace('~^<p>|</p>\s*$~', '', trim($md->render($text)));
}

function ref_short(string $s): string
{
    if (preg_match('/^(.+?[.!?])(\s|$)/s', $s, $m) && strlen($m[1]) <= 200) {
        return $m[1];
    }
    return strlen($s) > 160 ? rtrim(substr($s, 0, 160)) . '…' : $s;
}

/**
 * @param RParam[] $params
 * @param array<string,string> $index
 */
function ref_params_sig(array $params, string $ns, array $index, string $root): string
{
    $parts = [];
    foreach ($params as $p) {
        $s = '';
        if ($p->promoted !== '') {
            $s .= $p->promoted . ' ';
        }
        if ($p->type !== '') {
            $s .= ref_type($p->type, $ns, $index, $root) . ' ';
        }
        $s .= ($p->byRef ? '&amp;' : '') . ($p->variadic ? '...' : '') . '$' . $p->name;
        if ($p->hasDefault) {
            $s .= ' = ' . htmlspecialchars($p->default);
        }
        $parts[] = $s;
    }
    $flat = implode(', ', $parts);
    if (count($parts) > 2 && strlen(strip_tags($flat)) > 70) {
        return "\n    " . implode(",\n    ", $parts) . "\n";
    }
    return $flat;
}

/**
 * @param RParam[] $params
 * @param array<string,string> $index
 */
function ref_callable_sig(string $mods, string $name, array $params, string $ret, string $ns, array $index, string $root): string
{
    $s = $mods . 'function ' . htmlspecialchars($name) . '(' . ref_params_sig($params, $ns, $index, $root) . ')';
    if ($ret !== '') {
        $s .= ': ' . ref_type($ret, $ns, $index, $root);
    }
    return $s;
}

/**
 * @param RParam[] $params
 * @param array<string,string> $index
 */
function ref_doc_sections(RDoc $d, array $params, string $ret, string $ns, array $index, string $root, string $repo): string
{
    $h = '';
    if ($d->deprecated) {
        $h .= '<p class="dep"><b>Deprecated.</b> ' . ref_inline($d->deprecatedText) . "</p>\n";
    }
    $h .= ref_md($d->body, $root);
    $tdoc = false;
    foreach ($d->templates as $t) {
        if ($t->text !== '') {
            $tdoc = true;
        }
    }
    if ($tdoc) {
        $h .= "<h4>Type parameters</h4>\n<dl class=\"params\">\n";
        foreach ($d->templates as $t) {
            $h .= '<dt><code>' . htmlspecialchars($t->name) . '</code></dt><dd>' . ref_inline($t->text) . "</dd>\n";
        }
        $h .= "</dl>\n";
    }
    if (count($params) > 0) {
        $docp = [];
        foreach ($d->params as $t) {
            $docp[$t->name] = $t;
        }
        $h .= "<h4>Parameters</h4>\n<dl class=\"params\">\n";
        foreach ($params as $p) {
            $type = $p->type;
            $text = '';
            if (isset($docp[$p->name])) {
                $text = $docp[$p->name]->text;
                if ($docp[$p->name]->type !== '') {
                    $type = $docp[$p->name]->type;
                }
            }
            $def = $p->hasDefault ? ' <span class="def">= ' . htmlspecialchars($p->default) . '</span>' : '';
            $h .= '<dt><code>' . ($p->variadic ? '...' : '') . '$' . $p->name . '</code> ' . ref_type($type, $ns, $index, $root) . $def . '</dt><dd>' . ref_inline($text) . "</dd>\n";
        }
        $h .= "</dl>\n";
    }
    if ($ret !== '' && $ret !== 'void' && $ret !== 'never') {
        $rt = $d->returnType !== '' ? $d->returnType : $ret;
        $h .= "<h4>Returns</h4>\n<p>" . ref_type($rt, $ns, $index, $root) . ($d->returnText !== '' ? ' — ' . ref_inline($d->returnText) : '') . "</p>\n";
    }
    if (count($d->throws) > 0) {
        $h .= "<h4>Throws</h4>\n<dl class=\"params\">\n";
        foreach ($d->throws as $t) {
            $h .= '<dt>' . ref_type($t->type, $ns, $index, $root) . '</dt><dd>' . ref_inline($t->text) . "</dd>\n";
        }
        $h .= "</dl>\n";
    }
    foreach ($d->examples as $name) {
        $h .= ref_example(trim($name), $repo);
    }
    if (count($d->sees) > 0) {
        $h .= '<p class="see"><b>See also:</b> ';
        $links = [];
        foreach ($d->sees as $s) {
            $links[] = ref_see($s, $ns, $index, $root);
        }
        $h .= implode(', ', $links) . "</p>\n";
    }
    return $h;
}

/** @param array<string,string> $index */
function ref_see(string $s, string $ns, array $index, string $root): string
{
    $s = trim($s);
    if (preg_match('~^https?://~', $s)) {
        return '<a href="' . htmlspecialchars($s) . '">' . htmlspecialchars($s) . '</a>';
    }
    $key = strtolower(ltrim($s, '\\'));
    $key2 = strtolower($ns . '\\' . ltrim($s, '\\'));
    if (isset($index[$key2])) {
        return '<a href="' . $root . $index[$key2] . '"><code>' . htmlspecialchars($s) . '</code></a>';
    }
    if (isset($index[$key])) {
        return '<a href="' . $root . $index[$key] . '"><code>' . htmlspecialchars($s) . '</code></a>';
    }
    return '<code>' . htmlspecialchars($s) . '</code>';
}

function ref_example(string $name, string $repo): string
{
    $php = $repo . '/tests/aot/cases/' . $name . '.php';
    $out = $repo . '/tests/aot/expected/' . $name . '.out';
    if (!is_file($php)) {
        fwrite(STDERR, "ref: missing example $name\n");
        return '';
    }
    $code = preg_replace('/^<\?php\s*\n/', '', file_get_contents($php));
    $h = "<h4>Example</h4>\n<div class=\"code\"><pre><code class=\"lang-php\">" . htmlspecialchars(rtrim($code)) . "</code></pre><button class=\"copy\">Copy</button></div>\n";
    if (is_file($out)) {
        $h .= "<p class=\"outl\">Output</p>\n<div class=\"code\"><pre><code>" . htmlspecialchars(rtrim(file_get_contents($out))) . "</code></pre></div>\n";
    }
    return $h;
}

/** @param RItem[] $items */
function ref_sidebar(array $items, string $root, ?RItem $cur, string $curNs): string
{
    $h = "<h4>Reference</h4>\n<ul>\n";
    foreach (REF_NAMESPACES as $ns) {
        $dir = str_replace('\\', '/', $ns);
        $on = $ns === $curNs;
        $h .= '<li><a' . ($on && $cur === null ? ' class="cur"' : '') . ' href="' . $root . 'reference/' . $dir . '/">' . htmlspecialchars($ns) . "</a>\n";
        if ($on) {
            $by = [];
            foreach (['interface', 'class', 'trait', 'enum', 'function'] as $k) {
                $by[$k] = [];
            }
            foreach ($items as $it) {
                if ($it->ns === $ns) {
                    $by[$it->kind][] = $it;
                }
            }
            $h .= "<ul class=\"sub\">\n";
            foreach ($by as $k => $list) {
                if (count($list) === 0) {
                    continue;
                }
                usort($list, function (RItem $a, RItem $b): int {
                    return strcmp(strtolower($a->name), strtolower($b->name));
                });
                $h .= '<li class="grp">' . ($k === 'class' ? 'Classes' : ($k === 'interface' ? 'Interfaces' : ($k === 'trait' ? 'Traits' : ($k === 'enum' ? 'Enums' : 'Functions')))) . "</li>\n";
                foreach ($list as $it) {
                    $c = ($cur !== null && $cur->kind === $it->kind && $cur->name === $it->name && $cur->ns === $it->ns) ? ' class="cur"' : '';
                    $h .= '<li><a' . $c . ' href="' . $root . ref_url($it) . '">' . htmlspecialchars($it->name) . ($k === 'function' ? '()' : '') . "</a></li>\n";
                }
            }
            $h .= "</ul>\n";
        }
        $h .= "</li>\n";
    }
    return $h . "</ul>\n";
}

/**
 * @param RItem[] $items
 * @param array<string,string> $index
 * @param array<int,array<int,string>> $search
 */
function ref_render(array $items, string $out, array $index, string $repo, array &$search): void
{
    $kindName = ['class' => 'class', 'interface' => 'interface', 'trait' => 'trait', 'enum' => 'enum', 'function' => 'function'];
    foreach ($items as $it) {
        $depth = substr_count(ref_url($it), '/');
        $root = str_repeat('../', $depth);
        $ns = $it->ns;
        $src = REPO . '/blob/main/' . $it->file . '#L' . $it->line;
        $badges = '';
        if ($it->final) {
            $badges .= '<span class="badge">final</span>';
        }
        if ($it->abstract) {
            $badges .= '<span class="badge">abstract</span>';
        }
        if ($it->readonly) {
            $badges .= '<span class="badge">readonly</span>';
        }
        $badges .= '<span class="badge k">' . $kindName[$it->kind] . '</span>';
        $title = $it->name . ($it->kind === 'function' ? '()' : '');
        $body = '<p class="crumbs"><a href="' . $root . 'reference/">Reference</a> / <a href="' . $root . ref_dir($it) . '/">' . htmlspecialchars($ns) . "</a></p>\n";
        $body .= '<h1>' . htmlspecialchars($title) . '</h1><div class="badges">' . $badges . "</div>\n";
        if ($it->kind === 'function') {
            $sig = ref_callable_sig('', $it->name, $it->params, $it->returns, $ns, $index, $root);
        } else {
            $sig = ($it->final ? 'final ' : '') . ($it->abstract ? 'abstract ' : '') . ($it->readonly ? 'readonly ' : '') . $it->kind . ' ' . htmlspecialchars($it->name);
            if (count($it->doc->templates) > 0) {
                $tn = [];
                foreach ($it->doc->templates as $t) {
                    $tn[] = $t->name;
                }
                $sig .= '&lt;' . htmlspecialchars(implode(', ', $tn)) . '&gt;';
            }
            if ($it->backing !== '') {
                $sig .= ': ' . $it->backing;
            }
            if ($it->extends !== '') {
                $sig .= ' extends ' . ref_type($it->extends, $ns, $index, $root);
            }
            if (count($it->implements) > 0) {
                $l = [];
                foreach ($it->implements as $x) {
                    $l[] = ref_type($x, $ns, $index, $root);
                }
                $sig .= ($it->kind === 'interface' ? ' extends ' : ' implements ') . implode(', ', $l);
            }
        }
        $body .= '<pre class="sig"><code>' . $sig . "</code></pre>\n";
        $body .= '<p class="lead">' . ref_inline($it->doc->summary) . "</p>\n";
        $body .= ref_doc_sections($it->doc, $it->kind === 'function' ? $it->params : [], $it->returns, $ns, $index, $root, $repo);
        $groups = ['const' => 'Constants', 'case' => 'Cases', 'prop' => 'Properties', 'method' => 'Methods'];
        foreach ($groups as $kind => $label) {
            $list = [];
            foreach ($it->members as $m) {
                if ($m->kind === $kind) {
                    $list[] = $m;
                }
            }
            if (count($list) === 0) {
                continue;
            }
            $body .= '<h2 id="' . $kind . 's">' . $label . "</h2>\n<div class=\"tw\"><table class=\"mt\"><tbody>\n";
            foreach ($list as $m) {
                $link = $kind === 'method' || (($kind === 'prop') && $m->doc->summary !== '');
                $nm = '<code>' . htmlspecialchars($m->name) . ($kind === 'method' ? '()' : '') . '</code>';
                $mid = $kind === 'const' || $kind === 'case' ? '<td><code>' . htmlspecialchars($m->value) . '</code></td>' : ($kind === 'prop' ? '<td>' . ref_type($m->type, $ns, $index, $root) . '</td>' : '');
                $body .= '<tr><td>' . ($link ? '<a href="#m-' . $m->name . '">' . $nm . '</a>' : $nm) . '</td>' . $mid . '<td>' . ref_inline(ref_short($m->doc->summary)) . "</td></tr>\n";
            }
            $body .= "</tbody></table></div>\n";
            foreach ($list as $m) {
                if ($kind === 'const' || $kind === 'case' || ($kind === 'prop' && $m->doc->summary === '')) {
                    continue;
                }
                $mods = ($m->abstract ? 'abstract ' : '') . ($m->final ? 'final ' : '') . ($m->static ? 'static ' : '');
                if ($kind === 'method') {
                    $msig = ref_callable_sig($mods, $m->name, $m->params, $m->type, $ns, $index, $root);
                } elseif ($kind === 'prop') {
                    $msig = $mods . ($m->readonly ? 'readonly ' : '') . ($m->type !== '' ? ref_type($m->type, $ns, $index, $root) . ' ' : '') . '$' . htmlspecialchars($m->name) . ($m->hasValue ? ' = ' . htmlspecialchars($m->value) : '');
                } elseif ($kind === 'const') {
                    $msig = 'const ' . ($m->type !== '' ? ref_type($m->type, $ns, $index, $root) . ' ' : '') . htmlspecialchars($m->name) . ($m->hasValue ? ' = ' . htmlspecialchars($m->value) : '');
                } else {
                    $msig = 'case ' . htmlspecialchars($m->name) . ($m->hasValue ? ' = ' . htmlspecialchars($m->value) : '');
                }
                $msrc = REPO . '/blob/main/' . $it->file . '#L' . $m->line;
                $body .= '<section class="member" id="m-' . $m->name . '"><h3><code>' . htmlspecialchars($m->name) . ($kind === 'method' ? '()' : '') . '</code><a class="anchor" href="#m-' . $m->name . '">#</a><a class="srcl" href="' . $msrc . "\">source</a></h3>\n";
                $body .= '<pre class="sig"><code>' . $msig . "</code></pre>\n";
                if ($m->doc->summary !== '') {
                    $body .= '<p>' . ref_inline($m->doc->summary) . "</p>\n";
                }
                $body .= ref_doc_sections($m->doc, $kind === 'method' ? $m->params : [], $kind === 'method' ? $m->type : '', $ns, $index, $root, $repo);
                $body .= "</section>\n";
                $search[] = [$it->name . ($kind === 'method' ? '::' . $m->name . '()' : '::' . $m->name), $it->fqn(), '../' . ref_url($it) . '#m-' . $m->name, ''];
            }
        }
        $body .= '<p class="edit"><a href="' . $src . "\">View source</a></p>\n";
        $side = ref_sidebar($items, $root, $it, $ns);
        $dest = $out . '/' . ref_url($it);
        @mkdir(dirname($dest), 0777, true);
        file_put_contents($dest, page($title, $body, $root, $side, $side));
        $search[] = [$it->name . ($it->kind === 'function' ? '()' : ''), $it->ns, '../' . ref_url($it), $it->doc->summary];
    }

    foreach (REF_NAMESPACES as $ns) {
        $dir = str_replace('\\', '/', $ns);
        $root = str_repeat('../', substr_count($dir, '/') + 2);
        $body = '<p class="crumbs"><a href="' . $root . 'reference/">Reference</a></p><h1>' . htmlspecialchars($ns) . "</h1>\n";
        foreach (['interface' => 'Interfaces', 'class' => 'Classes', 'trait' => 'Traits', 'enum' => 'Enums', 'function' => 'Functions'] as $k => $label) {
            $list = [];
            foreach ($items as $it) {
                if ($it->ns === $ns && $it->kind === $k) {
                    $list[] = $it;
                }
            }
            if (count($list) === 0) {
                continue;
            }
            usort($list, function (RItem $a, RItem $b): int {
                return strcmp(strtolower($a->name), strtolower($b->name));
            });
            $body .= '<h2>' . $label . "</h2>\n<div class=\"tw\"><table class=\"mt\"><tbody>\n";
            foreach ($list as $it) {
                $body .= '<tr><td><a href="' . $root . ref_url($it) . '"><code>' . htmlspecialchars($it->name) . ($k === 'function' ? '()' : '') . '</code></a></td><td>' . ref_inline(ref_short($it->doc->summary)) . "</td></tr>\n";
            }
            $body .= "</tbody></table></div>\n";
        }
        $side = ref_sidebar($items, $root, null, $ns);
        @mkdir($out . '/reference/' . $dir, 0777, true);
        file_put_contents($out . '/reference/' . $dir . '/index.html', page($ns, $body, $root, $side, $side));
    }

    $body = "<h1>API reference</h1>\n<p class=\"lead\">Classes and functions of the Manticore-only API, generated from the sources.</p>\n<div class=\"cards\">\n";
    foreach (REF_NAMESPACES as $ns) {
        $n = 0;
        foreach ($items as $it) {
            if ($it->ns === $ns) {
                $n++;
            }
        }
        $body .= '<a class="card" href="' . str_replace('\\', '/', $ns) . '/"><b>' . htmlspecialchars($ns) . '</b><span>' . $n . " items</span></a>\n";
    }
    $body .= "</div>\n<p>Functions that mirror PHP's own are documented at <a href=\"https://www.php.net/manual/\">php.net</a>; see <a href=\"../docs/builtins.html\">builtin coverage</a> for what is implemented.</p>\n";
    $side = ref_sidebar($items, '../', null, '');
    file_put_contents($out . '/reference/index.html', page('API reference', $body, '../', $side, $side));
}

function page(string $title, string $body, string $root, string $side, string $mside): string
{
    $t = htmlspecialchars($title);
    $search = "<input class=\"sq\" type=\"search\" placeholder=\"Search docs (press /)\" aria-label=\"Search docs\" data-src=\"{$root}search.json\" data-base=\"{$root}docs/\"><div class=\"sr\"></div>";
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>$t · Manticore</title>
<script>try{var t=localStorage.getItem("theme");if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
<link rel="icon" href="{$root}favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="{$root}style.css">
</head>
<body>
<nav><div class="w wide">
  <b><a class="brand" href="{$root}"><i class="logo"></i>Manticore</a></b>
  <a href="{$root}docs/">Docs</a>
  <a href="{$root}reference/">Reference</a>
  <a href="https://github.com/manticorephp/compiler/releases">Releases</a>
  <a href="https://github.com/manticorephp/compiler">GitHub</a>
  <button id="theme" aria-label="Toggle theme" title="Theme: auto">◐</button>
</div></nav>
<div class="docs">
<aside class="side">$search<div class="sl">$side</div></aside>
<main class="prose">
<details class="mside"><summary>Menu</summary><div class="side">$search<div class="sl">$mside</div></div></details>
$body
</main>
</div>
<script defer src="{$root}vendor/highlight.min.js"></script>
<script defer src="{$root}vendor/llvm.min.js"></script>
<script defer src="{$root}app.js"></script>
</body>
</html>
HTML;
}

function label(string $path, string $title): string
{
    $names = [
        "docs/install.md" => "Install",
        "docs/cli.md" => "CLI reference",
        "docs/superset.md" => "The superset",
        "docs/async.md" => "Async",
        "docs/modules.md" => "Modules",
        "docs/ds.md" => "Typed arrays (Ds)",
        "docs/http.md" => "HTTP server",
        "docs/websocket.md" => "WebSocket",
        "docs/attributes.md" => "Attributes",
        "docs/memory.md" => "Memory model",
        "docs/pdo.md" => "PDO",
        "docs/curl.md" => "curl",
    ];
    if (isset($names[$path])) {
        return $names[$path];
    }
    $p = strpos($title, " — ");
    return $p === false ? $title : substr($title, 0, $p);
}

/**
 * @param array<string,string> $titles path => title
 * @param array<string,string[]> $groups group => paths
 */
function sidebar(array $groups, array $titles, string $root, string $cur): string
{
    $h = '';
    foreach ($groups as $g => $paths) {
        $h .= '<h4>' . htmlspecialchars($g) . "</h4>\n<ul>\n";
        foreach ($paths as $p) {
            $href = $root . substr($p, 5, -3) . '.html';
            $c = $p === $cur ? ' class="cur"' : '';
            $h .= "<li><a$c href=\"$href\">" . htmlspecialchars(label($p, $titles[$p])) . "</a></li>\n";
        }
        $h .= "</ul>\n";
    }
    return $h;
}

function copy_tree(string $from, string $to, string $skip): void
{
    @mkdir($to, 0777, true);
    foreach (scandir($from) as $f) {
        if ($f === '.' || $f === '..' || $f === $skip) {
            continue;
        }
        if (is_dir("$from/$f")) {
            copy_tree("$from/$f", "$to/$f", '');
        } else {
            copy("$from/$f", "$to/$f");
        }
    }
}

define('REPO_DIR', dirname(__DIR__));
if (($argv[1] ?? '') === '--lint-ref') {
    $items = ref_load(REPO_DIR, REF_SOURCES);
    $bad = ref_lint($items);
    echo count($items), " items, $bad problems\n";
    exit($bad > 0 ? 1 : 0);
}
$out = $argv[1] ?? REPO_DIR . '/_site';
if (is_dir($out)) {
    foreach (['docs'] as $d) {
        // stale pages from a previous build
        if (is_dir("$out/$d")) {
            foreach (glob("$out/$d/*.html") as $f) {
                unlink($f);
            }
            foreach (glob("$out/$d/design/*.html") as $f) {
                unlink($f);
            }
        }
    }
}
copy_tree(__DIR__, $out, 'build.php');

$ver = trim(file_get_contents(REPO_DIR . '/COMPILER_VERSION'));
if (strpos($ver, 'dev') !== false) {
    $ver = trim(file_get_contents(REPO_DIR . '/BOOTSTRAP_VERSION'));
}
$index = str_replace('{{version}}', $ver, file_get_contents(__DIR__ . '/index.html'));
file_put_contents("$out/index.html", $index);

// Sources: tracked guides only. docs/status, docs/audit, docs/superpowers are working notes.
$files = [];
foreach (glob(REPO_DIR . '/docs/*.md') as $f) {
    $files[] = 'docs/' . basename($f);
}
$design = [];
foreach (glob(REPO_DIR . '/docs/design/*.md') as $f) {
    $design[] = 'docs/design/' . basename($f);
}
sort($files);
sort($design);
$all = array_merge($files, $design);
$set = [];
foreach ($all as $p) {
    $set[$p] = true;
}

$titles = [];
$excerpts = [];
// Every sidebar needs every title, so render once up front; pages are small.
foreach ($all as $p) {
    $md = new Md(dirname($p), $set, str_repeat('../', substr_count($p, '/') - 1));
    $md->render(file_get_contents(REPO_DIR . '/' . $p));
    $titles[$p] = $md->title !== '' ? $md->title : basename($p, '.md');
    $excerpts[$p] = $md->excerpt;
}

$order = ['install', 'cli', 'superset', 'async', 'modules', 'ffi', 'attributes', 'generics', 'memory', 'ds', 'http', 'websocket', 'pdo', 'curl'];
$guides = [];
$ref = [];
foreach ($order as $o) {
    if (isset($set["docs/$o.md"])) {
        $guides[] = "docs/$o.md";
    }
}
foreach ($files as $p) {
    if (!in_array($p, $guides, true)) {
        $ref[] = $p;
    }
}
$search = [];
$groups = ['Guides' => $guides, 'Reference' => $ref, 'Design notes' => $design];

foreach ($all as $p) {
    $depth = substr_count($p, '/');          // docs/x.md = 1, docs/design/x.md = 2
    $root = str_repeat('../', $depth);
    $md = new Md(dirname($p), $set, str_repeat('../', $depth - 1));
    $body = $md->render(file_get_contents(REPO_DIR . '/' . $p));
    $lab = label($p, $titles[$p]);
    $url = substr($p, 5, -3) . '.html';
    $search[] = [$titles[$p], $lab, $url, $excerpts[$p]];
    foreach ($md->headings as $h) {
        $parts = explode('|', $h, 2);
        $search[] = [$parts[1], $lab, $url . '#' . $parts[0], ''];
    }
    $edit = REPO . '/edit/main/' . $p;
    $body .= "<p class=\"edit\"><a href=\"$edit\">Edit this page on GitHub</a></p>\n";
    $side = sidebar($groups, $titles, str_repeat('../', $depth - 1), $p);
    $dest = "$out/" . substr($p, 0, -3) . '.html';
    @mkdir(dirname($dest), 0777, true);
    file_put_contents($dest, page($titles[$p], $body, $root, $side, $side));
}

// docs/index.html
$body = "<h1>Documentation</h1>\n";
foreach ($groups as $g => $paths) {
    $body .= '<h2>' . htmlspecialchars($g) . "</h2>\n<div class=\"cards\">\n";
    foreach ($paths as $p) {
        $ex = $excerpts[$p];
        if (strlen($ex) > 150) {
            $ex = rtrim(substr($ex, 0, 150)) . '…';
        }
        $href = substr($p, 5, -3) . '.html';
        $body .= '<a class="card" href="' . $href . '"><b>' . htmlspecialchars(label($p, $titles[$p])) . '</b><span>' . htmlspecialchars($ex) . "</span></a>\n";
    }
    $body .= "</div>\n";
}
$side = sidebar($groups, $titles, '', '');
file_put_contents("$out/docs/index.html", page('Documentation', $body, '../', $side, $side));

$items = ref_load(REPO_DIR, REF_SOURCES);
$index = [];
foreach ($items as $it) {
    $index[strtolower($it->fqn())] = ref_url($it);
}
RefCtx::$docs = $set;
ref_render($items, $out, $index, REPO_DIR, $search);

file_put_contents("$out/search.json", json_encode($search, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo 'built ', count($all) + 2, " pages into $out\n";
