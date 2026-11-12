<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ResetUserPasswordRequest;
use App\Http\Requests\Users\SetUserPinRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** FR-103 User management. */
class UserController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);
        $query = User::query()->orderBy('full_name');
        if ($role = $request->input('filter.role')) {
            $query->where('role', $role);
        }
        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }
        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(fn ($q) => $q->where('full_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $page = $query->paginate($perPage);

        return UserResource::collection($page->items())->additional([
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(StoreUserRequest $request): UserResource
    {
        Gate::authorize('create', User::class);
        $data = $request->validated();
        if (! Gate::allows('assignRole', [User::class, $data['role']])) {
            throw ApiException::forbidden();
        }
        $user = new User(collect($data)->except('password')->all());
        $user->email = Str::lower($data['email']);
        $user->password_hash = Hash::make($data['password']);
        $user->must_change_password = (bool) ($data['must_change_password'] ?? true);
        $user->save();

        return new UserResource($user);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        Gate::authorize('update', $user);
        $data = $request->validated();
        /** @var User $actor */
        $actor = $request->user();
        if (isset($data['role']) && ! Gate::allows('assignRole', [User::class, $data['role']])) {
            throw ApiException::forbidden();
        }
        if ($user->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw ApiException::forbidden();
        }
        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $user->id === $actor->id) {
            throw new ApiException('SELF_DEACTIVATE', 422);
        }
        $demotes = ($user->isSuperAdmin() && (($data['role'] ?? 'super_admin') !== 'super_admin' || (array_key_exists('is_active', $data) && ! $data['is_active'])));
        if ($demotes && User::query()->where('role', 'super_admin')->where('is_active', true)->where('id', '!=', $user->id)->doesntExist()) {
            throw new ApiException('LAST_SUPER_ADMIN', 422);
        }
        if (isset($data['email'])) {
            $data['email'] = Str::lower($data['email']);
        }
        DB::transaction(function () use ($user, $data): void {
            $user->fill($data)->save();
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        return new UserResource($user->refresh());
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): UserResource
    {
        Gate::authorize('update', $user);
        /** @var User $actor */
        $actor = $request->user();
        if ($user->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw ApiException::forbidden();
        }
        User::withoutEvents(function () use ($user, $request): void {
            $user->forceFill([
                'password_hash' => Hash::make($request->string('password')),
                'must_change_password' => $request->boolean('must_change_password', true),
            ])->save();
        });
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->audit->log('password_reset', 'users', $user->id, null, ['by_admin' => true]);

        return new UserResource($user->refresh());
    }

    public function setPin(SetUserPinRequest $request, User $user): UserResource
    {
        Gate::authorize('update', $user);
        $pin = $request->input('pin');
        User::withoutEvents(function () use ($user, $pin): void {
            $user->forceFill([
                'override_pin_hash' => $pin ? Hash::make($pin) : null,
                'pin_failed_attempts' => 0,
                'pin_locked_until' => null,
            ])->save();
        });
        $this->audit->log('pin_change', 'users', $user->id, null, ['pin_set' => $pin !== null]);

        return new UserResource($user->refresh());
    }
}
