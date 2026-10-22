<x-layouts.guest :title="'Something went wrong'">
    <h2 class="text-heading-4 font-semibold text-ink">Something went wrong</h2>
    <p class="mt-2 text-body-sm text-steel">The error has been logged. Please try again.</p>
    <div class="mt-6">
        <x-ui.button variant="primary" :href="route('home')">Go to my dashboard</x-ui.button>
    </div>
</x-layouts.guest>
