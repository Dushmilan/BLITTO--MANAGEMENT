<x-layouts.app :title="$application->title">
    <div class="space-y-6">
        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-heading-3 font-semibold text-ink">{{ $application->title }}</h1>
                    <p class="mt-1 font-mono text-caption text-steel">
                        Reference: {{ $application->application_number ?: substr($application->id, 0, 8) }}
                    </p>
                    <p class="mt-1 text-body-sm text-steel">{{ $application->technology_area ?: 'No technology area' }}</p>
                </div>

                <x-ui.status-badge :status="$application->status" />
            </div>
        </x-ui.card>

        @if ($granted)
            <x-ui.card title="Patent document">
                @if ($hasDocument)
                    <x-ui.button variant="accent" :href="route('documents.granted-download', $application)">
                        Download patent document
                    </x-ui.button>
                @elseif ($openRequest)
                    <p class="text-body-sm text-steel">
                        You have already requested this document; we will notify you when it is ready.
                    </p>
                @elseif ($canRequest)
                    <p class="text-body-sm text-steel">
                        Your patent is granted but the document is still waiting for BLITTO to upload.
                    </p>
                    <form method="POST" name="request-document" action="{{ route('documents.request', $application) }}" class="mt-4">
                        @csrf
                        <x-ui.button variant="primary" type="submit">Request document</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        @endif

        <x-ui.card title="Documents">
            @if ($documents->isEmpty())
                <x-ui.empty-state title="No documents available yet." />
            @else
                <ul class="divide-y divide-hairline-soft text-body-sm">
                    @foreach ($documents as $document)
                        <li class="flex items-center justify-between py-3">
                            <span class="text-ink">{{ $document->filename }}</span>
                            <span class="text-steel">{{ number_format($document->file_size / 1024, 1) }} KB</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="Progress">
            <x-ui.timeline :entries="$history" />
        </x-ui.card>
    </div>
</x-layouts.app>
