{{--
    Ustawienia → „Ukryte" (issue #1810, D-278).

    Lista jawnych poleceń widza z drogą do cofnięcia (AGENTS.md §8): osobno
    wpisy i osoby, przy każdym data końca, „Zostaw ukryte" (bez terminu)
    i „Przywróć". Wszystko to są zwykłe formularze — bez JavaScriptu, cele
    48 px (`.btn`). Nazwa konta dosłownie, po dwukropku.
--}}
<x-layout title="Ukryte" :noindex="true">
    <h1>Ukryte</h1>

    <p>To, co tu jest, ukrywasz tylko dla siebie. Inni widzą te wpisy i osoby jak dotąd,
        a nikt nie dostaje o tym powiadomienia.</p>

    @if($ostrzezenie)
        <p class="notice">Ukrywasz już co najmniej jedną trzecią osób, które ostatnio coś pokazały.
            Może część z nich warto przywrócić.</p>
    @endif

    <section class="mt-8" id="ukryte-wpisy">
        <h2>Ukryte wpisy</h2>
        @if($wpisy->isEmpty())
            <p class="meta">Nie ukrywasz żadnego wpisu.</p>
        @else
            <div class="stack-tight">
                @foreach($wpisy as $ukrycie)
                    <div class="card stack-tight" data-ukrycie="wpis">
                        <p class="m-0">
                            @php $poczatek = \Illuminate\Support\Str::limit(trim((string) $ukrycie->post->body), 80); @endphp
                            <a href="{{ route('posts.show', $ukrycie->post) }}?pokaz=1">{{ $poczatek !== '' ? $poczatek : 'Wpis bez opisu' }}</a>
                            · Autor: {{ $ukrycie->post->author->displayName() }}
                        </p>
                        <p class="meta m-0">
                            @if($ukrycie->naStale())
                                Ukryte, dopóki nie przywrócisz.
                            @else
                                Ukryte do {{ \App\Support\Czas::data($ukrycie->hidden_until, 'j F Y') }}. Potem wróci samo.
                            @endif
                        </p>
                        <div class="flex flex-wrap gap-3">
                            @unless($ukrycie->naStale())
                                <form method="POST" action="{{ route('settings.hidden.keep', $ukrycie) }}">
                                    @csrf @method('PATCH')
                                    <button class="btn btn-secondary" type="submit">Zostaw ukryte</button>
                                </form>
                            @endunless
                            <form method="POST" action="{{ route('settings.hidden.restore', $ukrycie) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-secondary" type="submit">Przywróć</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mt-8" id="ukryte-osoby">
        <h2>Ukryte osoby</h2>
        <p>Ich wpisów nie widzisz w „Świeżo z <x-kuking-word />”, na tablicy na dziś ani wśród propozycji osób.
            Profil, wyszukiwarka i wpisy pod linkiem działają jak dotąd.</p>
        @if($osoby->isEmpty())
            <p class="meta">Nie ukrywasz nikogo.</p>
        @else
            <div class="stack-tight">
                @foreach($osoby as $ukrycie)
                    <div class="card stack-tight" data-ukrycie="osoba">
                        <p class="m-0">Osoba: <a href="{{ route('profile.show', $ukrycie->hiddenUser->profile->username) }}">{{ $ukrycie->hiddenUser->displayName() }}</a></p>
                        <p class="meta m-0">
                            @if($ukrycie->naStale())
                                Ukryta, dopóki nie przywrócisz.
                            @else
                                Ukryta do {{ \App\Support\Czas::data($ukrycie->hidden_until, 'j F Y') }}. Potem wróci sama.
                            @endif
                        </p>
                        <div class="flex flex-wrap gap-3">
                            @unless($ukrycie->naStale())
                                <form method="POST" action="{{ route('settings.hidden.keep', $ukrycie) }}">
                                    @csrf @method('PATCH')
                                    <button class="btn btn-secondary" type="submit">Zostaw ukryte</button>
                                </form>
                            @endunless
                            <form method="POST" action="{{ route('settings.hidden.restore', $ukrycie) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-secondary" type="submit">Przywróć</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <p class="mt-8">Zablokowane osoby są osobno, w ustawieniach <a href="{{ route('settings.privacy') }}#zablokowane">Prywatność</a>.</p>

    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="hidden" />
    </x-slot:rail>
</x-layout>
