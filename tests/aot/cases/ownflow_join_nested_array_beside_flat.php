<?php
function setHeaders(array $headers): array {
    $headers = array_values($headers);
    if ($headers && !\is_array($headers[0])) {
        $headers = [$headers];
    }
    return $headers;
}
var_dump(setHeaders(['a', 'b']), setHeaders([['x', 'y']]));
