<?php
// Function and static-method forms of a `class-string<T>` template, `@phpstan-` spellings included.
namespace App\Model;

class User { public function __construct(public string $name = 'anon') {} public function label(): string { return "user:" . $this->name; } }

/**
 * @template T of object
 * @param class-string<T> $cls
 * @return T
 */
function make(string $cls): object { return new $cls(); }

final class Factory {
    /**
     * @phpstan-template TModel of object
     * @param string $cls
     * @phpstan-param class-string<TModel> $cls
     * @phpstan-return TModel
     * @return object
     */
    public static function create(string $cls, string $name): object { return new $cls($name); }
}

echo make(User::class)->label(), "\n";
$u = Factory::create(User::class, 'ada');
echo $u->label(), " ", $u->name, "\n";
echo \App\Model\make('App\Model\User')->name, "\n";
