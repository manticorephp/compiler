<?php
// Dom\HTMLDocument::createFromString over ordinary markup; nodeName compared lower-case (see the prelude's note).
function dump($n, int $d = 0): void {
    foreach ($n->childNodes as $c) {
        if ($c->nodeType === 10) { continue; }
        $name = strtolower($c->nodeName);
        $line = str_repeat(' ', $d) . $name;
        if ($c instanceof \Dom\Element) {
            $as = [];
            foreach ($c->attributes as $a) { $as[] = strtolower($a->name) . '=' . $a->value; }
            sort($as);
            if ($as) { $line .= ' [' . implode(' ', $as) . ']'; }
        } elseif ($name === '#text' || $name === '#comment') { $line .= ' ' . json_encode($c->textContent); }
        echo $line, "\n";
        if ($c->hasChildNodes()) { dump($c, $d + 1); }
    }
}
$cases = [
    '<!DOCTYPE html><body><h2>Title &amp; more</h2><p>one <strong>two</strong> <em>three</em><br>four</p><p>five<p>six<ul><li>a<li>b</ul><hr><pre>' . "\n" . 'code &lt;x&gt;</pre></body>',
    '<p class="a b" data-x=1 HREF=\'q\' disabled>x</p><img src="i.png" alt="a&quot;b"><a href="/u?a=1&amp;b=2">l</a><!-- c --><blockquote><p>q</p></blockquote>',
    '<html lang="en"><head><title>T &amp; t</title><meta charset="utf-8"><style>p > a { }</style><script>if (a<b) { x("</p>"); }</script></head><body class="k">t</body></html>',
    'plain 1 < 2 &copy; <b>bold</b></p> after',
    '<table><tr><td>1<td>2<tr><th>3</table><dl><dt>a<dd>b<dt>c</dl><select><option>x<option>y</select><textarea>' . "\n" . '<b>r</b> &amp;</textarea>',
    '<h1>a<h2>b</h2><div><p>in</div>out',
];
foreach ($cases as $i => $h) {
    echo "== $i\n";
    $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html>' . $h, LIBXML_NOERROR);
    dump($doc);
}
$d = @\Dom\HTMLDocument::createFromString('<!DOCTYPE html><body><p>x</p></body>', LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED);
$b = $d->getElementsByTagName('body')->item(0);
var_dump($b->hasChildNodes(), $b instanceof \Dom\Node);
foreach ($b->attributes?->getIterator() ?? [] as $a) { echo $a->name; }
