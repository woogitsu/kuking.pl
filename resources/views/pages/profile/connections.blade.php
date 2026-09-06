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
                                <button class="btn btn-secondary" type="submit">Przestań obserwować</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('social.follow', $personUsername) }}">
                                @csrf
                                <button class="btn btn-primary" type="submit">Obserwuj</button>
                            </form>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-6">{{ $people->links() }}</div>
    @endif
</x-layout>
