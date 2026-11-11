<?php
class Real {}
class_alias('Real', 'Aliased');
function mk() { return new Aliased(); }
function late() { class_alias('Real', 'Late'); }
function useLate() { return new Late(); }
echo get_class(mk()), "\n";
