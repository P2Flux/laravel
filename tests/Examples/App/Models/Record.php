<?php

declare(strict_types=1);

namespace App\Models;

/**
 * An in-memory stand-in for an Eloquent model.
 *
 * The examples are merchant application code, so the harness fakes the merchant's half - the
 * database - and keeps the P2Flux half real: the examples resolve P2FluxClient from the container
 * exactly as they would in production, and only the transport is canned.
 *
 * It implements the query and attribute calls the examples actually make, and records every write,
 * which is what the scenarios assert on. It is not an ORM and is not shipped: this file lives in
 * tests/ and is export-ignored.
 */
abstract class Record
{
    /** @var array<class-string, array<int, static>> */
    private static array $tables = [];

    /** @var array<class-string, int> */
    private static array $nextId = [];

    /** @var list<array<string, mixed>> every update() this record received, oldest first */
    public array $writes = [];

    /** @param array<string, mixed> $attributes */
    final public function __construct(public array $attributes = [])
    {
    }

    public static function reset(): void
    {
        self::$tables[static::class] = [];
        self::$nextId[static::class] = 1;
    }

    /** @param array<string, mixed> $attributes */
    public static function create(array $attributes): static
    {
        $id = self::$nextId[static::class] ?? 1;
        self::$nextId[static::class] = $id + 1;

        $record = new static($attributes + ['id' => $id]);
        self::$tables[static::class][$id] = $record;

        return $record;
    }

    /** @return list<static> */
    public static function all(): array
    {
        return array_values(self::$tables[static::class] ?? []);
    }

    public static function find(int $id): ?static
    {
        return self::$tables[static::class][$id] ?? null;
    }

    // --- the query surface the examples use -------------------------------------------------

    public static function query(): Query
    {
        return new Query(static::class);
    }

    /** @param mixed ...$arguments */
    public static function where(...$arguments): Query
    {
        return (new Query(static::class))->where(...$arguments);
    }

    public static function whereKey(int $id): Query
    {
        return (new Query(static::class))->whereKey($id);
    }

    public static function findOrFail(int $id): static
    {
        /** @var static $record */
        $record = (new Query(static::class))->findOrFail($id);

        return $record;
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): bool
    {
        $this->writes[] = $attributes;
        $this->attributes = array_merge($this->attributes, $attributes);

        return true;
    }

    public function fresh(): static
    {
        return $this;
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }
}
