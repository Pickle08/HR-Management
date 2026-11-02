<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    private const WITH = ['employee:id,user_id,employee_number,full_name,is_active'];

    public function __construct(private AccountService $accounts)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage', User::class);

        $filters = $request->validate([
            'role' => ['nullable', Rule::enum(Role::class)],
            'is_active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $users = User::query()
            ->with(self::WITH)
            ->when($filters['role'] ?? null, fn ($q, $v) => $q->where('role', $v))
            ->when(
                ($filters['is_active'] ?? null) !== null,
                fn ($q) => $q->where('is_active', $request->boolean('is_active'))
            )
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
            ))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return AccountResource::collection($users);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        [$user, $password] = $this->accounts->create($request->validated());

        return $this->withPassword($user, $password)->setStatusCode(201);
    }

    public function changeRole(Request $request, User $user): AccountResource
    {
        Gate::authorize('manage', User::class);

        $data = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);

        return $this->respond($this->accounts->changeRole($request->user(), $user, Role::from($data['role'])));
    }

    public function lock(Request $request, User $user): AccountResource
    {
        Gate::authorize('manage', User::class);

        return $this->respond($this->accounts->lockAccount($request->user(), $user));
    }

    public function unlock(User $user): AccountResource
    {
        Gate::authorize('manage', User::class);

        return $this->respond($this->accounts->unlockAccount($user));
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manage', User::class);

        [$user, $password] = $this->accounts->resetPassword($request->user(), $user);

        return $this->withPassword($user, $password);
    }

    private function respond(User $user): AccountResource
    {
        return new AccountResource($user->load(self::WITH));
    }

    private function withPassword(User $user, string $password): JsonResponse
    {
        return (new AccountResource($user->load(self::WITH)))
            ->additional(['meta' => ['temporary_password' => $password]])
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }
}
