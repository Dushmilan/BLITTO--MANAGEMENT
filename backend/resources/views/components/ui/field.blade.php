@props(['label', 'name', 'hint' => null, 'type' => 'text', 'value' => null, 'required' => false])

@php
    $key = rtrim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id = \Illuminate\Support\Str::slug($name);
@endphp

<div class="mb-4">
    <label for="{{ $id }}" class="mb-1 block text-body-sm font-medium text-ink">
        {{ $label }}
        @if ($required)
            <span class="text-brand-error" aria-hidden="true">*</span>
        @endif
    </label>

    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($key, $value) }}"
        @if ($required) required @endif
        aria-describedby="{{ $id }}-error"
        @error($key) aria-invalid="true" @enderror
        class="w-full rounded-input border bg-canvas px-3 py-2 text-body-md text-ink @error($key) border-brand-error @else border-hairline @enderror"
    >

    @if ($hint)
        <p class="mt-1 text-caption text-steel">{{ $hint }}</p>
    @endif

    @error($key)
        <p id="{{ $id }}-error" class="mt-1 text-caption text-brand-error">{{ $message }}</p>
    @enderror
</div>
