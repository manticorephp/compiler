<?php
final class Loader
{
    private bool $deferred = false;

    public function load(string $env): string
    {
        $tag = 'env:' . $env;
        $this->deferred = true;
        try {
            if ('local' === $env) {
                return 'early ' . $tag;
            }
            echo "body ", $tag, "\n";
        } finally {
            try {
                echo "resolve ", $tag, "\n";
            } finally {
                $this->deferred = false;
                echo "reset\n";
            }
        }
        return 'late ' . $tag;
    }
}

function nested(bool $early): void
{
    $s = 'x' . (string) $early;
    try {
        if ($early) {
            return;
        }
        echo "run ", $s, "\n";
    } finally {
        try {
            echo "a ", $s, "\n";
        } finally {
            echo "b\n";
        }
    }
    echo "end\n";
}

$l = new Loader();
echo $l->load('local'), "\n";
echo $l->load('dev'), "\n";
nested(true);
nested(false);
