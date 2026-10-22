@if (session('status'))
    <x-ui.toast tone="success">{{ session('status') }}</x-ui.toast>
@endif

@if (session('warning'))
    <x-ui.toast tone="warning">{{ session('warning') }}</x-ui.toast>
@endif

@if ($errors->has('form'))
    <x-ui.toast tone="danger">{{ $errors->first('form') }}</x-ui.toast>
@endif
