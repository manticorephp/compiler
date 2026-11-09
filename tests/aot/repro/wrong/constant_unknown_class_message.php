<?php
// constant() of a constant on an undeclared class says "Undefined constant X::C" where php says Class "X" not found
// issue: #604
try { constant("Nope\\Missing::C"); } catch (Error $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
