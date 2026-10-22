<?php
// A prelude-internal class used directly must pull the tokenizer in.
$t = \__McTok::run("<?php // hi\r\$x;", 0, 1);
echo $t->n, "\n";
for ($i = 0; $i < $t->n; $i++) {
    echo json_encode($t->texts[$i]), ' ', $t->offs[$i], "\n";
}
