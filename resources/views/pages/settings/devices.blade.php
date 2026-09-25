{{--
    „Urządzenia z dostępem" (D-270) — telefony i tablety zalogowane
    w aplikacji mobilnej Kuking.

    Odpowiada na dwa pytania, w tej kolejności:
      1. Gdzie jestem zalogowany w aplikacji?   (nazwa, od kiedy, kiedy ostatnio)
      2. Jak odciąć urządzenie, którego nie mam w ręku?

    Bez JavaScriptu: odwołanie to zwykły formularz, a potwierdzenie daje
    `<x-confirm-button>` na `<details>`. Obie akcje destrukcyjne stoją
    w `.danger-zone`, odsunięte od reszty (AGENTS.md §5).
--}}
<x-layout title="Urządzenia z dostępem" :noindex="true">
    <h1>Urządzenia z dostępem</h1>

    <p class="mb-5">
        Tu widać telefony i tablety, na których ktoś zalogował się na to konto
        w aplikacji Kuking. Jeśli któregoś nie rozpoznajesz albo urządzenie
        zginęło — odetnij je tutaj. Przeglądarki na tej liście nie ma: tę
        wylogujesz na ekranie „Bezpieczeństwo".
    </p>

    <x-error-summary />

    @if($urzadzenia->isEmpty())
        <x-empty-state title="Żadne urządzenie nie ma dostępu">
            Konto nie jest teraz zalogowane w aplikacji na żadnym telefonie ani tablecie.
        </x-empty-state>
    @else
        <ul class="lista-urzadzen">
            @foreach($urzadzenia as $urzadzenie)
                <li class="sekcja-strony">
                    <h2 class="mt-0">{{ $urzadzenie->name }}</h2>
                    <p>
                        Zalogowane {{ \App\Support\Czas::dataLubNic($urzadzenie->created_at) }}.
                        @if($urzadzenie->last_used_at !== null)
                            Ostatnio używane {{ \App\Support\Czas::data($urzadzenie->last_used_at, 'j F Y, H:i') }}.
                        @else
                            Jeszcze nieużywane.
                        @endif
                    </p>

                    <div class="danger-zone">
                        <x-confirm-button
                            :action="route('settings.devices.destroy', $urzadzenie)"
                            label="Odetnij to urządzenie"
                            :question="'Odciąć „'.$urzadzenie->name.'”? Aplikacja na tym urządzeniu od razu przestanie mieć dostęp do konta.'"
                        />
                    </div>
                </li>
            @endforeach
        </ul>

        @if($urzadzenia->count() > 1)
            <section class="sekcja-strony mt-8">
                <h2 class="mt-0">Odetnij wszystkie naraz</h2>
                <p>
                    Użyj tego, gdy nie wiesz, które urządzenie jest które. Na każdym
                    trzeba będzie potem zalogować się w aplikacji jeszcze raz.
                </p>
                <div class="danger-zone">
                    <x-confirm-button
                        :action="route('settings.devices.destroy-all')"
                        label="Odetnij wszystkie urządzenia"
                        question="Odciąć wszystkie urządzenia z listy? Aplikacja na każdym z nich od razu przestanie mieć dostęp do konta."
                    />
                </div>
            </section>
        @endif
    @endif

    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="devices" />
    </x-slot:rail>
</x-layout>
