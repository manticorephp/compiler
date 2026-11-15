<?php
/* stub: input/a_functions.php */ namespace {


/**
 * Adds two numbers.
 */
#[Pure]
function add(int $a, int $b = 2): int
{}

function &refs(array &$xs, string ...$rest): array {}

function greeting(string $tpl = <<<TXT
    Hello, {name}!
    TXT, ?callable $cb = null): string
{}


if (!function_exists('polyfill_one')) {
    /** A polyfill. */
    function polyfill_one(string $s): string
    {}
}


define('STUB_LIMIT', 10 * 2);
const STUB_NAME = 'stub';

}
/* stub: input/b_namespace.php */

namespace App\Model {

use InvalidArgumentException;
use App\Contracts\{HasName, HasId};

/** Something with a name. */
interface Named
{
    public const PREFIX = 'n:';

    public function name(): string;
}

trait Greets
{
    public function greet(): string
    {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Marker
{
}

abstract class Base implements Named
{
    protected static int $count = 0;

    abstract protected function id(): int;

    public static function count(): int {}
}

/**
 * A user.
 */
#[Marker]
final class User extends Base
{
    use Greets;

    public const string KIND = 'user';

    private array $tags = ['a', 'b'];

    public string $bio = <<<'BIO'
        multi
        line
        BIO;

    /**
     * @param string $name the name
     */
    public function __construct(
        /** The display name. */
        #[\SensitiveParameter] public readonly string $name,
        protected int $id = 0,
        private ?Status $status = Status::Active,
    ) {}

    public function name(): string {}

    protected function id(): int
    {}
}

enum Status: string implements \JsonSerializable
{
    case Active = 'active';
    case Banned = 'banned';

    const DEFAULT = self::Active;

    public function label(): string
    {}

    public function jsonSerialize(): mixed {}
}

readonly class Point
{
    public function __construct(public int $x = 0, public int $y = 0) {}
}

function helper(Status $s = Status::Banned): Status {}

} namespace App\Other {

function other(): void
{}
}
/* stub: input/c_braced.php */

namespace Lib\Util {
    const VERSION = '1.0';

    function slug(string $s): string
    {}

    try {
        function maybe(): int {}
    } catch (\Throwable $e) {
    }
}

namespace {
    function global_fn(): bool {}

}
