<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\WskazanieWskazowki;
use App\Domain\Users\ZamekUprzywilejowanegoAktora;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\RecipeHint;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * „Przywróć wskazówkę” — ręczne cofnięcie ukrycia wskazówki od gotujących
 * przez moderację, BEZ odwołania kucharza (#2352, decyzja właściciela
 * z 1.10.2026). Wzór: przywracanie wersji przepisu z historii zmian
 * (`DecyzjaOWersjiPrzepisu::przywroc`) — cofnięcie własnej decyzji serwisu
 * jest samo decyzją (`unhide`, powód w rejestrze, wpis w dzienniku) i
 * powiadamia osobę, której dotyczy.
 *
 * ZAMEK: jak ukrycie (`RozstrzygnijZgloszenie`) i „Wycofaj zgodę” — wszystkie
 * wiersze `users` w jednym posortowanym przebiegu (aktor, kucharz, autor
 * przepisu przez `ZamekUprzywilejowanegoAktora::wykonaj(..., $konta)`), potem
 * wiersz wskazówki. Inna kolejność zakleszczałaby się z wycofaniem zgody, gdy
 * rozstrzygający jest sam autorem przepisu.
 *
 * ZGODA KUCHARZA JEST WAŻNIEJSZA NIŻ DECYZJA MODERACJI. Zdejmujemy tylko ślad
 * moderacji (`moderation_hidden_at`). Jeśli kucharz w międzyczasie wycofał
 * zgodę, wskazówka zostaje wycofana: nic nie wraca publicznie, a kucharz nie
 * dostaje wiadomości, że „znów jest widoczna” — bo nie jest.
 *
 * LIMIT 10 NA PRZEPIS tu NIE obowiązuje (decyzja z 1.10.2026): ukryta
 * wskazówka zwolniła miejsce, więc przywrócenie może je przekroczyć. Limit
 * chroni przed zasypywaniem próśbami, nie przed cofnięciem decyzji
 * moderacji; nowe prośby autora czekają, aż liczba spadnie poniżej limitu
 * (`RecipeHint::scopeZajmujaceMiejsce`).
 */
final class PrzywrocWskazowke
{
    public function __construct(private readonly NotifyModerationDecision $powiadom) {}

    /**
     * Stan przycisku dla widoku kolejki — jedno źródło z regułami akcji, żeby
     * przycisk nie obiecywał tego, czego akcja odmówi (jak
     * `RestoreContent::wolnoCofnac`, #1748).
     *
     * @return 'przywroc'|'strona'|'tylko_admin'|null `null`: wskazówka nie jest
     *                                                ukryta przez moderację, więc nie ma czego przywracać
     */
    public static function stan(User $moderator, RecipeHint $wskazowka): ?string
    {
        if (! $wskazowka->jestUkrytaPrzezModeracje()) {
            return null;
        }

        $zdjecie = RestoreContent::zdjeciePrzezModeracje('recipe_hint', (string) $wskazowka->getKey(), false);

        if ($zdjecie === null) {
            return null;
        }

        return match (true) {
            $moderator->getKey() === $wskazowka->cook_id || $moderator->getKey() === $wskazowka->author_id => 'strona',
            ! RestoreContent::wolnoCofnac($moderator, $zdjecie) => 'tylko_admin',
            default => 'przywroc',
        };
    }

