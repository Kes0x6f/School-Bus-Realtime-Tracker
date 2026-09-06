<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'latitude'   => ['required', 'numeric', 'between:-90,90'],
            'longitude'  => ['required', 'numeric', 'between:-180,180'],
            'speed_mps'  => [
                'nullable',
                'numeric',
                'min:0',
                'max:' . Vehicle::MAX_SPEED_MPS,
            ],
            'speed'      => ['prohibited'],
        ];
    }
}
