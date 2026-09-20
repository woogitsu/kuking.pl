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
     * TA METODA NIE POWTARZA REGUŁY, TYLKO JĄ WOŁA. Rozstrzyga
     * `Media::maWariantDoPokazania()` — ta sama, jedna bramka, której używa
     * `DostepDoZdjecia` i widok zdjęcia wpisu (#430). Druga kopia rozjechałaby
     * się przy pierwszej zmianie listy wariantów, a rozjazd znaczyłby tutaj
     * „wystawiliśmy plik wgrany przez człowieka, z GPS-em kuchni w EXIF-ie":
     * oryginał nie jest wariantem i tą drogą nie przechodzi.
     *
     * STATUS `deleted` NIE PRZECHODZI NIGDY — i to jest ZWĘŻENIE reguły,
     * nie jej rozszerzenie. `KasujZdjecie` oznacza tak wiersz PRZED
     * skasowaniem plików i zostawia w `metadata.variants` klucze potrzebne
     * do sprzątania. Gdyby pytanie brzmiało wyłącznie „czy jest wariant",
     * zdjęcie przejęte do skasowania wracałoby na ekran — a przy wymazaniu
     * konta i przy decyzji moderacyjnej to jest dokładnie ten wiersz, który
     * ma zniknąć. Zwężenia pilnuje `Media::maWariantDoPokazania()` i osobny
     * test tego pasa; tutaj nie ma go po raz drugi.
     *
     * DLACZEGO `thumb`, SKORO PO WGRANIU ISTNIEJE TYLKO `podglad`
     * Bo `Media::wariantDoSerwowania()` przy braku żądanego wariantu bierze
     * pierwszy istniejący. Awatar dostaje więc `podglad` w pierwszej chwili
     * po wgraniu, a `thumb` — właściwy rozmiar — gdy tylko przetwarzanie
     * w tle je policzy. Nazwa wariantu mówi, czego CHCEMY, nie na co czekamy.
     *
     * CZĘŚĆ DROGI, KTÓRA NALEŻY DO #430. Widok pyta o wariant, ale BAJTY
     * wydaje trasa `media.show`. Pomiar z 12 września 2026
     * (`scripts/zdjecie-profilowe.mjs`) na gałęzi SPRZED scalenia #430:
     * dla wiersza `pending` z gotowym wariantem `<img class="avatar">` stał
     * na stronie, a trasa zdjęcia odpowiadała **404 na 16 z 16 odsłon** —
     * pusta ramka zamiast twarzy, bo `DostepDoZdjecia` pytało tam jeszcze
     * o `isReady()`. #430 zdjęło ten warunek i obie strony pytają dziś o to
     * samo. Ta liczba zostaje tu jako ostrzeżenie: rozdzielenie bramki widoku
     * od bramki serwowania daje pustą ramkę, a nie czerwony test.
     */
    public function zdjecieDoPokazania(): ?Media
    {
        $zdjecie = $this->avatar;

        if ($zdjecie === null) {
            return null;
        }

        return $zdjecie->maWariantDoPokazania('thumb') ? $zdjecie : null;
    }

    /**
     * Czekanie ma sens tylko podczas pracy, zanim istnieje bezpieczny wariant.
     */
    public function zdjecieSieJeszczePrzygotowuje(): bool
    {
        $zdjecie = $this->avatar;

        return $zdjecie !== null
            && in_array($zdjecie->status, [Media::STATUS_PENDING, Media::STATUS_PROCESSING], true)
            && $this->zdjecieDoPokazania() === null;
    }

    public function photoPreparationFailed(): bool
    {
        return $this->avatar?->status === Media::STATUS_REJECTED
            && $this->zdjecieDoPokazania() === null;
    }
}
