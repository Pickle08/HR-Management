<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NamedResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Logika bersama untuk departemen dan jabatan: keduanya hanya punya nama unik. */
abstract class OrganizationController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function label(): string;

    abstract protected function isUsed(Model $item): bool;

    protected function listAll(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage', $this->model());

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $class = $this->model();

        $items = $class::query()
            ->withCount('employees')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return NamedResource::collection($items);
    }

    protected function create(Request $request): JsonResponse
    {
        Gate::authorize('manage', $this->model());

        $data = $this->validatedName($request, null);
        $class = $this->model();

        $item = new $class($data);
        $this->persist($item);

        return (new NamedResource($item->loadCount('employees')))->response()->setStatusCode(201);
    }

    protected function rename(Request $request, Model $item): NamedResource
    {
        Gate::authorize('manage', $this->model());

        $item->fill($this->validatedName($request, $item));
        $this->persist($item);

        return new NamedResource($item->loadCount('employees'));
    }

    protected function remove(Model $item): Response
    {
        Gate::authorize('manage', $this->model());

        $message = "{$this->label()} masih dipakai oleh karyawan atau lowongan, jadi tidak bisa dihapus.";

        if ($this->isUsed($item)) {
            abort(409, $message);
        }

        try {
            $item->delete();
        } catch (QueryException) {
            // Penjaga terakhir: foreign key di database, kalau ada yang memakainya persis saat ini.
            abort(409, $message);
        }

        return response()->noContent();
    }

    private function validatedName(Request $request, ?Model $current): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        $class = $this->model();
        $table = (new $class)->getTable();

        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique($table, 'name')->ignore($current?->id)],
        ]);
    }

    private function persist(Model $item): void
    {
        try {
            $item->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => ["{$this->label()} dengan nama ini sudah ada."],
            ]);
        }
    }
}
