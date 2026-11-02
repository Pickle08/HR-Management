<?php

namespace App\Http\Controllers\Api;

use App\Models\Department;
use App\Models\JobOpening;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use App\Http\Resources\NamedResource;

class DepartmentController extends OrganizationController
{
    protected function model(): string
    {
        return Department::class;
    }

    protected function label(): string
    {
        return 'Departemen';
    }

    protected function isUsed(Model $item): bool
    {
        return $item->employees()->exists()
            || JobOpening::where('department_id', $item->id)->exists();
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->listAll($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->create($request);
    }

    public function update(Request $request, Department $department): NamedResource
    {
        return $this->rename($request, $department);
    }

    public function destroy(Department $department): Response
    {
        return $this->remove($department);
    }
}
