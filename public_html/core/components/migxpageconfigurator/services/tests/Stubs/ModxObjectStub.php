<?php

namespace MpcTests\Stubs;

/**
 * Заглушка MODX ORM-объекта (modResource, modSystemSetting и т.д.)
 */
class ModxObjectStub
{
    private string $class;
    private array $data = [];

    public function __construct(string $class, array $data = [])
    {
        $this->class = $class;
        $this->data = $data;
    }

    public function get(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    public function set(string $field, mixed $value): void
    {
        $this->data[$field] = $value;
    }

    public function save(): bool
    {
        return true;
    }

    public function getTVValue(string $tvName): mixed
    {
        return $this->data["tv_{$tvName}"] ?? '';
    }

    public function toArray(): array
    {
        return $this->data;
    }
}

/**
 * Заглушка MODX Query (xPDOQuery)
 */
class ModxQueryStub
{
    public object $stmt;

    public function __construct()
    {
        $this->stmt = new class {
            public function execute(): bool { return false; }
            public function fetchColumn(): mixed { return null; }
        };
    }

    public function leftJoin(string $class, string $alias, string $on): void {}
    public function where(array $conditions): void {}
    public function select(string $fields): void {}
    public function prepare(): void {}
    public function limit(int $limit): void {}
    public function sortby(string $field, string $dir = 'ASC'): void {}
}
