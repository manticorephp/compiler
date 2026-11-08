<?php
interface VoterInterface {}
interface CacheableVoterInterface extends VoterInterface {}
/**
 * @template TAttribute of string
 * @template TSubject of mixed
 */
abstract class Voter implements VoterInterface, CacheableVoterInterface
{
    public function vote(mixed $subject, string $attribute): int { return $this->supports($attribute, $subject) ? 1 : 0; }
    abstract protected function supports(string $attribute, mixed $subject): bool;
}
final class Post { public function __construct(public string $title = 't') {} }
/** @extends Voter<string, Post> */
final class PostVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool { return $subject instanceof Post && $attribute === 'view'; }
}
function chain(string $c): string {
    $r = new ReflectionClass($c); $o = [];
    for ($n = 0; $r = $r->getParentClass(); $n++) { $o[] = $r->getName(); if ($n > 6) { $o[] = '...'; break; } }
    return implode(' > ', $o);
}
echo (new PostVoter)->vote(new Post, 'view'), "\n";
foreach ([PostVoter::class, Voter::class] as $c) { echo $c, ': [', chain($c), "]\n"; }
