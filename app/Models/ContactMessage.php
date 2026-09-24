<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContactMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wiadomość z formularza „Napisz do nas" — od człowieka DO OPERATORA.
 *
 * To NIE JEST zgłoszenie treści (`Report`). Różnicę rozpisuje migracja
 * `2026_09_09_100000_create_contact_messages_table`; w skrócie: tam skarga na
 * cudzy wpis z decyzją moderatora i prawem do odwołania, tu zdanie o działaniu
 * serwisu, na które odpisuje właściciel.
 */
class ContactMessage extends Model
{
    /** @use HasFactory<ContactMessageFactory> */
    use HasFactory;

    use HasUuids;

    /** Coś nie działa. */
    public const KIND_BLAD = 'blad';

    /** Propozycja zmiany albo nowej rzeczy. */
    public const KIND_POMYSL = 'pomysl';

    /** Wszystko pozostałe — także „chcę tylko coś powiedzieć". */
    public const KIND_INNE = 'inne';

    /**
     * Kafelki na formularzu. Klucz idzie do bazy, wartość na ekran.
     *
     * TRZY, NIE JEDENAŚCIE JAK W `Report::REASONS`. Tamta lista musi
     * rozróżniać podstawy prawne, bo od kategorii zależy decyzja. Tutaj
     * kategoria służy wyłącznie do ustawienia kolejności w panelu — więcej
     * kafelków znaczyłoby dłuższe zastanawianie się przed napisaniem
     * pierwszego zdania, a to jest jedyna rzecz, którą ten formularz ma
     * ułatwiać.
     */
    public const RODZAJE = [
        self::KIND_BLAD => 'Coś nie działa',
        self::KIND_POMYSL => 'Mam pomysł',
        self::KIND_INNE => 'Coś innego',
    ];

    /** Nowa, jeszcze nietknięta. */
    public const STATUS_NOWA = 'new';

    /** Przeczytana, w robocie. */
    public const STATUS_W_TOKU = 'in_progress';

    /** Załatwiona — od tej chwili liczy się retencja. */
    public const STATUS_ZALATWIONA = 'done';

    public const STATUSY = [
        self::STATUS_NOWA => 'Nowa',
        self::STATUS_W_TOKU => 'W trakcie',
        self::STATUS_ZALATWIONA => 'Załatwiona',
    ];

