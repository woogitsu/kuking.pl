<?php

declare(strict_types=1);

namespace App\Moderacja;

use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * DRUGA PARA OCZU: MODEL OCENIAJĄCY TREŚĆ I ZDJĘCIA (D-055).
 *
 * Oddaje takie same `Sygnal`-e jak lokalny `WykrywaczSygnalow`, więc dalej
 * wszystko dzieje się identycznie: jedna pozycja w kolejce moderatora,
 * powód po polsku, żadnej konsekwencji dla autora. To jest cały sens tego
 * kształtu — model nie dostaje własnej ścieżki, bo nie ma własnego rodzaju
 * decyzji.
 *
 * CO TEN MODEL ŁAPIE, A CZEGO NIE
 * Nienawiść, przemoc, treści seksualne, samookaleczenie. **Nie ocenia
 * spamu w ogóle** — a spam jest naszym realnym zagrożeniem przy fali
 * z Garnek.pl. To jest UZUPEŁNIENIE sygnałów lokalnych, nie ich zamiennik,
 * i trzeba to powtarzać, bo inaczej ktoś uzna, że skoro jest AI, to spam
 * mamy załatwiony.
 *
 * NAJWIĘKSZA WARTOŚĆ SĄ TU ZDJĘCIA. Kuking stoi na fotografiach obiadów
 * wrzucanych przez nieznajomych — to jest jedyna treść, której NIKT nie
 * przeczyta, dopóki ktoś jej nie zgłosi. Tekst przynajmniej mija się
 * z ludzkim okiem w feedzie.
 *
 * CO WYCHODZI (D-240): wyłącznie treść publiczna (`GranicaWysylki`) i zdjęcie
 * wpisu pomniejszone do `MAX_BOK`. Zdjęcia profilowego ta klasa nie wysyła
 * wcale — nie ma potwierdzonej zgody na jego ocenę.
 *
 * ZAWODZI W DOBRĄ STRONĘ, ALE NIE PO CICHU. Brak klucza, timeout, 5xx,
 * odpowiedź w nieznanym kształcie — każde z tych oddaje pustą listę
 * sygnałów i nigdy nie blokuje publikacji. Każde zostawia też wpis
 * w dzienniku: pusta lista znaczy „nie wiemy", a nie „sprawdzone, czyste".
 */
final class OcenaModelem
{
    public const KOD = 'automat_model';

    /**
     * Najdłuższy bok obrazu, który wolno wysłać (D-240).
     *
     * Równy dzisiejszemu `thumb` z `config/kuking.php`, ale celowo NIE
     * czytany z konfiguracji: to jest granica prywatności, a nie ustawienie
     * generatora wariantów — powiększenie miniatur na stronie nie może po
     * cichu powiększyć tego, co wychodzi do dostawcy.
     */
    public const MAX_BOK = 320;

    public function __construct(
        private readonly KlientOpenAI $klient,
        private readonly GranicaWysylki $granica,
    ) {}

    /**
     * Ocena tekstu i zdjęć wpisu albo tekstu komentarza.
     *
     * GRANICA (`GranicaWysylki`, D-240) jest pytana PRZED KAŻDYM żądaniem,
     * nie raz na wejściu: ocena jednego zdjęcia trwa sekundy, a w tym czasie
     * autor może przełączyć wpis na prywatny.
     *
     * @return list<Sygnal>
     */
    public function dla(Post|Comment $tresc): array
    {
        if (! KlientOpenAI::oceniamy()) {
            $this->sladBrakuKlucza();

            return [];
        }

        $sygnaly = [];

        $tekst = trim((string) $tresc->body);

        if ($tekst !== '' && $this->granica->publiczna($tresc)) {
            $sygnaly = $this->zWyniku($this->klient->ocenTekst($tekst), $sygnaly);
        }

        if ($tresc instanceof Post && config('kuking.moderation.model.ocenia_zdjecia')) {
            foreach ($this->zdjeciaDoOceny($tresc) as $media) {
                if (! $this->granica->zdjecieWpisu($tresc, $media)) {
                    continue;
                }

                $dataUri = $this->jakoJpeg($media);

                if ($dataUri === null) {
                    continue;
                }

                $sygnaly = $this->zWyniku($this->klient->ocenObraz($dataUri), $sygnaly);
            }
        }

        return $sygnaly;
    }

    /**
     * BRAK KLUCZA NA PRODUKCJI NIE JEST STANEM SPOCZYNKU, TYLKO AWARIĄ.
     *
     * Lokalnie, w CI i w testach brak klucza jest normalny i cichy
     * (D-055). Na produkcji ten sam brak znaczy, że żadna treść nie jest
     * oglądana przez model, a z zewnątrz wygląda to identycznie jak
     * „model niczego nie znalazł". Dlatego zostawiamy ślad w dzienniku —
     * przy każdej treści, która przez to nie została oceniona. Lokalne
     * sygnały działają dalej niezależnie od tego.
     */
    private function sladBrakuKlucza(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        Log::warning('Ocena modelem pominięta: brak klucza OPENAI_MODERATION_KEY. Treść NIE została sprawdzona przez model.', [
            'stage' => 'openai_disabled',
        ]);
    }

