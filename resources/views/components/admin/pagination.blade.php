@props([
    'paginator',
])

@if ($paginator && $paginator->hasPages())
    <div {{ $attributes->merge(['class' => 'bs-ad-pagination-wrap']) }}>
        {{ $paginator->withQueryString()->links() }}
    </div>
@endif
