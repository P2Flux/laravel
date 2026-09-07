<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/** The slice of a query builder the examples touch, over an in-memory array. */
final class Query
{
    /** @var list<callable(Record): bool> */
    private array $filters = [];

    private ?int $limit = null;

    /** @param class-string<Record> $model */
    public function __construct(private string $model)
    {
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

        $this->filters[] = static function (Record $record) use ($column, $operator, $value): bool {
            $actual = $record->{$column};

            return match ($operator) {
                '=' => $actual == $value,
                '<' => $actual !== null && $actual < $value,
                '<=' => $actual !== null && $actual <= $value,
                '>' => $actual !== null && $actual > $value,
                '>=' => $actual !== null && $actual >= $value,
                default => false,
            };
        };

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->filters[] = static fn (Record $record): bool => $record->{$column} !== null;

        return $this;
    }

    public function whereKey(int $id): self
    {
        return $this->where('id', $id);
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /** No database here, so this records intent rather than taking a lock - see the harness notes. */
    public function lockForUpdate(): self
    {
        return $this;
    }

    /** @return list<Record> */
    public function get(): array
    {
        $model = $this->model;
        $matching = array_values(array_filter(
            $model::all(),
            fn (Record $record): bool => array_reduce(
                $this->filters,
                static fn (bool $carry, callable $filter): bool => $carry && $filter($record),
                true,
            ),
        ));

        return $this->limit === null ? $matching : array_slice($matching, 0, $this->limit);
    }

    public function cursor(): array
    {
        return $this->get();
    }

    public function first(): ?Record
    {
        return $this->get()[0] ?? null;
    }

    public function findOrFail(int $id): Record
    {
        $record = $this->whereKey($id)->first();

        if ($record === null) {
            throw (new ModelNotFoundException())->setModel($this->model, [$id]);
        }

        return $record;
    }
}
