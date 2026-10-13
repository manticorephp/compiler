<?php

// ext/filter exceptions (php 8.5). Braced namespace, no dependencies beyond \Exception.
// The filter_* functions themselves live in src/Runtime/Stdlib/Filter.php.
namespace Filter {

    class FilterException extends \Exception {}

    class FilterFailedException extends FilterException {}
}
