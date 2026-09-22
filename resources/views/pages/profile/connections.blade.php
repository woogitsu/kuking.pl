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
                        @if($isFollowingPerson)
                            <form method="POST" action="{{ route('social.unfollow', $personUsername) }}">
                                @csrf @method('DELETE')
                                {{-- #793 rozszerzone na relacje: lista bywa
                                     otwarta długo, a nazwa w adresie mogła
                                     w międzyczasie zmienić właściciela. --}}
                                <input type="hidden" name="oczekiwany_id" value="{{ $person->getKey() }}">
                                <button class="btn btn-secondary" type="submit">Przestań obserwować</button>
                            </form>
                        @elseif($person->isActive())
                            <form method="POST" action="{{ route('social.follow', $personUsername) }}">
                                @csrf
                                <input type="hidden" name="oczekiwany_id" value="{{ $person->getKey() }}">
                                <button class="btn btn-primary" type="submit">Obserwuj</button>
                            </form>
                        @else
                            {{-- #780: `widocznyJakoOsoba()` w kontrolerze zostawia
                                 konta zawieszone na liście (zawieszenie jest
                                 tymczasowe), ale `UserPolicy::follow()` wymaga
                                 `isActive()` i zawsze odmawia. Bez tej gałęzi
                                 przycisk „Obserwuj" byłby martwy (D-053):
                                 zawsze kończyłby się błędem po kliknięciu. --}}
                            <span class="meta">Konto zawieszone — nie można teraz obserwować.</span>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>

        <x-show-more :paginator="$people" czego="osób" />
    @endif
</x-layout>
