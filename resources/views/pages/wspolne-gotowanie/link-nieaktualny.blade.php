<x-layout title="Nie można dołączyć do sesji" :noindex="true">
    {{-- Token nieznany, wygasły, odwołany, zużyty, brak dostępu do przepisu,
         blokada, zamknięte konto — jedno zdanie dla wszystkich (#2385). Adres
         linku nie jest wyrocznią: nie mówimy, który z przypadków zaszedł. --}}
    <div class="stack max-w-[38rem] mx-auto">
        <h1>Nie możesz dołączyć do tej sesji</h1>
        <p>{{ \App\Domain\Recipes\Gotowanie\Wspolne\ZaproszenieDoGotowania::NIEAKTUALNE }}</p>
        <a class="btn btn-primary" href="{{ route('home') }}">Wróć na Start</a>
    </div>
</x-layout>
