<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Models\Notification;
use App\Models\User;

/**
 * Jedno miejsce, przez które powstają wszystkie powiadomienia w aplikacji.
 *
 * Reguły wpisane tutaj, żeby nie trzeba było o nich pamiętać w 12 miejscach:
 *  - nie powiadamiamy nikogo o jego własnej akcji,
 *  - nie powiadamiamy, jeśli między osobami jest blokada,
 *  - nie powiadamiamy kont nieaktywnych,
 *  - powiadomienia o STANIE nie wracają w oknie doby (patrz niżej).
 */
final class NotifyUser
{
    /**
     * Rodzaje powiadomień, dla których POWTÓRZENIE nie niesie nowej
     * informacji — i dlatego są wyciszane w oknie z konfiguracji.
     *
     * PODZIAŁ, NA KTÓRYM TO STOI: STAN kontra ZDARZENIE.
     *
     * „X Cię obserwuje" to STAN. Ktoś, kto odobserwuje i wróci — na
     * przykład sprawdzając, czy dana osoba zniknie mu z tablicy — nie
     * przekazał drugiej stronie niczego nowego. Bez okna wysyłał jej trzy
     * identyczne powiadomienia, bo `UnfollowUser` robi twardy `detach()`,
     * więc kolejny `follow` widzi „nie obserwuję" i tworzy powiadomienie od
     * zera. Zmierzone: trzykrotny cykl dawał trzy powiadomienia.
     *
     * „X skomentował" to ZDARZENIE. Drugi komentarz tej samej osoby pod tym
     * samym wpisem jest NOWĄ rzeczą i musi dojść — inaczej wyciszamy
     * rozmowę, czyli dokładnie to, po co ten serwis istnieje. Dlatego lista
     * jest JAWNA i wąska, a nie „wszystko oprócz kilku wyjątków": przy
     * dodawaniu nowego rodzaju powiadomienia domyślną odpowiedzią ma być
     * „dochodzi zawsze", nie „bywa wyciszane".
     *
     * SAM LIMIT LICZBY ŻĄDAŃ TEGO NIE ZAŁATWIA. Limit `follow` 10/min
     * zatrzymuje spam w obrębie jednej minuty i nie dotyka wcale wzorca
     * rozłożonego na godziny. To jest problem semantyki, nie tempa.
     */
    private const TYPY_WYCISZANE_W_OKNIE = [
        Notification::TYPE_FOLLOW,
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $recipient, string $type, ?User $actor = null, array $data = []): ?Notification
    {
        if ($actor !== null && $actor->getKey() === $recipient->getKey()) {
            return null;
        }

        // Pytanie brzmi „czy ten człowiek może to jeszcze przeczytać",
        // a nie „czy konto jest aktywne". Zawieszenie odcina od PISANIA,
        // nie od życia serwisu — patrz `User::mozeCzytac()`.
        if (! $recipient->mozeCzytac()) {
            return null;
        }

        if ($actor !== null && $recipient->hasBlockRelationWith($actor)) {
            return null;
        }

        if ($this->juzBylo($recipient, $type, $actor)) {
            return null;
        }

        return Notification::create([
            'user_id' => $recipient->getKey(),
            'actor_id' => $actor?->getKey(),
            'type' => $type,
            'data' => $data,
        ]);
    }

    /**
     * Czy tej PARZE osób wysłano już takie powiadomienie w oknie.
     *
     * Okno dotyczy pary (nadawca → odbiorca), nie samego odbiorcy: dwie
     * różne osoby obserwujące tego samego człowieka to dwie różne
     * informacje i obie mają dojść.
     *
     * Bez `$actor` nie ma czego deduplikować — powiadomienie systemowe
     * (powitanie, decyzja moderacji) nie ma nadawcy i nie jest na tej
     * liście.
     */
    private function juzBylo(User $recipient, string $type, ?User $actor): bool
    {
        if ($actor === null || ! in_array($type, self::TYPY_WYCISZANE_W_OKNIE, true)) {
            return false;
        }

        $godziny = (int) config('kuking.notifications.okno_powtorzenia_godzin', 24);

        return Notification::query()
            ->where('user_id', $recipient->getKey())
            ->where('actor_id', $actor->getKey())
            ->where('type', $type)
            ->where('created_at', '>=', now()->subHours($godziny))
            ->exists();
    }
}
