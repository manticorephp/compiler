<?php

namespace App\Model;

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
    {
        return 'hi ' . $this->name();
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Marker
{
}

abstract class Base implements Named
{
    protected static int $count = 0;

    abstract protected function id(): int;

    public static function count(): int { return static::$count; }
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
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('empty');
        }
    }

    public function name(): string { return $this->name; }

    protected function id(): int
    {
        return $this->id;
    }
}

enum Status: string implements \JsonSerializable
{
    case Active = 'active';
    case Banned = 'banned';

    const DEFAULT = self::Active;

    public function label(): string
    {
        return match ($this) {
            self::Active => 'A',
            self::Banned => 'B',
        };
    }

    public function jsonSerialize(): mixed { return $this->value; }
}

readonly class Point
{
    public function __construct(public int $x = 0, public int $y = 0) {}
}

function helper(Status $s = Status::Banned): Status { return $s; }

namespace App\Other;

function other(): void
{
    echo "x";
}
