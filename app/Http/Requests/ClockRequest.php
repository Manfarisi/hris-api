<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Koordinat hanya wajib kalau validasi lokasi sedang aktif
        $presence = config('hris.attendance.require_location') ? 'required' : 'nullable';

        return [
            'latitude' => [$presence, 'numeric', 'between:-90,90'],
            'longitude' => [$presence, 'numeric', 'between:-180,180'],
        ];
    }
}