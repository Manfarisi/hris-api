<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $departments = Department::withCount('employees')->orderBy('name')->get();

        return DepartmentResource::collection($departments);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:departments,name'],
        ]);

        $department = Department::create($data);

        return (new DepartmentResource($department))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Department $department): DepartmentResource
    {
        return new DepartmentResource($department->loadCount('employees'));
    }

    public function update(Request $request, Department $department): DepartmentResource
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('departments', 'name')->ignore($department->id),
            ],
        ]);

        $department->update($data);

        return new DepartmentResource($department->loadCount('employees'));
    }

    public function destroy(Department $department): JsonResponse
    {
        if ($department->employees()->exists()) {
            return response()->json([
                'message' => 'Departemen masih punya karyawan, tidak bisa dihapus.',
            ], 422);
        }

        $department->delete();

        return response()->json(['message' => 'Departemen dihapus.']);
    }
}