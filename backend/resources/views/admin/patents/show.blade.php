<x-layouts.app :title="$application->title">
    <div class="space-y-6">
        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-heading-3 font-semibold text-ink">{{ $application->title }}</h1>
                    <p class="mt-1 font-mono text-caption text-steel">
                        Reference: {{ $application->application_number ?: substr($application->id, 0, 8) }}
                    </p>
                    <p class="mt-1 text-body-sm text-steel">
                        {{ $application->inventor_name ?: 'No inventor recorded' }}
                        &middot; {{ $application->technology_area ?: 'No technology area' }}
                    </p>
                </div>

                <x-ui.status-badge :status="$application->status" />
            </div>

            <p class="mt-4 text-caption text-steel">
                Created {{ $application->created_at?->toDayDateTimeString() }}
                &middot; Updated {{ $application->updated_at?->toDayDateTimeString() }}
            </p>
        </x-ui.card>

        @if ($errors->any())
            <x-ui.toast tone="danger">{{ $errors->first() }}</x-ui.toast>
        @endif

        <x-ui.card title="Patent details">
            <form method="POST" action="{{ route('applications.update', $application) }}">
                @csrf
                @method('PATCH')

                <x-ui.field label="Title" name="title" :value="$application->title" required />
                <x-ui.field label="Technology area" name="technology_area" :value="$application->technology_area" />

                <x-ui.button variant="secondary" type="submit">Save details</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Status change" subtitle="Every change needs a reason; it is recorded in the history.">
            <form method="POST" action="{{ route('applications.status', $application) }}">
                @csrf

                <div class="mb-4">
                    <label for="new_status" class="mb-1 block text-body-sm font-medium text-ink">New status</label>
                    <select id="new_status" name="new_status" class="w-full rounded-input border border-hairline bg-canvas px-3 py-2 text-body-md text-ink">
                        @foreach ($statuses as $case)
                            <option value="{{ $case->value }}" @selected($application->status === $case->value)>{{ $case->value }}</option>
                        @endforeach
                    </select>
                    @error('new_status')
                        <p class="mt-1 text-caption text-brand-error">{{ $message }}</p>
                    @enderror
                </div>

                <x-ui.field label="Reason" name="reason" required hint="Visible to everyone with access to this patent." />

                <x-ui.button variant="primary" type="submit">Change status</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Status history">
            <x-ui.timeline :entries="$history" />
        </x-ui.card>
    </div>
</x-layouts.app>
