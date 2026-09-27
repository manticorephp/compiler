<?php

namespace Manticore\TestRunner;

$runner = new Runner(dirname(__DIR__, 2), new Terminal());

exit($runner->execute());
