<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;

class LeaveTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => LeaveType::orderBy('id')->get(['id', 'name', 'default_quota']),
        ]);
    }
}