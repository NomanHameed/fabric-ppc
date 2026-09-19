<?php

namespace App\Modules\Foundation\Masters;

final class MasterRegistry
{
    /** @var array<string, MasterResource> */
    private array $resources = [];

    public function register(MasterResource $resource): void
    {
        $this->resources[$resource->key()] = $resource;
    }

    public function get(string $key): MasterResource
    {
        return $this->resources[$key] ?? abort(404);
    }
}
