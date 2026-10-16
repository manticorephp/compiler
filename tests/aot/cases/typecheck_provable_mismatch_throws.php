<?php
// php checks an argument against the parameter type when the call is MADE: a
// call that cannot succeed is a TypeError at run time, never a build error, and
// code that does not run costs nothing.
class Route
{
    /**
     * @param string[] $allow
     */
    public function __construct(array $allow, string $message = '')
    {
    }

    /**
     * @param int[] $data
     */
    public function write(array $data): string
    {
        return count($data) . ' items';
    }
}

class Base
{
    public function __construct(int $status, string $message = '')
    {
    }

    public static function make(int $status): self
    {
        return new static($status, 'm');
    }
}

class Derived extends Base
{
    /**
     * @param string[] $allow
     */
    public function __construct(array $allow, string $message = '')
    {
    }
}

function describe(Route $r, mixed $service): string
{
    if (is_array($service)) {
        return $r->write($service);
    }
    return $r->write($service::class);
}

function untouched(int $n): string
{
    if ($n > 100) {
        return (new Route('x'))->write([]);
    }
    return 'skipped';
}

new Derived(["GET"]);
echo untouched(1), "\n";
echo describe(new Route([]), [1, 2, 3]), "\n";

try {
    echo describe(new Route([]), new Route([])), "\n";
} catch (TypeError $e) {
    echo explode(", called in", $e->getMessage())[0], "\n";
}

try {
    Derived::make(404);
} catch (TypeError $e) {
    echo explode(", called in", $e->getMessage())[0], "\n";
}
