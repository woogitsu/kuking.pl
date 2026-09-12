<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Publiczna twarz użytkownika. Klucz główny to user_id (jeden profil na konto).
 */
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'username',
        'display_name',
        'bio',
        'avatar_media_id',
        'region',
        'speciality',
    ];

    /**
     * Profil po nazwie użytkownika, BEZ rozróżniania wielkości liter.
     *
     * PostgreSQL porównuje teksty z rozróżnianiem wielkości, a klawiatura
     * telefonu podnosi pierwszą literę bez pytania. `ProfileController`
     * radził sobie z tym od audytu A25, ale `SocialController`
     * i `OnboardingController` pytały zwykłym `where('username', ...)` —
     * więc `/@Halina` otwierało profil, a `/@Halina/obserwujacy` dawało 404.
     * Ta sama klasa błędu, którą A25 uznał za wartą naprawy, naprawiona
     * wtedy w jednym miejscu z trzech.
     *
     * Zapytanie trafia w unikalny indeks funkcyjny `lower(username)`
     * (migracja `2026_09_05_220000`), więc nie jest to skan tabeli.
     */
    public static function poNazwie(string $username): ?self
    {
        return self::whereRaw('lower(username) = ?', [mb_strtolower(trim($username))])->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    /** Adres profilu: /@basia */
    public function url(): string
    {
        return route('profile.show', ['username' => $this->username]);
    }

    /**
     * Zdjęcie profilowe, które NAPRAWDĘ wolno pokazać — albo `null`.
     *
     * REGUŁA ZMIENIŁA SIĘ Z „STATUS WIERSZA" NA „ISTNIEJĄCY WARIANT" (#448).
     * Widoki pytały dotąd o `Media::isReady()`, czyli o STAN WIERSZA. Po
     * wgraniu zdjęcia profilowego wiersz jest jeszcze `pending`, więc człowiek
     * dostawał w miejscu swojej twarzy napis o przygotowywaniu — także wtedy,
     * gdy plik nadający się do pokazania już istniał. Pytamy więc o to, o co
     * naprawdę chodzi: czy z naszego kodera wyszedł PLIK, który da się podać
     * przeglądarce.
     *
     * ORYGINAŁ NIE JEST WARIANTEM I NIE MA PRAWA TU WEJŚĆ. Rozstrzyga o tym
     * `Media::wariantDoSerwowania()` — jedyne miejsce w serwisie, które wie,
     * który plik idzie do przeglądarki (patrz jego docblock). Ta metoda
     * niczego z tamtej reguły nie powtarza, tylko ją woła: druga kopia
     * rozjechałaby się przy pierwszej zmianie listy wariantów, a rozjazd
     * znaczyłby tutaj „wystawiliśmy plik wgrany przez człowieka, z GPS-em
     * kuchni w EXIF-ie".
     *
     * STATUS `deleted` NIE PRZECHODZI NIGDY — i to jest ZWĘŻENIE reguły,
     * nie jej rozszerzenie. `KasujZdjecie` oznacza tak wiersz PRZED
     * skasowaniem plików i zostawia w `metadata.variants` klucze potrzebne
     * do sprzątania. Gdyby pytanie brzmiało wyłącznie „czy jest wariant",
     * zdjęcie przejęte do skasowania wracałoby na ekran — a przy wymazaniu
     * konta i przy decyzji moderacyjnej to jest dokładnie ten wiersz, który
     * ma zniknąć.
     *
     * CZEGO TA METODA NIE ZAŁATWIA — ZMIERZONE, NIE ZGADNIĘTE (#448 × #430)
     * Widok pyta o wariant, ale BAJTY wydaje trasa `media.show`, a ona pyta
     * dalej o status wiersza (`App\Domain\Media\DostepDoZdjecia::
     * gotoweDoSerwowania()` → `Media::isReady()`). Pomiar w przeglądarce
     * (`scripts/zdjecie-profilowe.mjs`, 12 września 2026) dla wiersza
     * `pending` z gotowym wariantem: `<img class="avatar">` stoi na stronie,
     * a trasa zdjęcia odpowiada **404 na 16 z 16 odsłon** — czyli pusta ramka
     * zamiast twarzy.
     *
     * DZIŚ TO NIKOMU NIE SZKODZI, bo stanu „`pending` i wariant już jest"
     * w tym repozytorium nie da się osiągnąć: `ProcessUploadedImage` zapisuje
     * warianty i `status = ready` JEDNYM `update()`. Stan ten pojawia się
     * dopiero z `PodgladOdRazu` (#430) — i wtedy TA SAMA ZMIANA musi rozluźnić
     * `DostepDoZdjecia`, inaczej awatary zamienią się w puste ramki. Kto
     * wprowadza podgląd od razu, wprowadza go w obu miejscach.
     */
    public function zdjecieDoPokazania(): ?Media
    {
        $zdjecie = $this->avatar;

        if ($zdjecie === null || $zdjecie->status === Media::STATUS_DELETED) {
            return null;
        }

        return $zdjecie->wariantDoSerwowania('thumb') === null ? null : $zdjecie;
    }

    /**
     * Czy człowiek ma zdjęcie, którego jeszcze nie da się pokazać.
     *
     * Ekran `/ustawienia/zdjecie` mówi trzy różne zdania i musi je rozróżnić:
     * „to jest Twoje zdjęcie", „Twoje zdjęcie się przygotowuje" i „nie masz
     * jeszcze zdjęcia". Ta metoda stoi obok `zdjecieDoPokazania()`, żeby obie
     * odpowiedzi brały `deleted` pod uwagę w ten sam sposób — wiersz przejęty
     * do skasowania nie przygotowuje się do niczego i człowiek nie ma na co
     * czekać.
     */
    public function zdjecieSieJeszczePrzygotowuje(): bool
    {
        $zdjecie = $this->avatar;

        return $zdjecie !== null
            && $zdjecie->status !== Media::STATUS_DELETED
            && $this->zdjecieDoPokazania() === null;
    }
}
