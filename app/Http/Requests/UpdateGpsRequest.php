<?php

namespace App\Http\Requests;

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
            'latitude'   => ['required', 'numeric'],
            'longitude'  => ['required', 'numeric'],
            'speed'      => ['nullable', 'numeric'],
        ];
    }
}
