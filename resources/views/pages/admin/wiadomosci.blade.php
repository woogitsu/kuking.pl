{{--
    Kolejka wiadomości z „Napisz do nas" — panel operatora.

    OSOBNY EKRAN OD `/admin/zgloszenia` I TO JEST CAŁA JEGO TREŚĆ.
    Tam sprawy moderacyjne z decyzją i prawem do odwołania (DSA art. 20),
    tutaj zdania w rodzaju „nie mogę wgrać zdjęcia z telefonu". Zmieszanie
    ich w jednej liście zapchałoby najważniejszą kolejkę w serwisie.

    NAJSTARSZE NA GÓRZE, odwrotnie niż w zgłoszeniach — patrz komentarz
    w `WiadomosciController::index()`.

    LISTA POKAZUJE POCZĄTEK TREŚCI, NIE CAŁOŚĆ. Wiadomość bywa długa,
    a kolejka ma odpowiadać na pytanie „co jest do zrobienia", nie zastępować
    czytania. Całość jest na ekranie wiadomości, który przechodzi przez
    `ContactMessagePolicy::view()`.
--}}
<x-layout title="Wiadomości do nas — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Wiadomości do nas" />

    <h1>Wiadomości do nas</h1>

    <p class="meta">
        To nie są zgłoszenia treści. Skargi na cudze wpisy są w
        <a href="{{ route('admin.reports') }}">Zgłoszeniach</a>.
    </p>

    <nav class="tabs" aria-label="Filtr wiadomości">
        @foreach(\App\Models\ContactMessage::STATUSY as $wartosc => $etykieta)
            <a class="tab" href="{{ route('admin.contact', ['status' => $wartosc]) }}"
               @if($status === $wartosc) aria-current="page" @endif>{{ $etykieta }} ({{ $liczniki[$wartosc] }})</a>
        @endforeach
        <a class="tab" href="{{ route('admin.contact', ['status' => 'wszystkie']) }}"
           @if($status === 'wszystkie') aria-current="page" @endif>Wszystkie</a>
    </nav>

    @forelse($wiadomosci as $wiadomosc)
        <article class="card mb-5">
            <h2 class="mt-0 text-title-sm">{{ $wiadomosc->rodzajLabel() }}</h2>

            <p class="meta">
                {{ \App\Support\Czas::data($wiadomosc->created_at, 'j F Y, H:i') }} ·
                {{ $wiadomosc->statusLabel() }}
                @if($wiadomosc->author)
                    · od {{ $wiadomosc->author->displayName() }}
                @elseif($wiadomosc->contact_email)
                    · bez konta
                @else
                    · bez konta i bez adresu do odpowiedzi
                @endif
            </p>

            <p class="whitespace-pre-line">{{ \Illuminate\Support\Str::limit($wiadomosc->message, 400) }}</p>

            <p class="mb-0">
                <a class="btn btn-secondary" href="{{ route('admin.contact.show', $wiadomosc) }}">
                    Otwórz wiadomość
                </a>
            </p>
        </article>
    @empty
        {{-- `<x-empty-state>`, nie akapit — ten sam komponent co w „Tagach
             promowanych" i w „Użytkownikach" (issue #367). Bez `action`:
             pusta kolejka wiadomości nie ma czego zaproponować, a martwy
             przycisk jest zakazany (D-053). --}}
        <x-empty-state :title="$status === \App\Models\ContactMessage::STATUS_NOWA ? 'Nic nowego' : 'Tu nic nie ma'">
            @if($status === \App\Models\ContactMessage::STATUS_NOWA)
                Wszystko, co przyszło, jest już w robocie albo załatwione.
            @endif
        </x-empty-state>
    @endforelse

    <div class="mt-6"><x-paginacja-panelu :paginator="$wiadomosci" /></div>
</x-layout>
