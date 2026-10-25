<?php
// A closing tag terminates a statement without a semicolon
$k = "v";
function f() {
    echo "a"
    ?>
    mid
    <?php
    return strtoupper("b")
?>
<?php }
echo f(), "\n";
