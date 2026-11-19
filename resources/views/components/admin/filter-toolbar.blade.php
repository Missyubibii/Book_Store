@props([
    'action',
    'method' => 'GET',
])

<form
    method="{{ $method }}"
    action="{{ $action }}"
    {{ $attributes->merge(['class' => 'bs-ad-compact-filter-bar', 'role' => 'search']) }}
>
    {{ $slot }}

    @isset($actions)
        <div class="bs-ad-compact-filter-actions">
            {{ $actions }}
        </div>
    @endisset
</form>
