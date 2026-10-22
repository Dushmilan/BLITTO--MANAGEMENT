<x-layouts.app :title="'Users'">
    <div class="space-y-6">
        <div>
            <h1 class="text-heading-3 font-semibold text-ink">Users</h1>
            <p class="mt-1 text-body-sm text-steel">Manage user accounts and roles.</p>
        </div>

        <x-ui.card title="Create user" subtitle="Only the MD may create director and MD accounts.">
            <form method="POST" action="{{ route('auth.register') }}">
                @csrf

                <x-ui.field label="Email" name="email" type="email" required hint="Must be an institution address." />

                <div class="mb-4">
                    <label for="role" class="mb-1 block text-body-sm font-medium text-ink">Role</label>
                    <select id="role" name="role" class="w-full rounded-input border border-hairline bg-canvas px-3 py-2 text-body-md text-ink">
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->value }}</option>
                        @endforeach
                    </select>
                </div>

                <x-ui.field label="Password" name="password" type="password" required hint="72 bytes maximum (bcrypt limit)." />
                <x-ui.field label="User code" name="user_code" hint="Optional internal staff code." />

                <x-ui.button variant="primary" type="submit">Create user</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card>
            <p class="mb-4 text-caption text-steel">{{ $total }} users registered</p>

            <form method="GET" action="{{ route('admin.users.index') }}" class="mb-4 flex items-end gap-3">
                <div class="min-w-64 flex-1">
                    <label for="q" class="mb-1 block text-body-sm font-medium text-ink">Search users</label>
                    <input id="q" name="q" type="search" value="{{ $query }}" class="w-full rounded-input border border-hairline bg-canvas px-3 py-2 text-body-md text-ink">
                </div>
                <x-ui.button variant="secondary" type="submit">Search</x-ui.button>
            </form>

            @if ($users->isEmpty())
                <x-ui.empty-state title="No users found." />
            @else
                <table class="w-full text-left text-body-sm">
                    <thead class="text-caption uppercase tracking-wide text-steel">
                        <tr>
                            <th scope="col" class="pb-2">Email</th>
                            <th scope="col" class="pb-2">Role</th>
                            <th scope="col" class="pb-2">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline-soft">
                        @foreach ($users as $user)
                            <tr>
                                <td class="py-3 text-ink">{{ $user->email }}</td>
                                <td class="py-3"><x-ui.badge tone="neutral">{{ $user->role }}</x-ui.badge></td>
                                <td class="py-3 text-steel">{{ $user->created_at?->toDayDateTimeString() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
