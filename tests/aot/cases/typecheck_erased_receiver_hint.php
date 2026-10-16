<?php
// A method call on an erased receiver is typed by the classes that declare the
// name; with one declarer that is a hint, not proof, so it is no build error.
class Dump
{
    public function getMessages(): iterable
    {
        yield 1;
    }
}

interface Message
{
}

class Events
{
    /**
     * @return Message[]
     */
    public static function messages(): array
    {
        return self::events()->getMessages();
    }

    private static function events(): Missing\Events
    {
        throw new LogicException('no events');
    }
}

try {
    Events::messages();
} catch (LogicException $e) {
    echo $e->getMessage(), "\n";
}
