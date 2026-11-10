<?php
function outer() {
    function inner() {}
    inner_missing();
}
outer();
