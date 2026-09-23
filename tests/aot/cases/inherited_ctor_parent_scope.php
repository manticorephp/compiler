<?php

namespace App\Errors {
    class NotFound extends \InvalidArgumentException
    {
        public const KIND = 'not-found';

        /**
         * @param string[] $alternatives
         */
        public function __construct(
            string $message,
            private array $alternatives = [],
            int $code = 0,
            ?\Throwable $previous = null,
        ) {
            parent::__construct(self::KIND . ': ' . $message, $code, $previous);
        }

        /** @return string[] */
        public function getAlternatives(): array { return $this->alternatives; }
    }

    // Inherits the constructor. `parent::` and `self::` inside it still mean
    // NotFound's, while the object is a NamespaceNotFound.
    class NamespaceNotFound extends NotFound
    {
        public const KIND = 'ns';
        public string $extra = 'defaulted';
    }
}

namespace {
    $e = new App\Errors\NamespaceNotFound('app:x', ['app:y'], 3);
    var_dump($e->getMessage(), $e->getCode(), $e->getAlternatives(), $e->extra, $e instanceof InvalidArgumentException);
}
