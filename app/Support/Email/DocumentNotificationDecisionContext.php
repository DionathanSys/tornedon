<?php

namespace App\Support\Email;

class DocumentNotificationDecisionContext
{
    /**
     * @var array<string,bool>
     */
    private static array $overrides = [];

    public static function put(string $documentType, int $documentId, bool $shouldSend): void
    {
        self::$overrides[self::key($documentType, $documentId)] = $shouldSend;
    }

    public static function pull(string $documentType, int $documentId): ?bool
    {
        $key = self::key($documentType, $documentId);

        if (! array_key_exists($key, self::$overrides)) {
            return null;
        }

        $value = self::$overrides[$key];
        unset(self::$overrides[$key]);

        return $value;
    }

    private static function key(string $documentType, int $documentId): string
    {
        return "{$documentType}:{$documentId}";
    }
}
