<x-layouts.guest :title="'Sign in · '.config('app.name')">
    <h2 class="text-heading-4 font-semibold text-ink">Welcome back</h2>
    <p class="mt-1 text-body-sm text-steel">Sign in to access your docket.</p>

    <form method="POST" action="{{ route('auth.login') }}" class="mt-6">
        @csrf

        <x-ui.field label="Email" name="email" type="email" required />
        <x-ui.field label="Password" name="password" type="password" required />

        <x-ui.button variant="primary" type="submit" class="w-full">Sign in</x-ui.button>
    </form>
</x-layouts.guest>
