@props(['status'])

@php
    $value = (string) $status;

    $tone = match (true) {
        $value === 'GRANTED' => 'success',
        $value === 'REJECTED' => 'danger',
        str_starts_with($value, 'DEFECT_SHEET') => 'warning',
        $value === 'DRAFT' => 'neutral',
        default => 'info',
    };

    $label = $value === 'DRAFT'
        ? 'Draft'
        : ucfirst(strtolower(str_replace('_', ' ', $value)));
@endphp

<x-ui.badge :tone="$tone">{{ $label }}</x-ui.badge>
