{{--
    Wybór sposobu wyświetlania zdjęć: zwykle / karuzela / kolaż (issue #92).

    TEN WYBÓR NIE STOI JUŻ W FORMULARZU PUBLIKACJI — DECYZJA WŁAŚCICIELA
    Stał tam ukryty i odsłaniał go skrypt po wybraniu drugiego pliku. Działało
    to wyłącznie u osób, którym skrypt się dociągnął: zanim ktoś kliknie
    „Opublikuj", zdjęcia SĄ JESZCZE W PRZEGLĄDARCE, więc serwer nie zna ich
    liczby i bez JavaScriptu nie ma jak pokazać wyboru w odpowiednim momencie.

    Zamiast tego pytamy PO opublikowaniu, na ekranie „Zdjęcia w tym wpisie",
    i tylko przy dwóch zdjęciach albo większej liczbie. Ta droga działa
    u wszystkich tak samo. Cena jest realna i przyjęta świadomie: jeden ekran
    więcej między „Opublikuj" a obejrzeniem wpisu — ale dopiero wtedy, gdy
    naprawdę jest o czym decydować.

    DOMYŚLNIE „ZWYKLE" I TO NIE JEST PRZYPADEK
    Zwykłe zdjęcia jedno pod drugim to jedyny wariant, jaki Kuking miał do tej
    pory, więc każdy wpis zapisany wcześniej wygląda tak samo jak wczoraj.
--}}
@props(['wartosc' => \App\Models\Post::DISPLAY_NORMAL])
<fieldset class="wybor-wygladu">
    <legend>Jak mają się wyświetlić te zdjęcia?</legend>

    <div class="choice-grid">
        <label class="choice">
            <input type="radio" name="display_mode" value="{{ \App\Models\Post::DISPLAY_NORMAL }}"
                   @checked($wartosc === \App\Models\Post::DISPLAY_NORMAL)>
            <span>
                <span class="choice-label">Zwykle</span>
                <span class="choice-help">Zdjęcia jedno pod drugim, wszystkie widoczne od razu.</span>
            </span>
        </label>

        <label class="choice">
            <input type="radio" name="display_mode" value="{{ \App\Models\Post::DISPLAY_CAROUSEL }}"
                   @checked($wartosc === \App\Models\Post::DISPLAY_CAROUSEL)>
            <span>
                <span class="choice-label">Karuzela</span>
                <span class="choice-help">Jedno zdjęcie naraz. Pod zdjęciem są przyciski „Poprzednie” i „Następne”.</span>
            </span>
        </label>

        <label class="choice">
            <input type="radio" name="display_mode" value="{{ \App\Models\Post::DISPLAY_COLLAGE }}"
                   @checked($wartosc === \App\Models\Post::DISPLAY_COLLAGE)>
            <span>
                <span class="choice-label">Kolaż</span>
                {{-- Zdanie o telefonie jest tu OBOWIĄZKOWE, nie jest dopiskiem.
                     Poniżej 30rem kolaż pokazuje się jako zwykła lista (patrz
                     `.kolaz` w app.css) — bez tego zdania autor wybrałby kolaż,
                     obejrzał wpis na własnym telefonie i zobaczył coś innego,
                     niż zaznaczył. Brak wyjaśnienia zamienia świadomą decyzję
                     projektową w usterkę. --}}
                <span class="choice-help">
                    Wszystkie zdjęcia w siatce, na jednym ekranie. Nic nie jest przycinane.
                    Na wąskim telefonie zdjęcia pokazują się jedno pod drugim — w siatce
                    byłyby za małe, żeby cokolwiek na nich zobaczyć.
                </span>
            </span>
        </label>
    </div>
    @error('display_mode')<span class="field-error">{{ $message }}</span>@enderror
</fieldset>
