<?php

namespace App\Modules\Foundation\Lov;

final class LovRegistry
{
    /** @var array<string, LovDefinition> */
    private array $definitions = [];

    public function register(LovDefinition $definition): void
    {
        $this->definitions[$definition->name()] = $definition;
    }

    public function get(string $name): LovDefinition
    {
        return $this->definitions[$name] ?? abort(404);
    }
}
