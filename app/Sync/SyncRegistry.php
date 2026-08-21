<?php

namespace App\Sync;

use RuntimeException;

class SyncRegistry
{
    /**
     * The entity key (e.g. "customer") for a model instance or class name.
     */
    public static function keyForModel(object|string $model): string
    {
        $class = is_object($model) ? get_class($model) : $model;

        foreach (config('sync.entities', []) as $key => $entity) {
            if ($entity['model'] === $class) {
                return $key;
            }
        }

        throw new RuntimeException("No sync entity registered for model [{$class}]. Add it to config/sync.php.");
    }

    public static function modelFor(string $entity): string
    {
        return static::entry($entity)['model'];
    }

    public static function table(string $entity): string
    {
        return static::entry($entity)['table'];
    }

    /**
     * The field allowlist — applied in both directions. Anything not
     * listed is stripped from wire payloads.
     *
     * @return array<int, string>
     */
    public static function fields(string $entity): array
    {
        return static::entry($entity)['fields'];
    }

    public static function scopeFor(string $entity): string
    {
        return static::entry($entity)['scope'];
    }

    /**
     * @return array{model: class-string, table: string, scope: class-string, fields: array<int, string>}
     */
    protected static function entry(string $entity): array
    {
        return config("sync.entities.{$entity}")
            ?? throw new RuntimeException("Unknown sync entity [{$entity}]. Register it in config/sync.php.");
    }
}
