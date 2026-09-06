<x-layout title="Prywatność" :noindex="true">
    <h1>Prywatność</h1>

    <form class="card" method="POST" action="{{ route('settings.privacy') }}">
        @csrf @method('PUT')
        <label class="choice" for="f-digest">
            <input id="f-digest" type="checkbox" name="wants_weekly_digest" value="1" @checked(auth()->user()->wants_weekly_digest)>
            <span>
                <span class="choice-label">Chcę raz w tygodniu dostawać e-mail z Kuking</span>
                <span class="choice-help">Krótkie podsumowanie: kto ugotował z Twoich przepisów i co ciekawego się działo. Jeden e-mail tygodniowo, nigdy więcej.</span>
            </span>
        </label>
        <button class="btn btn-primary" type="submit" style="margin-top:var(--spacing-4);">Zapisz</button>
    </form>

    <section style="margin-top:var(--spacing-8);">
        <h2>Zablokowane osoby</h2>
        @if($blocked->isEmpty())
            <p class="meta">Nikogo nie zablokowałaś.</p>
        @else
            <p>Te osoby nie widzą Twoich treści, a Ty nie widzisz ich.</p>
            <div class="stack-tight">
                @foreach($blocked as $person)
                    <div class="card" style="display:flex; align-items:center; gap:var(--spacing-3); flex-wrap:wrap;">
                        <x-avatar :user="$person" :size="44" />
                        <span style="flex:1;">{{ $person->displayName() }}</span>
                        <form method="POST" action="{{ route('social.unblock', $person->profile->username) }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-secondary" type="submit">Zdejmij blokadę</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section style="margin-top:var(--spacing-8);">
        <h2>Kto widzi Twoje treści</h2>
        <p>
            Przy każdym wpisie i przepisie sama decydujesz: wszyscy, tylko osoby które Cię obserwują,
            albo tylko Ty. Możesz to zmienić w każdej chwili.
        </p>
    </section>

    <x-ustawienia-nawigacja aktywne="privacy" />
</x-layout>
