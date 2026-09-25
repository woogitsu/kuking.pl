<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Log;

/**
 * Metadane zdjęcia. Sam plik żyje w object storage pod `object_key`.
 *
 * CO WOLNO POKAZAĆ — ZMIANA REGUŁY, 12 września 2026 (issue #430, D-???)
 *
 * Do tego dnia stało tu: „widoki NIGDY nie pokazują zdjęcia, które nie jest
 * `ready`". Reguła była zapisana przez STAN WIERSZA, a chroniła co innego —
 * BAJTY: chodziło o to, żeby na stronę nie trafił plik przysłany przez
 * użytkownika, z nietkniętym EXIF-em. `ready` było tylko skrótem myślowym na
 * „ten plik przeszedł już przez nasz koder".
 *
 * Skrót przestał być prawdziwy, odkąd `StoreUploadedImage` robi wariant
 * `podglad` SYNCHRONICZNIE, jeszcze w żądaniu wgrywającym: istnieje wtedy
 * wariant przepuszczony przez nasz koder (czyli bez EXIF-u), a wiersz stoi
 * dalej na `pending`. Reguła po staremu kazałaby ukryć plik, który jest
 * bezpieczny — i to jest dokładnie usterka #430: autorka widziała napis
 * zamiast własnego zdjęcia.
 *
 * DZIŚ REGUŁA BRZMI: pokazujemy wyłącznie to, co WYSZŁO Z NASZEGO KODERA,
 * czyli wariant zapisany w `metadata.variants`. Oryginał nie jest tam nigdy
 * i nie ma drogi, którą mógłby tam trafić — `url()` go nie zna,
 * `MediaController` serwuje wyłącznie klucze z `wariantDoSerwowania()`,
 * a `DostepDoZdjecia` pyta o to samo. Nowa reguła jest WĘŻSZA od poprzedniej
 * (mówi o bajtach, nie o etykiecie stanu) i nie ma w niej wyjątku dla
 * właściciela.
 *
 * @see maWariantDoPokazania()
 */
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'media';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Zdjęcie PRZEJĘTE DO SKASOWANIA — już nie do przypięcia (D-083).
     *
     * To nie znaczy „skasowane", tylko „kasowanie trwa": wiersz zostaje,
     * dopóki nie zniknie ostatni plik, i jest jedynym uchwytem do ponowienia
     * — dokładnie ta sama rola, jaką wiersz `media` pełni przy wymazywaniu
     * konta (`EraseAccountData`). Wartość dopuszcza `media_status_check` od
     * pierwszej migracji tabeli, więc schemat nie wymagał zmiany.
     */
    public const STATUS_DELETED = 'deleted';

    /**
     * Klucze plików, które `ProcessUploadedImage` DOPIERO ZAPISUJE (#601).
     *
     * `KasujZdjecie` chodzi po `metadata.variants`, a ta tablica powstaje
     * dopiero po ostatnim wariancie. Między pierwszym `put()` a końcem
     * zadania pliki leżą już w publicznym buckecie, a w bazie nie ma pod nie
     * żadnego klucza — więc nie kasuje ich ani usunięcie wpisu, ani wymazanie
     * konta. Ta lista jest tym brakującym uchwytem i znika po sukcesie.
     *
     * Nie nazywa się `variants`, bo `wariantDoSerwowania()` pokazałoby po
     * niej zdjęcie w połowie przetwarzania — pod nazwą wariantu, którego plik
     * może jeszcze nie istnieć.
     */
    public const METADANE_WARIANTY_W_TRAKCIE = 'warianty_w_trakcie';

    protected $fillable = [
        'owner_id',
        'disk',
        'variants_disk',
        'object_key',
        'mime_type',
        'bytes',
        'width',
        'height',
        'status',
        'alt_text',
        'checksum_sha256',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * Wpisy, do ktorych to zdjecie jest przypiete.
     *
     * Potrzebne do bramki wlasnosci przy odzyskiwaniu zdjec po nieudanej
     * walidacji (audyt C1): zdjecie juz przypiete do wpisu nie moze zostac
     * podpiete pod drugi.
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_media');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Czy jest już COKOLWIEK, co wolno pokazać na stronie (issue #430).
     *
     * To jest bramka widoków i bramka `DostepDoZdjecia` — ta sama, jedna.
     * NIE pyta o status, tylko o to, czy istnieje wariant, czyli plik, który
     * wyszedł z naszego kodera. Dlaczego akurat tak, patrz docblock klasy.
     *
     * `pending` i `processing` PRZECHODZĄ, gdy jest już `podglad` — i po to
     * ta metoda powstała. `rejected` też przechodzi, jeśli podgląd zdążył
     * powstać: przetwarzanie w tle padło, ale zdjęcie autorki jest i da się
     * je pokazać, a pokazanie go jest bliżej prawdy niż zdanie „nie udało
     * się przygotować" pod obrazkiem, który istnieje.
     *
     * `deleted` NIE PRZECHODZI NIGDY, nawet z kompletem wariantów. Ten status
     * znaczy „kasowanie trwa" (D-083): pliki właśnie znikają, wiersz jest
     * tylko uchwytem do ponowienia. Serwis powiedział już komuś „skasowane"
     * i od tej chwili nie wolno tych bajtów pokazać ani razu więcej.
     */
    public function maWariantDoPokazania(string $variant = 'feed'): bool
    {
        return $this->status !== self::STATUS_DELETED
            && $this->wariantDoSerwowania($variant) !== null;
    }

    /**
     * Klucz PUBLICZNEGO wariantu, policzony z klucza oryginału.
     *
     * JEDNO MIEJSCE, BO LICZĄ TO DWA (issue #430). Wariant `podglad` powstaje
     * w `StoreUploadedImage` (synchronicznie, przy wgraniu), a `thumb`, `feed`
     * i `large` w `ProcessUploadedImage` (w tle). Obie strony muszą wyliczyć
     * TĘ SAMĄ ścieżkę, bo obie piszą do tego samego prefiksu i obie te pliki
     * kasuje potem `KasujZdjecie`, chodząc po `metadata.variants`.
     *
     * Gdyby każda liczyła po swojemu, rozjazd nie wywaliłby żadnego testu od
     * razu — dałby pliki-sieroty w publicznym buckecie, których nic już nigdy
     * nie skasuje, bo nie ma ich pod żadnym kluczem w bazie.
     *
     * Zamiana prefiksu `incoming/` (prywatny bucket oryginałów) na `media/`
     * (bucket publiczny), a nie przepisywanie całej ścieżki — dzięki temu
     * stare wiersze, zapisane jeszcze pod `media/`, liczą się bez zmian.
     */
    public static function kluczPublicznegoWariantu(string $objectKey, string $nazwaWariantu): string
    {
        $publicznyKlucz = str_starts_with($objectKey, 'incoming/')
            ? 'media/'.substr($objectKey, strlen('incoming/'))
            : $objectKey;

        return preg_replace('/\.[^.]+$/', '', $publicznyKlucz)."_{$nazwaWariantu}.webp";
    }

    /**
     * Adres wariantu zdjęcia — TRASA APLIKACJI, nie adres pliku w buckecie
     * (audyt W7-02).
     *
     * DLACZEGO NIE `Storage::url()`, SKORO TAK BYŁO
     * Bo adres pliku pod własną domeną CDN nikogo o nic nie pyta. Kto raz go
     * skopiował, otwierał zdjęcie także po zablokowaniu, po cofnięciu
     * obserwowania i po przełączeniu przepisu na prywatny — bez konta, bez
     * sesji, bez śladu. Przy `recipes.source_scan_media_id` (skan odręcznej
     * kartki z nazwiskami i adresami) to jest awaria prywatności, a nie
     * niedogodność.
     *
     * Teraz adres prowadzi do `MediaController`, który pyta Policy treści
     * NADRZĘDNEJ i przekierowuje (302) na krótko podpisany adres R2. Bajty
     * nie idą przez PHP — idzie przez nie wyłącznie decyzja.
     *
     * TA METODA JEST JEDYNYM MIEJSCEM GENERUJĄCYM ADRES ZDJĘCIA i to jest
     * warunek działania całej zmiany. Drugie miejsce, które zbuduje adres
     * pliku samo, obchodzi kontrolę dostępu i nie wywali przy tym żadnego
     * testu — pilnuje tego `ZdjeciaChronioneNieWyciekajaTest`.
     *
     * NIGDY nie wraca do oryginału. Wcześniejsza wersja miała
     * `?? $this->object_key` jako zabezpieczenie przed pustą ramką i to był
     * wyciek: oryginał to plik przysłany przez użytkownika, z nietkniętym
     * EXIF-em, czyli z dokładną lokalizacją kuchni.
     *
     * Kolejność: żądany wariant → dowolny wygenerowany → placeholder.
     * Pusta ramka jest gorsza niż nic, ale wyciek cudzego adresu jest gorszy
     * od pustej ramki.
     */
    public function url(string $variant = 'feed'): string
    {
        $wybrany = $this->wariantDoSerwowania($variant);

        if ($wybrany === null) {
            Log::warning('Zdjęcie bez wygenerowanych wariantów', [
                'media_id' => $this->getKey(),
                'status' => $this->status,
            ]);

            return asset('icons/kuking-mark.svg');
        }

        return route('media.show', [
            'media' => $this->getKey(),
            'wariant' => $wybrany['nazwa'],
        ]);
    }

    /**
     * Który wariant naprawdę pójdzie do przeglądarki i pod jakim kluczem.
     *
     * Rozstrzygnięcie „żądany wariant → dowolny wygenerowany → nic" musi być
     * JEDNO, wspólne dla `url()` (buduje adres) i dla `MediaController`
     * (serwuje bajty). Dwie kopie tej samej kolejności rozjechałyby się przy
     * pierwszej zmianie listy wariantów: adres wskazywałby `large`, a
     * kontroler oddawałby `feed` albo 404.
     *
     * @return array{nazwa: string, klucz: string}|null
     */
    public function wariantDoSerwowania(string $variant = 'feed'): ?array
    {
        $variants = $this->warianty();

        $klucz = $variants[$variant]['key'] ?? null;

        if ($klucz !== null) {
            return ['nazwa' => $variant, 'klucz' => $klucz];
        }

        // Wariant nieznany, ale jakieś istnieją — bierzemy pierwszy lepszy.
        // To znaczy, że ktoś dodał wariant do konfiguracji i nie przetworzył
        // istniejących zdjęć; obraz będzie w złym rozmiarze, ale bezpieczny
        // DLA WYŚWIETLENIA W SERWISIE, i tylko dla niego. Dla wysyłki poza
        // serwer rozmiar JEST granicą (D-240, #912): zamiennikiem może być
        // `large`. Kto wysyła zdjęcie na zewnątrz, bierze `wariant()` wprost
        // i przy braku odmawia — jak `OcenaModelem::jakoJpeg()`.
        foreach ($variants as $nazwa => $dane) {
            if (isset($dane['key'])) {
                return ['nazwa' => $nazwa, 'klucz' => $dane['key']];
            }
        }

        return null;
    }

    public function width(string $variant = 'feed'): ?int
    {
        return $this->wymiar($variant, 'width') ?? $this->width;
    }

    public function height(string $variant = 'feed'): ?int
    {
        return $this->wymiar($variant, 'height') ?? $this->height;
    }

    /**
     * Czy ten KONKRETNY wariant naprawdę istnieje — bez podstawiania innego.
     *
     * `wariantDoSerwowania()` celowo podstawia zamiennik, bo do pokazania
     * czegokolwiek lepszy jest zły rozmiar niż pusta ramka. `srcset` jest
     * jedynym miejscem, które potrzebuje odpowiedzi DOSŁOWNEJ: wypisanie tam
     * czterech nazw wariantów, z których istnieje jedna, dałoby cztery
     * kandydatury wskazujące na ten sam plik i przeglądarka nie miałaby
     * z czego wybierać.
     */
    public function maWariant(string $nazwa): bool
    {
        return isset($this->warianty()[$nazwa]['key']);
    }

    /**
     * Wartość atrybutu `srcset` z PRAWDZIWYCH szerokości istniejących
     * wariantów (audyt T30, issue #430; wspólne od #1310 i #1326).
     *
     * Wcześniej ta pętla żyła tylko w `<x-photo>`, a miniatury tablicy
     * i katalogu tagów pisały gołe `<img src=feed>` — przeglądarka nie miała
     * z czego wybrać i do pola ~120–350 px pobierała wariant 960 px.
     *
     * `maWariant()`, nie `width()`: `width()` podstawia wariant zastępczy,
     * więc przed zadaniem w tle cztery nazwy wskazywałyby na jeden `podglad`.
     * Jeden kandydat na szerokość (od najmniejszego pliku), bo `scaleDown()`
     * nie powiększa i przy małym zdjęciu `feed` i `large` mają równą
     * szerokość. Oryginał nie jest wariantem i tu nie trafia nigdy.
     */
    public function srcset(): string
    {
        $kandydaci = [];

        foreach (['thumb', 'podglad', 'feed', 'large'] as $nazwaWariantu) {
            if (! $this->maWariant($nazwaWariantu)) {
                continue;
            }

            $szerokosc = $this->width($nazwaWariantu);

            if ($szerokosc === null || isset($kandydaci[$szerokosc])) {
                continue;
            }

            $kandydaci[$szerokosc] = $this->url($nazwaWariantu).' '.$szerokosc.'w';
        }

        ksort($kandydaci);

        return implode(', ', $kandydaci);
    }

    /**
     * Zapis JEDNEGO wariantu z metadanych, albo `null`.
     *
     * Istnieje po to, żeby `ProcessUploadedImage` mógł przenieść `podglad`
     * do nowej listy wariantów, nie sięgając po `metadata` gołą ręką:
     * `metadata` to JSONB i dla analizy statycznej jest wartością o nieznanym
     * kształcie. Kształt opisuje jedno miejsce — `warianty()` — i wszystko,
     * co czyta warianty, ma iść przez nie.
     *
     * @return array{key?: string, width?: int, height?: int}|null
     */
    public function wariant(string $nazwa): ?array
    {
        return $this->warianty()[$nazwa] ?? null;
    }

    /**
     * Wymiar TEGO, CO NAPRAWDĘ PÓJDZIE DO PRZEGLĄDARKI (issue #430).
     *
     * Kolejność jest ta sama co w `wariantDoSerwowania()` i to jest cały
     * powód, dla którego ta metoda istnieje. Wcześniej przy braku żądanego
     * wariantu wracały tu `width`/`height` z KOLUMN, czyli wymiary oryginału
     * — a `src` wskazywał tymczasem na wariant podstawiony. Dopóki
     * niegotowych zdjęć nie pokazywano wcale, nie miało to znaczenia.
     *
     * Od #430 ma, i to widoczne: kolumny opisują plik PRZED obrotem z EXIF-u,
     * a wariant jest już obrócony. Dla zdjęcia z telefonu trzymanego pionowo
     * (`Orientation` 6 albo 8) atrybuty `width`/`height` mówiłyby więc
     * 4032×3024 o obrazku, który jest 3024×4032 — i karta skakałaby
     * o połowę ekranu w chwili, w której zdjęcie się wczyta. Przy powiększonym
     * tekście na telefonie to jest skok na cały ekran.
     *
     * Na kolumny spadamy dopiero, gdy nie ma ŻADNEGO wariantu — wtedy nic
     * się nie wczyta i jedyne, do czego te liczby służą, to kształt ramki
     * zastępczej.
     */
    private function wymiar(string $variant, string $ktory): ?int
    {
        $warianty = $this->warianty();

        if (isset($warianty[$variant][$ktory])) {
            return $warianty[$variant][$ktory];
        }

        $wybrany = $this->wariantDoSerwowania($variant);

        return $wybrany === null ? null : ($warianty[$wybrany['nazwa']][$ktory] ?? null);
    }

    /**
     * Dysk, na którym leżą PUBLICZNE WARIANTY tego zdjęcia.
     *
     * Oryginał (`disk`) i warianty mogą być w dwóch różnych bucketach —
     * oryginał w prywatnym, warianty w tym za `cdn.kuking.pl` (audyt G-01).
     * Na R2 publiczność jest cechą bucketu, nie obiektu, więc trzymanie obu
     * w jednym buckecie wystawiało oryginały z EXIF-em i GPS-em.
     *
     * `null` znaczy „tam, gdzie oryginał" i tak jest dla każdego zdjęcia
     * zapisanego przed rozdzieleniem bucketów. Nie backfillujemy tej kolumny:
     * wpisanie tam nazwy nowego dysku byłoby stwierdzeniem nieprawdy o tym,
     * gdzie te pliki fizycznie leżą, a `KasujZdjecie` szukałoby ich w złym
     * buckecie i zostawiało publiczne kopie na zawsze.
     */
    public function variantsDisk(): string
    {
        return $this->variants_disk ?? $this->disk;
    }

    /**
     * Warianty zdjęcia z metadanych, z JAWNIE OPISANYM KSZTAŁTEM.
     *
     * `metadata` to JSONB rzutowany na tablicę, więc dla analizy statycznej
     * jest tablicą o nieznanej zawartości — stąd trzy ostrzeżenia
     * „Offset 'variants' on array{} does not exist", które trzymały PHPStana
     * na poziomie 0 dla całego repozytorium.
     *
     * To nie jest cisza dla analizatora. `ProcessUploadedImage` zapisuje tu
     * strukturę, którą trzy metody niżej czytają na trzy różne sposoby, a
     * jedyny opis tej struktury żył w komentarzu w jobie. Teraz kształt stoi
     * w typie, obok kodu, który go czyta — i `is_array()` sprawdza go naprawdę,
     * bo w bazie mogą leżeć wiersze sprzed każdej zmiany tego formatu.
     *
     * @return array<string, array{key?: string, width?: int, height?: int}>
     */
    private function warianty(): array
    {
        $metadata = $this->metadata;

        if (! is_array($metadata) || ! is_array($metadata['variants'] ?? null)) {
            return [];
        }

        return $metadata['variants'];
    }
}
