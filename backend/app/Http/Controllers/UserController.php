<?php

namespace App\Http\Controllers;

use App\Domain\Authorization\UserDirectory;
use Illuminate\Http\JsonResponse;

final class UserController extends Controller
{
    public function __construct(private readonly UserDirectory $directory) {}

    public function index(): JsonResponse
    {
        return response()->json($this->directory->all());
    }
}
