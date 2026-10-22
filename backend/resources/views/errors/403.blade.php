<x-layouts.guest :title="'Forbidden'">
    <h2 class="text-heading-4 font-semibold text-ink">Forbidden</h2>
    <p class="mt-2 text-body-sm text-steel">Your role does not have access to this page.</p>
    <div class="mt-6">
        <x-ui.button variant="primary" :href="route('home')">Go to my dashboard</x-ui.button>
    </div>
</x-layouts.guest>
