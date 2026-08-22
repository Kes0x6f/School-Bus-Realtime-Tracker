<?php

namespace App\Http\Requests;

use App\Enums\VehicleRoute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'route_name' => ['required', new Enum(VehicleRoute::class)],
        ];
    }
}
