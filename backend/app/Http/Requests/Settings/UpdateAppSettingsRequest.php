<?php

namespace App\Http\Requests\Settings;

use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAppSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return SettingsService::rules();
    }
}
