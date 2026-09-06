{{--
    Wpisy bez odpowiedzi (issue #6).

    NAJTWARDSZA LICZBA Z CAŁEGO RESEARCHU
    55% osób 55-64 i 62% osób 65+ w mediach społecznościowych to WYŁĄCZNIE
    odbiorcy treści. Kto opublikuje, robi to wbrew własnemu nawykowi — a wpis
    bez żadnej reakcji to koniec: ta osoba już nie wróci.

    Ten ekran nie jest panelem statystyk. Jest listą rzeczy do zrobienia
    dzisiaj: najstarsze na górze, pierwsze wpisy jeszcze wyżej, odpowiedź
    wprost z listy. Ma zająć kilkanaście minut, nie godzinę.
--}}
<x-layout title="Wpisy bez odpowiedzi" :noindex="true">
    <h1>Wpisy bez odpowiedzi</h1>

    @if($wpisy->isEmpty())
        <x-empty-state title="Nikt nie czeka">
            <p style="margin-bottom:0;">
                Każdy opublikowany wpis ma odpowiedź. To jest dokładnie ten stan,
                o który chodzi.
            </p>
        </x-empty-state>
    @else
        <div class="notice">
            <strong>{{ $wpisy->count() }} {{ \App\Support\Odmiana::rzeczownik($wpisy->count(), 'wpis czeka', 'wpisy czekają', 'wpisów czeka') }} na odpowiedź.</strong>
            Najstarszy czeka {{ $najstarszy }} {{ \App\Support\Odmiana::rzeczownik((int) $najstarszy, 'godzinę', 'godziny', 'godzin') }}.
            @if($medianaReakcji !== null)
                Zwykle odpowiadamy po {{ str_replace('.', ',', (string) $medianaReakcji) }} h
                (mediana z ostatnich 30 dni).
            @endif
        </div>
    @endif

    <div class="stack">
        @foreach($wpisy as $wpis)
            <article class="card czeka czeka-{{ $wpis->pilnosc }}">
                <p class="meta">
                    @if($wpis->toPierwszyWpis)
                        {{-- Najważniejszy wiersz na tej liście. Pierwszy wpis
                             to jedyna okazja, żeby ktoś poczuł, że jest tu
                             ktoś po drugiej stronie. --}}
                        <strong class="czeka-pierwszy">Pierwszy wpis tej osoby</strong> ·
                    @endif
                    <a href="{{ route('profile.show', $wpis->author->profile->username) }}">
                        {{ $wpis->author->displayName() }}
                    </a>
                    ·
                    <time datetime="{{ $wpis->published_at->toIso8601String() }}">
                        czeka {{ $wpis->godzinCzekania }}
                        {{ \App\Support\Odmiana::rzeczownik($wpis->godzinCzekania, 'godzinę', 'godziny', 'godzin') }}
                    </time>
                    @if($wpis->topic)
                        · <a href="{{ route('topics.show', $wpis->topic) }}">{{ $wpis->topic->name }}</a>
                    @endif
                </p>

                @if($wpis->body)
                    <p>{{ \Illuminate\Support\Str::limit($wpis->body, 400) }}</p>
                @endif

                <x-photo :media="$wpis->media->first()" variant="thumb" :zoom="false" />

                <p class="meta">
                    <a href="{{ route('posts.show', $wpis) }}">Otwórz wpis</a>
                </p>

                {{-- Odpowiedź wprost z listy. Wejście we wpis i powrót przy
                     dwudziestu pozycjach to dwadzieścia przeładowań strony —
                     playbook przestaje być wykonalny. --}}
                <form method="POST" action="{{ route('admin.unanswered.reply', $wpis) }}">
                    @csrf
                    <div class="field">
                        <label for="odp-{{ $wpis->getKey() }}">Odpowiedz</label>
                        <textarea class="field-input" id="odp-{{ $wpis->getKey() }}"
                                  name="body" rows="3" required></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Wyślij odpowiedź</button>
                </form>
            </article>
        @endforeach
    </div>
</x-layout>
