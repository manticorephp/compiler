<?php
// The getaddrinfo fallback runs on the pool: numeric and /etc/hosts names resolve
// the same inside async() as outside, and the call is counted as offloaded.
use function Async\async;
echo gethostbyname('127.0.0.1'), "\n";
echo gethostbyname('localhost') !== 'localhost' ? "localhost ok\n" : "localhost fail\n";
async(function () {
    $before = Async\stats()['offloaded'];
    echo gethostbyname('127.0.0.1'), "\n";
    echo gethostbyname('localhost') !== 'localhost' ? "localhost ok\n" : "localhost fail\n";
    echo "gai offloaded: ", Async\stats()['offloaded'] > $before ? 1 : 0, "\n";
});
