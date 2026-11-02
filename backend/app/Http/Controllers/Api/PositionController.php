<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\NamedResource;
use App\Models\JobOpening;
use App\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PositionController extends OrganizationController
{
    protected function model(): string
    {
        return Position::class;
    }

    protected function label(): string
    {
        return 'Jabatan';
    }

    protected function isUsed(Model $item): bool
    {
        return $item->employees()->exists()
            || JobOpening::where('position_id', $item->id)->exists();
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->listAll($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->create($request);
    }

    public function update(Request $request, Position $position): NamedResource
    {
        return $this->rename($request, $position);
    }

    public function destroy(Position $position): Response
    {
        return $this->remove($position);
    }
}
