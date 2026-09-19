<?php

namespace App\Modules\Foundation\Masters;

final class Field
{
    private string $label;

    private bool $required = false;

    private array $options = [];

    private ?string $lov = null;

    private ?string $displayFrom = null;

    private int $span = 3;

    private bool $grid = true;

    private ?string $queryType;

    private bool $readonly = false;

    private function __construct(public readonly string $name, public readonly string $type)
    {
        $this->label = 'masters.fields.'.$name;
        $this->queryType = match ($type) {
            'number' => 'number',
            'bool' => 'bool',
            'multiselect', 'lov' => null,
            default => 'text',
        };
    }

    public static function make(string $name, string $type = 'text'): self
    {
        return new self($name, $type);
    }

    public function label(string $key): self { $this->label = $key; return $this; }

    public function required(): self { $this->required = true; return $this; }

    /** @param list<array{value: mixed, label: string}> $options */
    public function options(array $options): self { $this->options = $options; return $this; }

    public function lov(string $lov, string $displayFrom): self { $this->lov = $lov; $this->displayFrom = $displayFrom; return $this; }

    public function span(int $span): self { $this->span = $span; return $this; }

    public function grid(bool $grid = true): self { $this->grid = $grid; return $this; }

    public function queryType(?string $type): self { $this->queryType = $type; return $this; }

    public function readonly(): self { $this->readonly = true; return $this; }

    public function queryable(): ?string { return $this->queryType; }

    public function toArray(): array
    {
        return [
            'name' => $this->name, 'type' => $this->type, 'label' => __($this->label), 'required' => $this->required,
            'options' => $this->options, 'lov' => $this->lov, 'displayFrom' => $this->displayFrom, 'span' => $this->span,
            'grid' => $this->grid, 'queryable' => $this->queryType !== null, 'readonly' => $this->readonly,
        ];
    }
}
