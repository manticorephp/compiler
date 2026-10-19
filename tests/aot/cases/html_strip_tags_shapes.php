<?php
$ins = ['', 'a', '&', '&;', '&amp;', '&amp', 'amp;', '&amp;&lt;&gt;&quot;&#039;&apos;', '&#65;&#x41;&#X41;&#1114111;&#1114112;&#xZZ;&#;', '&nbsp;&copy;&hellip;&euro;&notanentity;',
    'a&b;c', 'x&amp;y&amp;z', '&&amp;&', '&amp;&', ';&amp;;', str_repeat('a', 40) . '&amp;', '&' . str_repeat('a', 31) . ';', '&' . str_repeat('a', 32) . ';', '&#' . str_repeat('1', 30) . ';',
    "a\0&amp;\xff&lt;", 'é&eacute;ü&uuml;', '&#34;&#39;&#x22;&#x27;'];
foreach ($ins as $s) {
    foreach ([ENT_QUOTES, ENT_COMPAT, ENT_NOQUOTES] as $fl) {
        echo bin2hex(html_entity_decode($s, $fl)), ' ';
    }
    echo bin2hex(html_entity_decode($s)), "\n";
}
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 100000] as $n) {
    $s = substr(str_repeat('&amp;&lt;x &copy; &#65; & ', intdiv($n, 8) + 1), 0, $n);
    $r = html_entity_decode($s);
    echo $n, ' ', strlen($r), ' ', md5($r), "\n";
}
// strip_tags
$tags = ['', 'plain', '<b>x</b>', '<b>x</b> y <i>z</i>', '<!-- c --> after', 'a<!-- c', '<unterminated', '<p>para</p><br/>', '<?php echo 1; ?>text', '<b class="q">x</b>', "<b\n>x</b\n>", 'x<>y', '<script>alert(1)</script>ok'];
foreach ($tags as $s) {
    echo bin2hex(strip_tags($s)), ' ', bin2hex(strip_tags($s, '<b>')), ' ', bin2hex(strip_tags($s, '<b><i><a>')), "\n";
}
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 100000] as $n) {
    $s = substr(str_repeat('<b>x</b> y <!-- c --> ', intdiv($n, 8) + 1), 0, $n);
    echo $n, ' ', strlen(strip_tags($s)), ' ', md5(strip_tags($s)), ' ', md5(strip_tags($s, '<b>')), "\n";
}
