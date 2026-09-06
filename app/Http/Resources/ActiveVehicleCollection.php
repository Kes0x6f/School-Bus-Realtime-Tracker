<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ActiveVehicleCollection extends ResourceCollection
{
    public static $wrap = null;

    public $collects = ActiveVehicleResource::class;

    /**
     * Preserve the existing top-level array contract used by the tracking
     * client while still serializing each item through a resource class.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        return $this->collection->map->resolve($request)->all();
    }
}
