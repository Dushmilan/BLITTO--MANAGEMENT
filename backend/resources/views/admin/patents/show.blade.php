@php($formatDate = fn ($value) => $value === null ? null : \Illuminate\Support\Carbon::parse($value)->toDayDateTimeString())

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

        <x-ui.card title="Filing" subtitle="NIPO filing record and the actions available at this stage.">
            <dl class="grid gap-3 text-body-sm sm:grid-cols-3">
                <div>
                    <dt class="text-caption uppercase tracking-wide text-steel">Filed</dt>
                    <dd class="text-ink">{{ $formatDate($filingRecord?->filed_date) ?? 'Not filed' }}</dd>
                </div>
                <div>
                    <dt class="text-caption uppercase tracking-wide text-steel">NIPO acknowledged</dt>
                    <dd class="text-ink">{{ $formatDate($filingRecord?->nipo_acknowledged_at) ?? 'Pending' }}</dd>
                </div>
                <div>
                    <dt class="text-caption uppercase tracking-wide text-steel">Patent number</dt>
                    <dd class="text-ink">{{ $application->application_number ?: '—' }}</dd>
                </div>
            </dl>

            @if ($warning)
                <div class="mt-4">
                    <x-ui.toast tone="warning">{{ $warning }}</x-ui.toast>
                </div>
            @endif

            <div class="mt-6 flex flex-wrap items-center gap-3">
                @if ($filingRecord === null)
                    <form method="POST" action="{{ route('filing.file', $application) }}">
                        @csrf
                        <x-ui.button variant="primary" type="submit">File patent</x-ui.button>
                    </form>
                @elseif ($filingRecord->nipo_acknowledged_at === null)
                    <form method="POST" action="{{ route('filing.acknowledge', $application) }}">
                        @csrf
                        <x-ui.button variant="primary" type="submit">Acknowledge NIPO</x-ui.button>
                    </form>
                @else
                    <form method="POST" action="{{ route('filing.defect-sheets.store', $application) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div>
                            <label for="sheet_number" class="mb-1 block text-body-sm font-medium text-ink">Sheet</label>
                            <select id="sheet_number" name="sheet_number" class="rounded-input border border-hairline bg-canvas px-3 py-2 text-body-md text-ink">
                                @foreach ([1, 2, 3] as $sheet)
                                    <option value="{{ $sheet }}">{{ $sheet }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="min-w-64 flex-1">
                            <label for="description" class="mb-1 block text-body-sm font-medium text-ink">Description</label>
                            <input id="description" name="description" class="w-full rounded-input border border-hairline bg-canvas px-3 py-2 text-body-md text-ink">
                        </div>
                        <x-ui.button variant="secondary" type="submit">Add defect sheet</x-ui.button>
                    </form>
                @endif
            </div>

            @if ($filingRecord?->nipo_acknowledged_at !== null)
                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <form method="POST" action="{{ route('filing.grant', $application) }}" class="rounded-card border border-hairline p-4">
                        @csrf
                        <x-ui.field label="Patent number" name="patent_number" required />
                        <x-ui.button variant="accent" type="submit">Grant patent</x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('filing.reject', $application) }}" class="rounded-card border border-hairline p-4">
                        @csrf
                        <p class="mb-4 text-body-sm text-steel">Rejecting is permanent for this docket.</p>
                        <button
                            type="button"
                            data-dialog-open="#confirm-reject"
                            class="inline-flex items-center rounded-pill bg-brand-error px-5 py-2.5 text-body-sm font-medium text-[#0a0a0a]"
                        >Reject patent</button>

                        <dialog id="confirm-reject" class="rounded-card border border-hairline bg-canvas p-6 text-ink">
                            <p class="text-body-md">Reject this patent?</p>
                            <div class="mt-4 flex gap-3">
                                <x-ui.button variant="danger" type="submit">Reject</x-ui.button>
                                <button type="button" onclick="this.closest('dialog').close()" class="rounded-pill border border-hairline px-5 py-2.5 text-body-sm">Cancel</button>
                            </div>
                        </dialog>
                    </form>
                </div>
            @endif

            @if ($defectSheets->isNotEmpty())
                <ul class="mt-6 divide-y divide-hairline-soft text-body-sm">
                    @foreach ($defectSheets as $sheet)
                        <li class="py-3">
                            <span class="font-medium text-ink">Sheet {{ $sheet->sheet_number }}</span>
                            <span class="text-slate"> — {{ $sheet->description }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="Documents">
            @if ($documents->isEmpty())
                <x-ui.empty-state title="No documents uploaded yet." />
            @else
                <table class="w-full text-left text-body-sm">
                    <thead class="text-caption uppercase tracking-wide text-steel">
                        <tr>
                            <th scope="col" class="pb-2">Filename</th>
                            <th scope="col" class="pb-2">Size</th>
                            <th scope="col" class="pb-2">Uploaded by</th>
                            <th scope="col" class="pb-2">Date</th>
                            <th scope="col" class="pb-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline-soft">
                        @foreach ($documents as $document)
                            <tr>
                                <td class="py-3 text-ink">{{ $document->filename }}</td>
                                <td class="py-3 text-steel">{{ number_format($document->file_size / 1024, 1) }} KB</td>
                                <td class="py-3 text-steel">{{ $document->uploaded_by }}</td>
                                <td class="py-3 text-steel">{{ $formatDate($document->uploaded_at) }}</td>
                                <td class="py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('documents.download', [$application, $document]) }}" class="text-body-sm text-ink underline">Download</a>

                                        @unless ($documentsLocked)
                                            <form method="POST" action="{{ route('documents.destroy', [$application, $document]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-body-sm text-brand-error underline">Delete</button>
                                            </form>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($documentsLocked)
                <p class="mt-4 text-body-sm text-steel">Documents are locked once the application is filed — new files can still be added, existing ones cannot be deleted.</p>
            @endif

            <form method="POST" action="{{ route('documents.store', $application) }}" enctype="multipart/form-data" class="mt-6 flex flex-wrap items-center gap-3">
                @csrf
                <input type="file" name="file" required class="text-body-sm text-ink">
                <x-ui.button variant="secondary" type="submit">Upload document</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Status history">
            <x-ui.timeline :entries="$history" />
        </x-ui.card>
    </div>
</x-layouts.app>
