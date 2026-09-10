<x-layout title="Prywatność" :noindex="true">
    <h1>Prywatność</h1>

    <form class="card" method="POST" action="{{ route('settings.privacy') }}">
        @csrf @method('PUT')
        <label class="choice" for="f-digest">
            <input id="f-digest" type="checkbox" name="wants_weekly_digest" value="1" @checked(auth()->user()->wants_weekly_digest)>
            <span>
                <span class="choice-label">Chcę raz w tygodniu dostawać e-mail z Kuking</span>
                <span class="choice-help">Krótkie podsumowanie: kto ugotował z Twoich przepisów, kto zaczął Cię obserwować i co pokazali ludzie, których obserwujesz. Jeden e-mail tygodniowo, nigdy więcej — i tylko wtedy, gdy naprawdę jest o czym pisać. Wypisać się możesz jednym kliknięciem na dole każdego listu, bez logowania.</span>
            </span>
        </label>

        {{--
            WSPOMNIENIA — WYŁĄCZNIK, KTÓRY MUSI BYĆ ŁATWY DO ZNALEZIENIA (issue #34).

            To nie jest ustawienie wygody. Wpis z przepisem po mamie, która
            zmarła w tym roku, wyświetlony bez ostrzeżenia na stronie głównej,
            jest okrutny. Człowiek w żałobie ma to wyłączyć jednym kliknięciem,
            a nie odklikiwać wspomnienia po kolei.

            Domyślnie włączone: funkcja, którą trzeba najpierw włączyć, nie
            istnieje dla nikogo poza tym, kto o niej wie — a to jest mechanika
            dla osoby gotującej od czterdziestu lat, nie dla osoby, która czyta
            ustawienia.
        --}}
        <label class="choice mt-4" for="f-wspomnienia">
            <input id="f-wspomnienia" type="checkbox" name="memories_enabled" value="1" @checked(auth()->user()->memories_enabled)>
            <span>
                <span class="choice-label">Przypominaj mi, co gotowałam w tym dniu w poprzednich latach</span>
                <span class="choice-help">Na stronie głównej pojawia się wtedy jeden Twój dawny wpis z tego samego dnia. Możesz to wyłączyć w każdej chwili — a pojedyncze wspomnienie schować przyciskiem przy nim.</span>
            </span>
        </label>

        <button class="btn btn-primary mt-4" type="submit">Zapisz</button>
    </form>

    <section class="mt-8">
        <h2>Zablokowane osoby</h2>
        @if($blocked->isEmpty())
            <p class="meta">Nikogo nie zablokowałaś.</p>
        @else
            <p>Te osoby nie widzą Twoich treści, a Ty nie widzisz ich.</p>
            <div class="stack-tight">
                @foreach($blocked as $person)
                    <div class="card flex items-center gap-3 flex-wrap">
                        <x-avatar :user="$person" :size="44" />
                        <span class="flex-1">{{ $person->displayName() }}</span>
                        <form method="POST" action="{{ route('social.unblock', $person->profile->username) }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-secondary" type="submit">Zdejmij blokadę</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mt-8">
        <h2>Kto widzi Twoje treści</h2>
        <p>
            Przy każdym wpisie i przepisie sama decydujesz: wszyscy, tylko osoby które Cię obserwują,
            albo tylko Ty. Możesz to zmienić w każdej chwili.
        </p>
    </section>

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="privacy" />
    </x-slot:rail>
</x-layout>
