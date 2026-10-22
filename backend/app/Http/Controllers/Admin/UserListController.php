<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Authorization\Role;
use App\Domain\Authorization\UserDirectory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class UserListController extends Controller
{
    public function __construct(private readonly UserDirectory $directory) {}

    public function __invoke(Request $request): View
    {
        $query = mb_strtolower(trim((string) $request->query('q', '')));
        $users = $this->directory->all()
            ->when($query !== '', fn ($rows) => $rows->filter(
                fn ($user): bool => str_contains(mb_strtolower($user->email.' '.$user->role), $query),
            ))
            ->values();

        return view('admin.users.index', [
            'viewer' => $request->user(),
            'users' => $users,
            'total' => $users->count(),
            'query' => (string) $request->query('q', ''),
            'roles' => Role::cases(),
        ]);
    }
}
