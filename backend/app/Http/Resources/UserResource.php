<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'default_branch_id' => $this->default_branch_id,
            'branch_ids' => $this->branch_ids,
            'avatar_url' => $this->avatar_url,
            'language' => $this->language,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'has_override_pin' => $this->hasOverridePin(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
