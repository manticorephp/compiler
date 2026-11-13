<?php
namespace Lib { class Numeric {} }
namespace App {
    use Lib\Numeric;
    function f(): Numeric { return new Numeric(); }
}
