<?php
// Top-level entry — invoked as the binary's main(). Sorted last by
// bin/compile so every class / function declaration is registered
// before this top-level call lowers.

exit(\Manticore\main_driver());
