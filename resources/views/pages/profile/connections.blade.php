{{--
    Lista relacji: obserwujący albo obserwowani danego profilu.

    Wspólny widok dla dwóch tras (/@{username}/obserwujacy i /obserwowani) —
    różni je tylko $relation i $title. Strona jest pomocnicza, nie treścią
    do wyszukiwarki, stąd :noindex="true" (nagłówek X-Robots-Tag ustawia
    kontroler, meta tag ustawia layout).
--}}
@php
    $viewer = auth()->user();
    $isFollowersList = $relation === 'followers';
@endphp
<x-layout :title="$title.' — '.$profile->display_name" :noindex="true">
    <p><a class="btn btn-quiet" href="{{ $profile->url() }}">&larr; Wróć do profilu {{ $profile->display_name }}</a></p>

    <h1>
        @if($isFollowersList)
            Kto obserwuje: {{ $profile->display_name }}
        @else
            Kogo obserwuje: {{ $profile->display_name }}
        @endif
    </h1>

    @if($people->count() === 0)
        <x-empty-state :title="$isFollowersList ? 'Jeszcze nikt nie obserwuje' : 'Jeszcze nikogo nie obserwuje'">
            @if($isFollowersList)
                Kiedy ktoś zacznie obserwować {{ $profile->display_name }}, pojawi się tutaj.
            @else
                Kiedy {{ $profile->display_name }} zacznie kogoś obserwować, ta osoba pojawi się tutaj.
            @endif
        </x-empty-state>
    @else
        <div class="stack">
            @foreach($people as $person)
                @php
                    $personUsername = $person->profile?->username;
                    $isSelf = $viewer !== null && $viewer->getKey() === $person->getKey();
                    // `obserwowany` policzył `withExists` w kontrolerze — jednym
                    // zapytaniem dla całej strony. Wcześniej było tu `isFollowing()`,
                    // czyli osobny `SELECT EXISTS` na każdy wiersz listy.
                    $isFollowingPerson = $viewer !== null && ! $isSelf && (bool) ($person->obserwowany ?? false);
                @endphp
                <div class="card flex gap-3 items-center justify-between flex-wrap">
                    <a href="{{ $person->profile?->url() }}" class="osoba-link">
                        <x-avatar :user="$person" :size="56" />
                        <span>
                            <span class="block font-semibold">{{ $person->displayName() }}</span>
                            @if($personUsername)
                                <span class="meta">&#64;{{ $personUsername }}</span>
                            @endif
                        </span>
                    </a>

                    @if($isSelf)
                        <span class="badge">To Ty</span>
                    @elseif($viewer !== null)
                        @if($isFollowingPerson && $viewer->can('unfollow', $person))
                            <form method="POST" action="{{ route('social.unfollow', $personUsername) }}">
                                @csrf @method('DELETE')
                                {{-- #793 rozszerzone na relacje: lista bywa
                                     otwarta długo, a nazwa w adresie mogła
                                     w międzyczasie zmienić właściciela. --}}
                                <input type="hidden" name="oczekiwany_id" value="{{ $person->getKey() }}">
                                <button class="btn btn-secondary" type="submit">Przestań obserwować</button>
                            </form>
                        {{--
                            WARUNKI `UserPolicy::follow()`, ALE BEZ ZAPYTANIA NA WIERSZ.

                            Blokadę w obie strony wycina już zapytanie listy
                            (`SocialController::connections()`), więc tu zostaje
                            wyłącznie stan obu kont — pole w modelu, bez bazy
                            (`ListyIWyszukiwarkaTest` pilnuje, że lista nie
                            rośnie o zapytanie na osobę). Zapis i tak pyta
                            Policy na świeżo w `SocialController::follow()`.
                        --}}
                        @elseif(! $isFollowingPerson && $viewer->isActive() && $person->isActive())
                            <form method="POST" action="{{ route('social.follow', $personUsername) }}">
                                @csrf
                                <input type="hidden" name="oczekiwany_id" value="{{ $person->getKey() }}">
                                <button class="btn btn-primary" type="submit">Obserwuj</button>
                            </form>
                        {{-- Brak przycisku mówi, CZYJE konto jest zawieszone.
                             Wcześniej jedno zdanie „Konto zawieszone" stało też
                             przy każdej aktywnej osobie, gdy zawieszony był
                             oglądający (#926, D-253) — nieprawda o cudzym koncie.
                             Własne konto najpierw: tylko na nie człowiek może
                             coś poradzić. Przycisk, który zawsze kończy się
                             błędem, byłby martwy (D-053, #780). --}}
                        @elseif($viewer->isSuspended())
                            @if($isFollowingPerson)
                                <span class="meta">Obserwujesz. Twoje konto jest zawieszone — do czasu zdjęcia zawieszenia nie możesz przestać obserwować.</span>
                            @else
                                <span class="meta">Twoje konto jest zawieszone — do czasu zdjęcia zawieszenia nie możesz obserwować.</span>
                            @endif
                        @elseif(! $person->isActive())
                            <span class="meta">Konto zawieszone — nie można teraz obserwować.</span>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>

        <x-show-more :paginator="$people" czego="osób" />
    @endif
</x-layout>
