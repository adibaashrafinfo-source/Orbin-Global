<?php

namespace App\Http\Requests;

use App\Support\MasterData;
use Illuminate\Foundation\Http\FormRequest;

class MasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware gates the route
    }

    public function rules(): array
    {
        $resource = (string) $this->route('resource');
        $id = $this->route('id') !== null ? (int) $this->route('id') : null;

        return MasterData::rules($resource, $id);
    }
}
