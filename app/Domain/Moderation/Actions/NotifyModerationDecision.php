<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Powiadomienie o decyzji moderacyjnej — czyli o tym, że KTOŚ COŚ ZROBIŁ
 * Z MOIMI TREŚCIAMI ALBO Z MOIM KONTEM.
 *
 * PROBLEM, KTÓRY TO ZAMYKA (audyt A16)
 * `moderation_actions.user_message` istniało od początku i formularz moderatora
 * jasno pisze „Wymóg DSA: autor musi wiedzieć dlaczego i że może się odwołać".
 * Treść była zapisywana — i nigdzie nie wysyłana. W logu stało „ostrzeżono",
 * a człowiek nie dostawał niczego. Przy odwołaniu (DSA art. 17) to jest
 * najgorszy możliwy stan dokumentacji: papier mówi co innego niż rzeczywistość.
 *
 * DLACZEGO NIE `NotifyUser`
 * Ta akcja świadomie omija wspólną bramkę powiadomień, bo obie jej reguły są
 * tu szkodliwe:
 *
 *  - `NotifyUser` nie powiadamia kont nieaktywnych. Zawieszenie i blokada
 *    ustawiają status ZANIM powstaje powiadomienie, więc każda decyzja, która
 *    dotyka konta, przepadałaby po cichu — czyli dokładnie te decyzje, o
 *    których człowiek MUSI się dowiedzieć.
 *  - `NotifyUser` nie powiadamia przy blokadzie między osobami. Wiadomość od
 *    moderacji nie jest cudzą aktywnością, tylko decyzją serwisu wobec tej
 *    osoby — zablokowanie moderatora nie może jej wyciszyć.
 *
 * DLACZEGO `actor_id` JEST PUSTE
 * Powiadomienie pochodzi od serwisu, nie od człowieka. Gdyby autorem był
 * moderator, lista powiadomień pokazałaby jego zdjęcie i nazwę przy decyzji
 * o zawieszeniu konta — przy zespole 1-2 osób to jest wskazanie palcem
 * konkretnej osoby przez kogoś, kto właśnie dostał karę.
 *
 * CZEGO TO NIE ZAŁATWIA
 * Osoba ZBANOWANA nie wejdzie do serwisu, więc tego powiadomienia nie
 * przeczyta. Wiersz i tak powstaje: jest zapisem tego, co jej powiedzieliśmy
 * (DSA art. 17), trafia do eksportu danych (RODO art. 15) i staje się widoczny,
 * jeśli blokada zostanie zdjęta. Kanał, który zbanowany naprawdę widzi, to
 * komunikat przy logowaniu — i to on niesie treść od moderatora
 * (`EnsureAccountIsActive`, `LoginController`).
 */
final class NotifyModerationDecision
{
    /**
     * Nagłówek powiadomienia — pierwsze zdanie ma powiedzieć, CO SIĘ STAŁO.
     *
     * Rejestr „poważny" wg `docs/brand/COPY_STYLE.md` §3. Bez gry słowem
     * `kuKING` — D-009 zabrania jej w wiadomości moderacyjnej wprost.
     *
     * @var array<string, string>
     */
    private const TYTULY = [
        ModerationAction::ACTION_WARN => 'Ostrzeżenie od moderacji Kuking.',
        ModerationAction::ACTION_HIDE => 'Moderacja Kuking ukryła Twoją treść.',
        ModerationAction::ACTION_UNHIDE => 'Twoja treść jest z powrotem na miejscu.',
        ModerationAction::ACTION_REMOVE => 'Moderacja Kuking usunęła Twoją treść.',
        ModerationAction::ACTION_SUSPEND => 'Twoje konto zostało zawieszone.',
        ModerationAction::ACTION_BAN => 'Twoje konto zostało zablokowane.',
    ];

