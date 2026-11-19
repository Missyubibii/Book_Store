<div {{ $attributes->merge(['class' => 'bs-ad-table-wrap bs-ad-desktop-table']) }}>
    <table class="bs-ad-table">
        @isset($head)
            <thead>
                {{ $head }}
            </thead>
        @endisset
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
