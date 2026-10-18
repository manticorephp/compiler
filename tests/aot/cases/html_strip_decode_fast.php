<?php
$cases = ["", "plain", "<b>x</b>", "a<b>c</b>d", "<!-- c --> after", "<!-- open", "x<", "<p class=\"a\">Hello <b>w</b> & more</p> tail",
    "<a title=\"un>closed>text", "t<?php echo 1; ?>u", str_repeat("<i>ab</i> ", 40)];
foreach ($cases as $c) {
    echo json_encode(strip_tags($c)), ' ', json_encode(strip_tags($c, '<b><i>')), ' ', json_encode(strip_tags($c, '<a>')), "\n";
}
$ents = ["", "plain", "&amp;", "a &amp; b", "&lt;&gt;&quot;&#39;&#x41;&#65;", "&unknown; &", "&amp", "x&amp;y&lt;z", "&#xZZ;", "&#99999999;", "&copy;&nbsp;&euro;", "tail &amp;", "&&amp;&;", str_repeat("t &amp; ", 40)];
foreach ($ents as $e) {
    echo json_encode(html_entity_decode($e)), ' ', json_encode(html_entity_decode($e, ENT_NOQUOTES)), ' ', json_encode(html_entity_decode($e, ENT_COMPAT)), "\n";
}