    /**
     * Treść zastępcza, gdy moderator nie napisał nic.
     *
     * Pole „Wiadomość do użytkownika" jest w formularzu nieobowiązkowe. Bez
     * tego zapasu powiadomienie o zawieszeniu konta byłoby samym nagłówkiem
     * i pustką pod spodem — a człowiek i tak zostałby bez odpowiedzi na
     * pytanie „co teraz mogę, a czego nie".
     *
     * @var array<string, string>
     */
    private const DOMYSLNE = [
        ModerationAction::ACTION_WARN => 'Zwracamy uwagę na Twoją ostatnią treść. Nic nie zostało ukryte ani usunięte.',
        ModerationAction::ACTION_HIDE => 'Ta treść nie jest już widoczna dla innych osób.',
        ModerationAction::ACTION_UNHIDE => 'Zdjęliśmy ukrycie. Treść wróciła do stanu sprzed decyzji — jeśli przed ukryciem była szkicem, jest nim dalej.',
        ModerationAction::ACTION_REMOVE => 'Ta treść została usunięta z serwisu.',
        ModerationAction::ACTION_SUSPEND => 'W czasie zawieszenia możesz czytać, ale nie opublikujesz wpisu ani komentarza.',
        ModerationAction::ACTION_BAN => 'To konto nie ma już dostępu do serwisu.',
    ];

    /**
     * @param  ?ModerationAction  $decyzjaModeracyjna  wiersz w logu, którego to
     *                                                 powiadomienie dotyczy. Bez niego przycisk „Odwołanie od tej
     *                                                 decyzji" nie ma dokąd prowadzić i zostaje samo zdanie z adresem
     *                                                 e-mail (tak wyglądają powiadomienia sprzed issue #10).
     */
    public function handle(
        User $osoba,
        string $decyzja,
        ?string $wiadomoscModeratora = null,
        ?CarbonInterface $do = null,
        ?ModerationAction $decyzjaModeracyjna = null,
    ): ?Notification {
        // „Bez działania" znaczy, że zgłoszenie zostało odrzucone i tej osobie
        // nic się nie stało. Powiadomienie byłoby tu szkodliwe: powiedziałoby
        // komuś, że został zgłoszony, przez kogoś, kogo nie wskazujemy.
        if (! isset(self::TYTULY[$decyzja])) {
            return null;
        }

        $wiadomosc = trim((string) $wiadomoscModeratora);

        return Notification::create([
            'user_id' => $osoba->getKey(),
            'actor_id' => null,
            'type' => Notification::TYPE_MODERATION,
            'data' => [
                'title' => $this->tytul($decyzja, $do),
                // Treść NAPISANA PRZEZ MODERATORA. To ona mówi, czego decyzja
                // dotyczy — sam fakt „dostałeś ostrzeżenie" nie mówi nic.
                'message' => $wiadomosc !== '' ? $wiadomosc : self::DOMYSLNE[$decyzja],
                'decision' => $decyzja,
                // Widok dokleja zdanie o odwołaniu z aktualnego adresu
                // kontaktowego. Zamrożenie adresu w `data` znaczyłoby, że po
                // jego zmianie stare powiadomienia wysyłają ludzi w próżnię.
                //
                // `false` przy przywróceniu treści: nikt nie odwołuje się od
                // dobrej wiadomości, a zdanie „jeśli uważasz, że to pomyłka"
                // pod komunikatem o zdjęciu ukrycia brzmi jak groźba.
                'appeal' => in_array($decyzja, ModerationAction::ODWOLYWALNE, true),
                // Adres formularza odwołania. Trzymamy sam identyfikator,
                // nie gotowy URL — trasy się zmieniają, historia powiadomień
                // zostaje na lata.
                'action_id' => $decyzjaModeracyjna?->getKey(),
            ],
        ]);
    }

    private function tytul(string $decyzja, ?CarbonInterface $do): string
    {
        // Termin w nagłówku, bo „do kiedy" to pierwsze pytanie osoby
        // zawieszonej. Data po polsku, nie ISO 8601 — `docs/UX_50_PLUS.md`.
        if ($decyzja === ModerationAction::ACTION_SUSPEND && $do !== null) {
            return 'Twoje konto jest zawieszone do '.$do->translatedFormat('j F Y').'.';
        }

        return self::TYTULY[$decyzja];
    }
}