    /**
     * @return array{decyzja: ModerationAction, widoczna: bool} `widoczna`: czy wskazówka stoi
     *                                                          znów przy przepisie (`false`, gdy kucharz zdążył wycofać zgodę)
     *
     * @throws BladDlaCzlowieka gdy wskazówki nie da się przywrócić (komunikat mówi moderatorowi, co zrobić)
     */
    public function handle(User $moderator, string $wskazowkaId, string $reasonCode, ?string $userMessage = null, ?string $ip = null): array
    {
        $konta = RecipeHint::kontaDoBlokady($wskazowkaId);

        return ZamekUprzywilejowanegoAktora::wykonaj($moderator, function (User $swiezy) use ($wskazowkaId, $reasonCode, $userMessage, $ip): array {
            // Policy na świeżym aktorze pod blokadą: degradacja w międzyczasie
            // jest widoczna, a strona sprawy (kucharz, autor przepisu) odpada.
            $wskazowka = RecipeHint::zablokujDoDecyzji($wskazowkaId)
                ?? throw new BladDlaCzlowieka('Tej wskazówki już nie ma — nie ma czego przywracać.');

            Gate::forUser($swiezy)->authorize('moderate', User::class);

            if (Gate::forUser($swiezy)->denies('restore', $wskazowka)) {
                throw new BladDlaCzlowieka(
                    'To wskazówka z Twojego wykonania albo z Twojego przepisu, więc przywrócić ją może tylko ktoś inny z moderacji. '
                    .'Kucharz, który się nie zgadza z ukryciem, może się od niego odwołać.',
                );
            }

            if (! $wskazowka->jestUkrytaPrzezModeracje()) {
                throw new BladDlaCzlowieka('Ta wskazówka nie jest ukryta — nie ma czego przywracać. Odśwież stronę.');
            }

            // PRZYWRACAMY TYLKO TO, CO SCHOWAŁA MODERACJA (jak B2-01): ostatnia
            // decyzja o wskazówce musi być ukryciem, a decyzję administratora
            // cofa administrator.
            $zdjecie = RestoreContent::zdjeciePrzezModeracje('recipe_hint', (string) $wskazowka->getKey(), false);

            if ($zdjecie === null) {
                throw new BladDlaCzlowieka('Tej wskazówki nie ukryła moderacja w rejestrze decyzji, więc nie ma czego cofać. Przekaż sprawę administratorowi.');
            }

            if (! RestoreContent::wolnoCofnac($swiezy, $zdjecie)) {
                throw new BladDlaCzlowieka('Tę wskazówkę ukrył administrator. Cofnąć tę decyzję może tylko administrator — przekaż mu sprawę.');
            }

            $kucharz = $wskazowka->cook;
            $przepis = $wskazowka->recipe;
            $widoczna = $wskazowka->jestPrzyjeta();

            $tekst = trim((string) $userMessage);
            $wiadomosc = $przepis !== null && $widoczna
                ? trim(WskazanieWskazowki::przywrocenie($przepis).($tekst === '' ? '' : ' '.$tekst))
                : ($tekst === '' ? null : $tekst);

            $wskazowka->zdejmijUkrycieModeracji();

            $decyzja = ModerationAction::create([
                'moderator_id' => $swiezy->getKey(),
                // NULL: przywrócenie jest drugą decyzją (pierwszą było ukrycie),
                // a `moderation_actions_one_per_report` dopuszcza jedną na zgłoszenie.
                'report_id' => null,
                'target_type' => 'recipe_hint',
                'target_id' => $wskazowka->getKey(),
                'subject_user_id' => $wskazowka->cook_id,
                'action' => ModerationAction::ACTION_UNHIDE,
                'previous_status' => null,
                'reason_code' => $reasonCode,
                'note' => null,
                'user_message' => $wiadomosc,
            ]);

            // Po wycofaniu zgody nic nie wraca publicznie, więc i wiadomości nie ma.
            if ($widoczna && $kucharz !== null) {
                $this->powiadom->handle(
                    osoba: $kucharz,
                    decyzja: ModerationAction::ACTION_UNHIDE,
                    wiadomoscModeratora: $wiadomosc,
                    decyzjaModeracyjna: $decyzja,
                );
            }

            AuditLogEntry::record(
                action: 'moderation.restored',
                actor: $swiezy,
                subject: $decyzja,
                metadata: [
                    'target_type' => 'recipe_hint',
                    'target_id' => (string) $wskazowka->getKey(),
                    'widoczna' => $widoczna,
                ],
                ip: $ip,
            );

            return ['decyzja' => $decyzja, 'widoczna' => $widoczna];
        }, $konta);
    }
}