    /**
     * @param  list<Sygnal>  $sygnaly
     * @return list<Sygnal>
     */
    private function zWyniku(?WynikOceny $wynik, array $sygnaly): array
    {
        if ($wynik === null || ! $wynik->costamZnalazl()) {
            return $sygnaly;
        }

        $sygnaly[] = new Sygnal(self::KOD, $wynik->powod(), $wynik->pilne);

        return $sygnaly;
    }

    /**
     * Zdjęcia wpisu do oceny — gotowe, w limicie z konfiguracji.
     *
     * TRZY RZECZY DZIEJĄ SIĘ PRZY NICH ŚWIADOMIE (`jakoJpeg()`):
     *
     * 1. bierzemy WARIANT `thumb`, nie oryginał. Oryginał niesie pełny EXIF,
     *    czyli współrzędne GPS kuchni, w której zrobiono zdjęcie
     *    (`AGENTS.md` §7). Wariant powstał przez przekodowanie, więc
     *    metadanych już nie ma;
     * 2. przekodowujemy go jeszcze raz do JPEG. Warianty zapisujemy w WebP,
     *    a formatem, o którym wiadomo, że API go przyjmie, jest JPEG —
     *    zamiana 320-pikselowej miniatury kosztuje ułamek sekundy i zdejmuje
     *    całą klasę cichych awarii („model milczy, bo nie rozumie formatu");
     * 3. wysyłamy `data:`, nie adres. Nasze zdjęcia stoją w prywatnym
     *    buckecie za polityką dostępu — publiczny adres dla OpenAI musiałby
     *    być publiczny także dla wszystkich innych.
     *
     * @return list<Media>
     */
    private function zdjeciaDoOceny(Post $post): array
    {
        $ile = max(0, (int) config('kuking.moderation.model.zdjec_na_wpis'));

        if ($ile === 0) {
            return [];
        }

        $wynik = [];

        foreach ($post->media()->limit($ile)->get() as $media) {
            if ($media instanceof Media && $media->status === Media::STATUS_READY) {
                $wynik[] = $media;
            }
        }

        return $wynik;
    }

    /**
     * Miniatura jako JPEG — albo `null`, gdy prawdziwej miniatury nie ma.
     *
     * TYLKO WARIANT `thumb`, BEZ ZASTĘPSTWA. `wariantDoSerwowania()` przy
     * braku miniatury podstawia pierwszy lepszy wariant — dla strony to
     * rozsądne, dla wysyłki poza serwer nie: zdjęcie z samym `large`
     * wychodziło do OpenAI w 1600 px (D-240). Brak miniatury = zdjęcie
     * nie wychodzi, z wpisem w dzienniku.
     *
     * WYMIARY Z BAJTÓW, NIE Z METADANYCH. Nazwa wariantu i liczby
     * w `metadata` to deklaracja, a granica dotyczy tego, co faktycznie
     * opuszcza serwer. Sprawdzamy plik przed dekodowaniem i gotowy JPEG
     * przed wysłaniem; każdy bok najwyżej `MAX_BOK` px.
     */
    private function jakoJpeg(Media $media): ?string
    {
        $wariant = $media->wariant('thumb');
        $klucz = is_array($wariant) ? ($wariant['key'] ?? null) : null;

        if (! is_string($klucz) || trim($klucz) === '') {
            $this->pominieteZdjecie('missing_thumb');

            return null;
        }

        try {
            $bajty = Storage::disk($media->variantsDisk())->get($klucz);

            if (! is_string($bajty) || $bajty === '' || ! $this->miesciSie($bajty)) {
                $this->pominieteZdjecie('thumb_not_small');

                return null;
            }

            $jpeg = (string) ImageManager::gd()->read($bajty)->toJpeg(quality: 80);
        } catch (Throwable $blad) {
            // Bez identyfikatora zdjęcia w treści komunikatu i bez samych
            // bajtów — to jest cudza fotografia, a dziennik błędów nie jest
            // miejscem na treści użytkowników.
            Log::warning('Nie udało się przygotować zdjęcia do oceny modelem.', [
                ...ExceptionContext::forStage($blad, 'image_preparation'),
            ]);

            return null;
        }

        if (! $this->miesciSie($jpeg)) {
            $this->pominieteZdjecie('encoded_not_small');

            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    private function miesciSie(string $bajty): bool
    {
        $rozmiar = @getimagesizefromstring($bajty);

        return $rozmiar !== false
            && $rozmiar[0] > 0 && $rozmiar[1] > 0
            && max($rozmiar[0], $rozmiar[1]) <= self::MAX_BOK;
    }

    /** Stały komunikat bez identyfikatorów: ma zostać ślad, że zdjęcia nikt nie oglądał. */
    private function pominieteZdjecie(string $powod): void
    {
        Log::warning('Zdjęcie pominięte w ocenie modelem: brak pomniejszonej miniatury.', [
            'stage' => 'image_boundary',
            'reason' => $powod,
        ]);
    }
}
