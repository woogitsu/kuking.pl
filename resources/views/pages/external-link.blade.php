<x-layout title="Opuszczasz Kuking" :noindex="true">
    <section class="card miara mx-auto">
        <h1>Opuszczasz Kuking</h1>
        <p>Strona docelowa: <strong class="link-w-tresci">{{ $domena }}</strong></p>
        <p>Kuking nie sprawdza bezpieczeństwa stron podanych przez użytkowników. Sprawdź adres, zanim podasz hasło, dane osobowe lub dane karty.</p>
        <p class="link-w-tresci">{{ $adres }}</p>
        <div class="flex flex-wrap gap-4">
            <a class="btn btn-primary" href="{{ $adres }}" rel="nofollow ugc noopener noreferrer" referrerpolicy="no-referrer">Przejdź do strony</a>
            <a class="btn btn-secondary" href="{{ route('landing') }}">Zostań w Kuking</a>
        </div>
    </section>
</x-layout>
