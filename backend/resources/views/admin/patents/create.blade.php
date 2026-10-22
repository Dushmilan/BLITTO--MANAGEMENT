<x-layouts.app :title="'New patent'">
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <h1 class="text-heading-3 font-semibold text-ink">New patent</h1>
            <p class="mt-1 text-body-sm text-steel">Record a disclosure. The application starts in DRAFT.</p>
        </div>

        @if ($errors->any())
            <x-ui.toast tone="danger">{{ $errors->first() }}</x-ui.toast>
        @endif

        <x-ui.card>
            <form method="POST" action="{{ route('applications.store') }}">
                @csrf

                <x-ui.field label="Title" name="title" required hint="One line that names the invention." />
                <x-ui.field label="Technology area" name="technology_area" hint="Optional." />

                <fieldset class="mt-6">
                    <legend class="mb-2 text-body-sm font-medium text-ink">Inventors</legend>

                    @foreach ([0, 1] as $index)
                        <div class="mb-4 rounded-card border border-hairline-soft p-4">
                            <x-ui.field label="Inventor name" :name="'inventors['.$index.'][inventor_name]'" :required="$index === 0" />
                            <x-ui.field label="Inventor email" :name="'inventors['.$index.'][inventor_email]'" type="email" :required="$index === 0" hint="Must be an institution address." />
                        </div>
                    @endforeach
                </fieldset>

                <div class="flex items-center gap-3">
                    <x-ui.button variant="primary" type="submit">Create patent</x-ui.button>
                    <x-ui.button variant="ghost" :href="route('admin.patents.index')">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
