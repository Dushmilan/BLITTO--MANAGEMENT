<x-layouts.guest :title="'Session expired'">
    <h2 class="text-heading-4 font-semibold text-ink">Session expired</h2>
    <p class="mt-2 text-body-sm text-steel">For your security the form expired. Please try again.</p>
    <div class="mt-6">
        <x-ui.button variant="primary" :href="route('home')">Go to my dashboard</x-ui.button>
    </div>
</x-layouts.guest>
