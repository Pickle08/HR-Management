<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Semua role butuh daftar jenis cuti untuk form; yang nonaktif cuma dilihat pengelola.
        $types = LeaveType::query()
            ->when(
                ! $request->user()->can('manage', LeaveType::class),
                fn ($q) => $q->where('is_active', true)
            )
            ->orderBy('name')
            ->get();

        return LeaveTypeResource::collection($types);
    }

    public function store(SaveLeaveTypeRequest $request): LeaveTypeResource
    {
        $type = LeaveType::create(array_merge(['is_active' => true], $request->validated()));

        return new LeaveTypeResource($type);
    }

    public function update(SaveLeaveTypeRequest $request, LeaveType $leaveType): LeaveTypeResource
    {
        $leaveType->update($request->validated());

        return new LeaveTypeResource($leaveType);
    }
}
