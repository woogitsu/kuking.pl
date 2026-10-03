{{--
    „Odzyskaj wcześniejszy tekst szkicu” (#2512, V2, D-333). Podgląd różnicy
    między bieżącym tekstem prywatnego szkicu a jego kopią zrobioną przy
    otwarciu szkicu do pisania. Ten ekran niczego nie zmienia — zmienia dopiero
    osobny przycisk „Przywróć tekst z kopii” (zwykły POST, bez JavaScriptu).

    Kopia zawiera tekst, nie zdjęcia. Przywrócenie nie publikuje, nie tworzy
    wykonania ani powiadomienia, a zastąpiony tekst zostaje jako kopia.
--}}
<x-layout title="Wcześniejszy tekst szkicu" :noindex="true">
    <div class="stack kolumna-czytania">
        <p class="mb-0"><a href="{{ route('recipes.create', ['szkic' => $szkic->getKey()]) }}">Wróć do szkicu</a></p>

        <h1>Wcześniejszy tekst szkicu</h1>

        <p>
            Szkic „{{ $szkic->title }}” zapisuje się sam, więc pomyłka też mogła się zapisać.
            Poniżej widzisz tekst z kopii zrobionej {{ \App\Support\Czas::data($punkt->taken_at, 'j F Y, H:i') }}
            i to, czym różni się od tekstu bieżącego. Kopię przechowujemy do
            {{ \App\Support\Czas::data($wygasa, 'j F Y, H:i') }}. Widzisz ją tylko Ty.
        </p>

        <p class="notice">
            Kopia zawiera tekst: nazwę, opis, składniki, kroki, minutniki i pochodzenie. Nie zawiera zdjęć.
            Przywrócenie niczego nie publikuje. Tekst, który zostanie zastąpiony, zostawiamy jako kopię,
            więc możesz do niego wrócić tym samym przyciskiem.
        </p>

        @if(! $rozni)
            <p data-brak-roznic><strong>Kopia jest taka sama jak bieżący tekst.</strong> Nie ma czego przywracać.</p>
        @else
            @if($pola !== [])
                <section aria-labelledby="odzyskanie-pola" data-roznice-pol>
                    <h2 id="odzyskanie-pola">Pola, które się różnią</h2>
                    <ul class="stack list-none p-0 m-0">
                        @foreach($pola as $pole)
                            <li class="card">
                                <p class="font-bold m-0">{{ $pole['etykieta'] }}</p>
                                <p class="m-0 mt-2"><span class="meta">Teraz:</span> {{ $pole['teraz'] }}</p>
                                <p class="m-0 mt-2"><span class="meta">W kopii:</span> {{ $pole['kopia'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if($zmieniaSkladniki)
                <section aria-labelledby="odzyskanie-skladniki" data-roznice-skladnikow>
                    <h2 id="odzyskanie-skladniki">Składniki</h2>
                    <h3>W kopii ({{ count($skladnikiKopia) }})</h3>
                    <ol class="stack-tight">
                        @forelse($skladnikiKopia as $wiersz)
                            <li>{{ $wiersz }}</li>
                        @empty
                            <li class="list-none">Brak składników.</li>
                        @endforelse
                    </ol>
                    <h3>Teraz ({{ count($skladnikiTeraz) }})</h3>
                    <ol class="stack-tight">
                        @forelse($skladnikiTeraz as $wiersz)
                            <li>{{ $wiersz }}</li>
                        @empty
                            <li class="list-none">Brak składników.</li>
                        @endforelse
                    </ol>
                </section>
            @endif

            @if($zmieniaKroki)
                <section aria-labelledby="odzyskanie-kroki" data-roznice-krokow>
                    <h2 id="odzyskanie-kroki">Kroki przygotowania</h2>
                    <h3>W kopii ({{ count($krokiKopia) }})</h3>
                    <ol class="stack-tight">
                        @forelse($krokiKopia as $wiersz)
                            <li>{{ $wiersz }}</li>
                        @empty
                            <li class="list-none">Brak kroków.</li>
                        @endforelse
                    </ol>
                    <h3>Teraz ({{ count($krokiTeraz) }})</h3>
                    <ol class="stack-tight">
                        @forelse($krokiTeraz as $wiersz)
                            <li>{{ $wiersz }}</li>
                        @empty
                            <li class="list-none">Brak kroków.</li>
                        @endforelse
                    </ol>
                </section>
            @endif

            @if($zdjeciaBlokuja)
                <p class="notice" data-zdjecia-blokuja>
                    W szkicu jest zdjęcie kroku, którego nie ma w kopii. Przywrócenie musiałoby je odpiąć, więc go nie umożliwiamy.
                    Usuń to zdjęcie w kreatorze, jeśli chcesz wrócić do kopii, albo zostań przy bieżącym tekście.
                </p>
            @else
                <form method="POST" action="{{ route('recipes.drafts.restore', $szkic->getKey()) }}" novalidate>
                    @csrf
                    <input type="hidden" name="rewizja" value="{{ $rewizja }}">
                    <input type="hidden" name="znacznik" value="{{ $znacznik }}">
                    <button class="btn btn-primary" type="submit">Przywróć tekst z kopii</button>
                </form>
            @endif
        @endif

        <p><a class="btn btn-secondary" href="{{ route('recipes.create', ['szkic' => $szkic->getKey()]) }}">Zostaw bieżący tekst i wróć do szkicu</a></p>
    </div>
</x-layout>
