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
 * ZAWODZI CICHO I W DOBRĄ STRONĘ. Brak klucza, timeout, 5xx, odpowiedź
 * w nieznanym kształcie — każde z tych oddaje pustą listę sygnałów. Skutkiem
 * jest brak jednej pozycji w kolejce, nigdy zablokowana publikacja.
 */
final class OcenaModelem
{
    public const KOD = 'automat_model';

    public function __construct(private readonly KlientOpenAI $klient) {}

    /**
     * @return list<Sygnal>
     */
    public function dla(Post|Comment $tresc): array
    {
        if (! KlientOpenAI::oceniamy()) {
            return [];
        }

        $sygnaly = [];

        $tekst = trim((string) $tresc->body);

        if ($tekst !== '') {
            $sygnaly = $this->zWyniku($this->klient->ocenTekst($tekst), $sygnaly);
        }

        if ($tresc instanceof Post && config('kuking.moderation.model.ocenia_zdjecia')) {
            foreach ($this->zdjeciaDoOceny($tresc) as $dataUri) {
                $sygnaly = $this->zWyniku($this->klient->ocenObraz($dataUri), $sygnaly);
            }
        }

        return $sygnaly;
    }

    /**
     * Ocena JEDNEGO zdjęcia, bez żadnej treści obok (issue #237).
     *
     * Używa tego zdjęcie profilowe, które nie należy do żadnego wpisu, a jest
     * widoczne częściej niż jakikolwiek wpis: chodzi za człowiekiem po całym
     * serwisie, przy każdym komentarzu i na każdej liście.
     *
     * TA SAMA DROGA CO ZDJĘCIA WPISÓW — wariant `thumb`, przekodowany do
     * JPEG, wysłany jako `data:`. Druga droga do tego samego API rozjechałaby
     * się przy pierwszej zmianie (inny format, inny rozmiar, inny sposób
     * radzenia się z błędem), a to jest miejsce, w którym cicha awaria znaczy
     * „nikt tego zdjęcia nie oglądał".
     *
     * `$przedmiot` wchodzi do powodu, bo moderator musi wiedzieć, NA CO
     * patrzy, zanim otworzy podgląd: „Zdjęcie profilowe: …" czyta się inaczej
     * niż „Zdjęcie: …".
     *
     * @return list<Sygnal>
     */
    public function dlaZdjecia(Media $media, string $przedmiot = 'Zdjęcie'): array
    {
        if (! KlientOpenAI::oceniamy() || ! config('kuking.moderation.model.ocenia_zdjecia')) {
            return [];
        }

        if ($media->status !== Media::STATUS_READY) {
            return [];
        }

        $dataUri = $this->jakoJpeg($media);

        if ($dataUri === null) {
            return [];
        }

        $wynik = $this->klient->ocenObraz($dataUri);

        if ($wynik === null || ! $wynik->costamZnalazl()) {
            return [];
        }

        return [new Sygnal(self::KOD, $przedmiot.': '.$wynik->powod(), $wynik->pilne)];
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
     * Zdjęcia wpisu jako `data:` URI — przekodowane, bez metadanych.
     *
     * TRZY RZECZY DZIEJĄ SIĘ TU ŚWIADOMIE:
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
     * @return list<string>
     */
    private function zdjeciaDoOceny(Post $post): array
    {
        $ile = max(0, (int) config('kuking.moderation.model.zdjec_na_wpis'));

        if ($ile === 0) {
            return [];
        }

        $wynik = [];

        foreach ($post->media()->limit($ile)->get() as $media) {
            if (! $media instanceof Media || $media->status !== Media::STATUS_READY) {
                continue;
            }

            $dataUri = $this->jakoJpeg($media);

            if ($dataUri !== null) {
                $wynik[] = $dataUri;
            }
        }

        return $wynik;
    }

    private function jakoJpeg(Media $media): ?string
    {
        $wariant = $media->wariantDoSerwowania('thumb');

        if ($wariant === null) {
            return null;
        }

        try {
            $bajty = Storage::disk($media->variantsDisk())->get($wariant['klucz']);

            if (! is_string($bajty) || $bajty === '') {
                return null;
            }

            $jpeg = (string) ImageManager::gd()->read($bajty)->toJpeg(quality: 80);
        } catch (Throwable $blad) {
            // Bez identyfikatora zdjęcia w treści komunikatu i bez samych
            // bajtów — to jest cudza fotografia, a dziennik błędów nie jest
            // miejscem na treści użytkowników.
            Log::warning('Nie udało się przygotować zdjęcia do oceny modelem.', [
                'blad' => $blad->getMessage(),
            ]);

            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
