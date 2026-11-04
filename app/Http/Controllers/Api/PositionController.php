<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $positions = Position::withCount('employees')->orderBy('name')->get();

        return PositionResource::collection($positions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:positions,name'],
        ]);

        $position = Position::create($data);

        return (new PositionResource($position))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Position $position): PositionResource
    {
        return new PositionResource($position->loadCount('employees'));
    }

    public function update(Request $request, Position $position): PositionResource
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('positions', 'name')->ignore($position->id),
            ],
        ]);

        $position->update($data);

        return new PositionResource($position->loadCount('employees'));
    }

    public function destroy(Position $position): JsonResponse
    {
        if ($position->employees()->exists()) {
            return response()->json([
                'message' => 'Jabatan masih dipakai karyawan, tidak bisa dihapus.',
            ], 422);
        }

        $position->delete();

        return response()->json(['message' => 'Jabatan dihapus.']);
    }
}