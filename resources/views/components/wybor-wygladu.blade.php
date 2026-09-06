{{--
    Wybór sposobu wyświetlania zdjęć: zwykle / karuzela / kolaż (issue #92).

    JEDEN KOMPONENT NA DWA EKRANY
    Ten sam wybór stoi w formularzu publikacji i na ekranie „Zdjęcia w tym
    wpisie". Dwie kopie tych samych trzech opisów rozjechałyby się przy
    pierwszej poprawce tekstu — a to są zdania, które mają tłumaczyć decyzję,
    nie tylko nazywać opcje.

    DOMYŚLNIE „ZWYKLE" I TO NIE JEST PRZYPADEK
    Zwykłe zdjęcia jedno pod drugim to jedyny wariant, jaki Kuking miał do tej
    pory, więc każdy wpis zapisany wcześniej wygląda tak samo jak wczoraj.
    Wybór jest DODATKIEM dla osoby, która chce pokazać danie inaczej —
    nie kolejnym pytaniem na drodze do opublikowania zdjęcia.
--}}
@props([
    'wartosc' => \App\Models\Post::DISPLAY_NORMAL,
    'ukryty' => false,
])
<fieldset class="wybor-wygladu" data-wybor-wygladu @if($ukryty) hidden @endif>
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
                <span class="choice-help">Wszystkie zdjęcia w siatce, na jednym ekranie. Nic nie jest przycinane.</span>
            </span>
        </label>
    </div>
    @error('display_mode')<span class="field-error">{{ $message }}</span>@enderror
</fieldset>
