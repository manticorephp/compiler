<?php
function handler(string $dsn): string {
    switch (true) {
        case str_starts_with($dsn, 'oci://'):
            if (!class_exists(Missing\DriverManager::class)) {
                throw new InvalidArgumentException('needs dbal');
            }
            return (new Missing\DsnParser())->parse($dsn);
        default:
            try {
                if (!function_exists('missing_fetch')) { return 'none'; }
                return missing_fetch();
            } finally {
                echo "done\n";
            }
    }
}
echo handler('x'), "\n";
