@props(['recipe', 'uklad' => 'wiersz', 'pokazWidocznosc' => false])
<article @class(['card', 'recipe-card-kafel' => $uklad === 'kafel'])>
    <div class="flex gap-4 items-start recipe-card-uklad">
        @if($recipe->heroMedia)
            @if($uklad === 'kafel')
                <div class="recipe-card-miniatura">
            @else
                <a href="{{ route('recipes.show', $recipe->slug) }}" class="recipe-card-miniatura">
            @endif
                {{-- `zoom` wyłączone: wiersz ma link na miniaturze,
                     a kafel jeden odnośnik rozszerzony na całą kartę.
                     Zdjęcie prowadzi do przepisu, bez dodatkowego linku
                     powiększania i bez zagnieżdżonych odnośników. --}}
                <x-photo :media="$recipe->heroMedia" :variant="$uklad === 'kafel' ? 'feed' : 'thumb'"
                         :sizes="$uklad === 'kafel' ? '(min-width: 768px) 360px, 100vw' : '120px'"
                         class="post-photo" :zoom="false" tresc="przepis" />
            @if($uklad === 'kafel')
                </div>
            @else
                </a>
            @endif
        @endif
        <div class="min-w-0">
            <h3 class="m-0 mb-2">
                @if($uklad === 'kafel')
                    {{ $recipe->title }}
                @else
                    <a href="{{ route('recipes.show', $recipe->slug) }}" class="link-tytul">{{ $recipe->title }}</a>
                @endif
            </h3>
            <p class="meta m-0 mb-2">
                {{ $recipe->attributionLine() }}
                {{-- Plakietka cicha „konto przykładowe" (D-032)
                     w tym samym wierszu metadanych — kropkę rysuje sam
                     komponent. --}}
                <x-konto-przykladowe :user="$recipe->author" />
                {{-- Widoczność ograniczona (issue #1320): wyszukiwarka pokazuje
                     też przepisy „dla obserwujących" i „tylko dla mnie", więc
                     karta musi to powiedzieć — inaczej autor weźmie własny
                     wynik za dowód, że przepis widzą wszyscy. Te same słowa
                     co na karcie wpisu. Publiczny zostaje bez plakietki. --}}
                @if($pokazWidocznosc && $recipe->visibility === 'followers')
                    · <span class="badge">Tylko dla obserwujących</span>
                @elseif($pokazWidocznosc && $recipe->visibility === 'private')
                    · <span class="badge">Tylko dla mnie</span>
                @endif
            </p>
            @if(($recipe->cooked_events_count ?? 0) > 0)
                <p class="m-0"><span class="badge badge-cooked">Ugotowane {{ $recipe->cooked_events_count }} ×</span></p>
            @endif
            @if($uklad === 'kafel')
                {{-- Długi tytuł może być wyższy niż ekran. Fokus obejmuje
                     krótką akcję, a pseudo-element rozszerza kliknięcie. --}}
                <a href="{{ route('recipes.show', $recipe->slug) }}" class="recipe-card-otworz"
                   aria-label="Zobacz przepis: {{ $recipe->title }}">Zobacz przepis</a>
            @endif
        </div>
    </div>
</article>
