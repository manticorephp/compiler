<?php
// Exception and Error declare the private `trace` property (symfony/polyfill-deepclone resets it through reflection).
$setTrace = [
    new ReflectionProperty(\Error::class, 'trace'),
    new ReflectionProperty(\Exception::class, 'trace'),
];
foreach ([new Error('e1'), new Exception('e2')] as $i => $e) {
    $setTrace[$i]->setValue($e, []);
    echo get_class($e), ' ', $setTrace[$i]->name, ' ', $e->getMessage(), ' ', count($e->getTrace()) >= 0 ? 'ok' : 'bad', "\n";
}
