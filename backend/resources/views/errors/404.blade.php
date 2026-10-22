<x-layouts.guest :title="'Not found'">
    <h2 class="text-heading-4 font-semibold text-ink">Not found</h2>
    <p class="mt-2 text-body-sm text-steel">That page or record does not exist.</p>
    <div class="mt-6">
        <x-ui.button variant="primary" :href="route('home')">Go to my dashboard</x-ui.button>
    </div>
</x-layouts.guest>
