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
                if ($lvl === 2) {
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

function page(string $title, string $body, string $root, string $side, string $mside): string
{
    $t = htmlspecialchars($title);
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
  <a href="https://github.com/manticorephp/compiler/releases">Releases</a>
  <a href="https://github.com/manticorephp/compiler">GitHub</a>
  <button id="theme" aria-label="Toggle theme" title="Theme: auto">◐</button>
</div></nav>
<div class="docs">
<aside class="side">$side</aside>
<main class="prose">
<details class="mside"><summary>Menu</summary><div class="side">$mside</div></details>
$body
</main>
</div>
<script src="{$root}app.js"></script>
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
$groups = ['Guides' => $guides, 'Reference' => $ref, 'Design notes' => $design];

foreach ($all as $p) {
    $depth = substr_count($p, '/');          // docs/x.md = 1, docs/design/x.md = 2
    $root = str_repeat('../', $depth);
    $md = new Md(dirname($p), $set, str_repeat('../', $depth - 1));
    $body = $md->render(file_get_contents(REPO_DIR . '/' . $p));
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

echo 'built ', count($all) + 2, " pages into $out\n";
