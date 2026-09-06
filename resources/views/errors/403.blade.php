{{--
    403 — jest taka strona, ale nie dla tej osoby (issue #81).

    Ważne, żeby nie brzmiało jak oskarżenie. Najczęstszy powód nie jest
    żadnym włamaniem: ktoś ustawił wpis na „tylko ja", ktoś inny zablokował
    tę osobę, albo link jest do panelu moderacji.
--}}
<x-layout title="Ta strona nie jest dla Ciebie" :noindex="true">
    <h1>Ta strona nie jest dla Ciebie</h1>

    <p class="mb-5">
        Ta treść jest dostępna tylko dla wybranych osób. Autor mógł ją schować
        albo udostępnić wyłącznie tym, którzy go obserwują.
    </p>

    <p class="mb-5">
        Jeśli spodziewasz się tu czegoś swojego, sprawdź, czy to na pewno
        to konto, na którym zwykle gotujesz.
    </p>

    <div class="form-actions">
        <a class="btn btn-primary" href="{{ auth()->check() ? route('home') : route('landing') }}">Strona główna</a>
        @guest
            <a class="btn btn-secondary" href="{{ route('login') }}">Zaloguj się</a>
        @endguest
        <a class="btn btn-quiet" href="{{ route('help') }}">Pomoc</a>
    </div>
</x-layout>