    /**
     * `status`, `handled_by` i `handled_at` NIE SĄ TU CELOWO.
     *
     * AGENTS.md §7 zakazuje `status` i `role` użytkownika w `$fillable`,
     * bo zmiana stanu ma być jawną, nazwaną metodą. Ta sama zasada obowiązuje
     * tutaj: stan obsługi wiadomości ustawia OPERATOR, nie formularz. Gdyby
     * `status` był tu wymieniony, wystarczyłoby dopisać `status=done` do
     * żądania POST z publicznej trasy, żeby wiadomość wpadła do bazy od razu
     * jako załatwiona — i nikt by jej nigdy nie zobaczył w kolejce.
     *
     * Jedyna droga zmiany stanu to `oznaczJako()` niżej.
     */
    protected $fillable = [
        'user_id',
        'klucz_wyslania',
        'kind',
        'message',
        'contact_email',
        'page_path',
        'wydanie',
    ];

    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Listy, które WYSZŁY Z SERWISU w tej sprawie — najstarszy na górze
     * (D-058).
     *
     * Porządek jest częścią relacji, nie obowiązkiem widoku: historia
     * odpowiedzi czytana od najstarszej opowiada, jak sprawa szła, a ekran,
     * który by o tym zapomniał, pokazałby ją w kolejności fizycznej wierszy,
     * czyli w żadnej. `id` rozstrzyga remis na sekundzie w tę samą stronę co
     * czas (UUID v7), więc kolejność jest stabilna między odświeżeniami.
     *
     * TO NIE JEST WĄTEK KORESPONDENCJI. Odpowiedź człowieka na nasz list
     * wraca na skrzynkę `kontakt@kuking.pl`, bo serwis poczty nie odbiera —
     * granica jest opisana w D-058 i w nagłówku
     * `App\Mail\OdpowiedzNaWiadomosc`.
     *
     * @return HasMany<ContactMessageReply, $this>
     */
    public function odpowiedzi(): HasMany
    {
        return $this->hasMany(ContactMessageReply::class, 'contact_message_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function rodzajLabel(): string
    {
        return self::RODZAJE[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUSY[$this->status] ?? $this->status;
    }

    public function jestOtwarta(): bool
    {
        return $this->status !== self::STATUS_ZALATWIONA;
    }

    /**
     * Jedyna droga zmiany stanu obsługi.
     *
     * Ustawia komplet naraz (`status` + `handled_by` + `handled_at`), bo
     * CHECK `contact_messages_handled_complete` w bazie nie przyjmie połowy.
     * To nie jest utrudnienie — to jest to, co gwarantuje, że retencja
     * (liczona od `handled_at`) ma od czego liczyć.
     *
     * Powrót do stanu „nowa" czyści ślad obsługi. Wygląda to na utratę
     * informacji i jest nią świadomie: „nowa" znaczy „nikt tego jeszcze nie
     * tknął", a wiersz z `handled_at` i statusem `new` byłby zdaniem
     * wewnętrznie sprzecznym — i tak samo odrzuciłby go CHECK.
     *
     * TEN SAM STAN TO NIE JEST NOWE ZDARZENIE (#843). Zapis formularza
     * z niezmienionym stanem — dopisanie numeru issue do notatki zamkniętej
     * sprawy, ponowne wysłanie tego samego formularza — nie przepisuje
     * `handled_at` ani `handled_by`. Inaczej druga osoba poprawiająca
     * literówkę stawałaby się „tą, która załatwiła", a zegar retencji
     * (liczony od `handled_at`) startowałby od nowa przy każdej notatce.
     * Brudne pola ustawione wcześniej (notatka) zapisują się tym samym
     * `save()` — jeden zapis całego zamierzonego stanu.
     */
    public function oznaczJako(string $status, User $operator): void
    {
        $nowa = $status === self::STATUS_NOWA;

        if ($this->exists && $this->getOriginal('status') === $status) {
            $this->save();

            return;
        }

        $this->forceFill([
            'status' => $status,
            'handled_by' => $nowa ? null : $operator->getKey(),
            'handled_at' => $nowa ? null : now(),
        ])->save();
    }

    /**
     * Adres, pod którym da się tej osobie odpisać — albo `null`.
     *
     * Dla zalogowanego bierzemy adres Z KONTA, bo formularz go nie pyta
     * (kopiowanie adresu do drugiej tabeli byłoby powielaniem danych
     * osobowych bez powodu). Dla gościa — to, co sam podał.
     *
     * KONTO WYMAZANE NIE MA ADRESU, mimo że kolumna `users.email` nie jest
     * pusta (D-058). `EraseAccountData` wpisuje tam `usuniete+<uuid>@konto.
     * kuking.pl` — adres z naszej własnej domeny technicznej, pod którym
     * nie ma żadnej skrzynki. Do 10 września 2026 ta metoda oddawała go jak
     * każdy inny, więc karta wiadomości pokazywała `mailto:` prowadzące
     * w próżnię. Odkąd panel naprawdę wysyła listy, ta różnica przestała być
     * kosmetyczna: wysyłka pod taki adres albo odbije się do dostawcy, albo
     * — gorzej — zostanie przez niego przyjęta, a panel zamelduje „wysłano"
     * nad listem, którego nikt nigdy nie przeczyta.
     *
     * Fallback na `contact_email` zostaje w mocy i to nie jest przypadek:
     * wiadomość od GOŚCIA, który dopiero potem założył i usunął konto,
     * niesie własny adres w swojej kolumnie — ale przy koncie wymazanym
     * `contact_email` jest zwykle `NULL`, więc odpowiedzią jest `null`,
     * a ekran mówi wprost, że nie da się odpisać.
     */
    public function adresDoOdpowiedzi(): ?string
    {
        $autor = $this->author;

        if ($autor !== null && ! $autor->isErased()) {
            return $autor->email;
        }

        return $this->contact_email;
    }
}
