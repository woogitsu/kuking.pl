<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Comment;
use App\Models\Notification;

/**
 * Żywe wycinki komentarzy w powiadomieniach (issue #758, D-229) —
 * wydzielone z modelu `Notification` w etapie 2 issue #1687, bez zmiany
 * zachowania. Czytają ich lista powiadomień i eksport danych
 * (`CollectUserExportData`).
 */
final class WycinkiKomentarzy
{
    /**
     * AKTUALNE wycinki komentarzy dla podanych powiadomień — JEDNYM zapytaniem.
     *
     * DECYZJA WŁAŚCICIELA Z 20 WRZEŚNIA 2026 (issue #758, D-229): wycinek
     * treści komentarza liczy się PRZY WYŚWIETLANIU, z aktualnej treści.
     * Jedno źródło prawdy — nie zamrożona kopia w `notifications.data`.
     * Do tej zmiany `PublishComment` wpisywał do `data.excerpt` 120 znaków
     * z chwili publikacji i nikt tego nigdy nie odświeżał: ktoś pisał
     * „dodaję dwie łyżki masła", poprawiał w oknie 15 minut na „łyżeczki",
     * a powiadomienie — i paczka RODO na zawsze — dalej mówiło „łyżki".
     *
     * TA METODA NIE JEST FURTKĄ DOOKOŁA `scopeVisibleTo()`.
     * Warunki `status`/`deleted_at`/`body_removed_at` stoją tu drugi raz
     * ŚWIADOMIE, choć bramka z #757 odcina takie powiadomienia już przy
     * odczycie listy. Żywy wycinek czyta `comments` bezpośrednio, więc gdyby
     * kiedykolwiek zawołał go ekran BEZ `visibleTo()`, brak tych trzech
     * warunków przywróciłby do widoku treść, którą usunięcie ukryło —
     * na ekranie i w paczce RODO naraz. To jest najgroźniejsza regresja tej
     * zmiany i dlatego ma własny test
     * (`PowiadomienieSledziTrescKomentarzaTest`).
     *
     * WIDOCZNOŚCI TRESCI NADRZĘDNEJ tu NIE liczymy — to robi `visibleTo()`
     * dla konkretnego odbiorcy i to jest jedyne miejsce, które zna odbiorcę.
     * Brak wiersza w wyniku znaczy „bez wycinka", NIGDY „weź stary z `data`":
     * sięgnięcie po zamrożoną kopię jako zapasowy plan byłoby dokładnie tym
     * wyciekiem, przed którym broni warunek wyżej.
     *
     * KOSZT (D-196). Strona mieści 30 powiadomień, a eksport nie ma górnej
     * granicy — wycinek liczony po jednym komentarzu na wiersz dokładałby
     * jedno zapytanie na wiersz. Wzór jest ten sam co
     * `NotificationController::decyzje()`: zbieramy identyfikatory z całej
     * strony i pytamy raz.
     *
     * @param  iterable<Notification>  $powiadomienia
     * @return array<string, string> identyfikator powiadomienia → wycinek
     */
    public function zywe(iterable $powiadomienia): array
    {
        /** @var array<string, list<string>> $poKomentarzu */
        $poKomentarzu = [];

        foreach ($powiadomienia as $powiadomienie) {
            if (! in_array($powiadomienie->type, Notification::TYPY_Z_WYCINKIEM_KOMENTARZA, true)) {
                continue;
            }

            $komentarzId = ($powiadomienie->data ?? [])['comment_id'] ?? null;

            if (is_string($komentarzId) && $komentarzId !== '') {
                // Jeden komentarz potrafi mieć DWA powiadomienia (odpowiedź
                // w cudzym wątku idzie i do autora treści, i do autora
                // komentarza-rodzica), więc mapa jest jeden-do-wielu.
                $poKomentarzu[$komentarzId][] = (string) $powiadomienie->getKey();
            }
        }

        if ($poKomentarzu === []) {
            return [];
        }

        $wiersze = Comment::query()
            ->whereIn('id', array_keys($poKomentarzu))
            ->where('status', Comment::STATUS_PUBLISHED)
            ->whereNull('deleted_at')
            ->whereNull('body_removed_at')
            ->get(['id', 'body']);

        $wycinki = [];

        foreach ($wiersze as $komentarz) {
            $wycinek = mb_substr((string) $komentarz->body, 0, Notification::DLUGOSC_WYCINKA_KOMENTARZA);

            foreach ($poKomentarzu[(string) $komentarz->getKey()] ?? [] as $idPowiadomienia) {
                $wycinki[$idPowiadomienia] = $wycinek;
            }
        }

        return $wycinki;
    }
}
