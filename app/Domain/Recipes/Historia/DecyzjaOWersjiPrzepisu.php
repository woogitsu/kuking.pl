<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Historia;

use App\Domain\Moderation\Actions\NotifyModerationDecision;
use App\Domain\Moderation\Actions\RestoreContent;
use App\Domain\Moderation\CofniecieUkryciaWersji;
use App\Domain\Moderation\WlasnejTresciNiePrzywracasz;
use App\Domain\Users\ZamekUprzywilejowanegoAktora;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Ukrycie jednej wersji przepisu PRZEZ MODERACJĘ jako decyzja moderacyjna
 * w rozumieniu DSA (decyzja właściciela z 30.09.2026, D-333, wiersz #2270).
 *
 * TO NIE JEST DRUGI SYSTEM. Decyzja idzie dokładnie tą drogą co „Zdejmij
 * z urzędu” (`ZdejmijZUrzedu`, G31, D-251):
 *
 *  - wiersz `moderation_actions` z `target_type = 'recipe_version'`,
 *    `target_id` = UUID wersji, `subject_user_id` = autor przepisu,
 *    `report_id = NULL` (nikt nie zgłasza wersji — sprawę znaleźliśmy sami),
 *    podstawą z `PodstawaDecyzji` i uzasadnieniem dla autora (art. 17);
 *  - powiadomienie autora przez `NotifyModerationDecision` — z przyciskiem
 *    odwołania (`action_id`), bo `hide` jest w `ODWOLYWALNE` (art. 20);
 *  - odwołanie przez `FileAppeal`, rozpatrzenie przez `ResolveAppeal`, a „cofam"
 *    przywraca wersję przez `poOdwolaniu()` niżej;
 *  - raport przejrzystości liczy ją w sekcji „Decyzje z urzędu”, a jej
 *    cofnięcie w „Przywrócenia treści” — bez żadnej zmiany w komendzie.
 *
 * Sam STAN wersji (najnowsza, już ukryta, kto ukrył) rozstrzyga
 * `UkrywanieWersji` pod blokadą wiersza przepisu i wersji. Ta klasa podaje mu
 * domknięcie, które w tej samej transakcji zapisuje decyzję; bez niego strona
 * moderacji nie ukryje ani nie odsłoni niczego (`UkrywanieWersji::BEZ_DECYZJI`).
 *
 * Ukrycie przez AUTORA nie przechodzi tędy: to jego decyzja o własnej
 * treści, nie decyzja serwisu wobec niego.
 *
 * Klasa mieszka w `Recipes`, bo rządzi stanem wersji; `ResolveAppeal` sięga
 * po nią przez kontrakt `CofniecieUkryciaWersji` (graf modułów bez cykli).
 */
final class DecyzjaOWersjiPrzepisu implements CofniecieUkryciaWersji
{
    public function __construct(
        private readonly UkrywanieWersji $ukrywanie,
        private readonly NotifyModerationDecision $powiadom,
    ) {}

    /**
     * Zdanie dopisane na początku uzasadnienia — autor ma wiedzieć, KTÓREJ
     * treści dotyczy decyzja, a powiadomienie nie ma innego miejsca na
     * wskazanie celu. `user_message` to tekst, który człowiek realnie
     * zobaczył, więc zapisujemy go razem z tym zdaniem.
     */
    public static function wskazanie(Recipe $recipe, int $numer): string
    {
        return 'Dotyczy wersji '.$numer.' przepisu „'.$recipe->title.'” w historii zmian.';
    }

    /**
     * @return UkrywanieWersji::UKRYTO|UkrywanieWersji::PRZEJETO|UkrywanieWersji::JUZ_UKRYTA|UkrywanieWersji::NAJNOWSZA|UkrywanieWersji::BRAK_WERSJI
     */
    public function ukryj(
        User $moderator,
        Recipe $recipe,
        int $numer,
        string $reasonCode,
        string $userMessage,
        ?string $note = null,
        ?string $ip = null,
    ): string {
        $wiadomosc = self::wskazanie($recipe, $numer).' '.trim($userMessage);

        return ZamekUprzywilejowanegoAktora::wykonaj($moderator, function (User $swiezy) use ($recipe, $numer, $reasonCode, $wiadomosc, $note, $ip): string {
            if (UkrywanieWersji::strona($swiezy, $recipe) !== RecipeVersion::UKRYLA_MODERACJA) {
                throw new LogicException('Autor ukrywa własną wersję bez decyzji moderacyjnej — przez UkrywanieWersji.');
            }

            return $this->ukrywanie->ukryj($swiezy, $recipe, $numer, $ip, function (RecipeVersion $wersja) use ($swiezy, $recipe, $reasonCode, $wiadomosc, $note, $ip): ModerationAction {
                $wersja->setRelation('recipe', $recipe);

                // Policy na świeżym aktorze pod blokadą — ten sam wzorzec co
                // `ZdejmijZUrzedu` (degradacja w międzyczasie jest widoczna).
                Gate::forUser($swiezy)->authorize('hide', $wersja);

                $decyzja = ModerationAction::create([
                    'moderator_id' => $swiezy->getKey(),
                    // NULL = decyzja z urzędu (D-251): wersji się nie zgłasza.
                    'report_id' => null,
                    'target_type' => self::TYP,
                    'target_id' => $wersja->getKey(),
                    'subject_user_id' => $recipe->author_id,
                    'action' => ModerationAction::ACTION_HIDE,
                    // Przejęcie ukrycia autora pamięta stan sprzed decyzji przy
                    // samej decyzji (decyzja 30.09.2026): uznane odwołanie
                    // wraca do ukrycia autora, nie do widoczności. `$wersja`
                    // jest tu jeszcze w stanie sprzed zapisu (pod blokadą).
                    'previous_status' => $wersja->czyUkryta() && $wersja->hidden_by_role === RecipeVersion::UKRYL_AUTOR
                        ? RecipeVersion::STAN_PRZED_PRZEJECIEM
                        : null,
                    'reason_code' => $reasonCode,
                    'note' => $note,
                    'user_message' => $wiadomosc,
                ]);

                $autor = $recipe->author;
                if ($autor !== null) {
                    $this->powiadom->handle(
                        osoba: $autor,
                        decyzja: ModerationAction::ACTION_HIDE,
                        wiadomoscModeratora: $wiadomosc,
                        decyzjaModeracyjna: $decyzja,
                    );
                }

                AuditLogEntry::record(
                    action: 'moderation.ex_officio',
                    actor: $swiezy,
                    subject: $decyzja,
                    metadata: [
                        'target_type' => self::TYP,
                        'target_id' => (string) $wersja->getKey(),
                        'reason_code' => $reasonCode,
                    ],
                    ip: $ip,
                );

                return $decyzja;
            });
        });
    }

    /**
     * „Przywróć” z historii zmian przez moderację — cofnięcie własnej
     * decyzji serwisu, więc też decyzja (`unhide`) z powiadomieniem autora,
     * jak `RestoreContent`.
     *
     * @return UkrywanieWersji::PRZYWROCONO|UkrywanieWersji::NIE_BYLA_UKRYTA|UkrywanieWersji::UKRYTA_PRZEZ_DRUGA_STRONE|UkrywanieWersji::BRAK_WERSJI
     *
     * @throws BladDlaCzlowieka gdy ukrycie zapisał administrator, a przywraca moderator
     */
    public function przywroc(
        User $moderator,
        Recipe $recipe,
        int $numer,
        string $reasonCode,
        ?string $userMessage = null,
        ?string $ip = null,
    ): string {
        $tekst = trim((string) $userMessage);
        $wiadomosc = 'Wersja '.$numer.' przepisu „'.$recipe->title.'” jest znowu widoczna w historii zmian.'
            .($tekst === '' ? '' : ' '.$tekst);

        return ZamekUprzywilejowanegoAktora::wykonaj($moderator, fn (User $swiezy): string => $this->ukrywanie->przywroc(
            $swiezy,
            $recipe,
            $numer,
            $ip,
            function (RecipeVersion $wersja) use ($swiezy, $recipe, $reasonCode, $wiadomosc, $ip): ModerationAction {
                $wersja->setRelation('recipe', $recipe);
                Gate::forUser($swiezy)->authorize('restore', $wersja);

                // Reguła rangi B2-01 — ta sama metoda co przy treści.
                $zdjecie = self::ostatnieUkrycie($wersja);
                if ($zdjecie !== null && ! RestoreContent::wolnoCofnac($swiezy, $zdjecie)) {
                    throw new BladDlaCzlowieka('Tę wersję ukrył administrator. Cofnąć tę decyzję może tylko administrator — przekaż mu sprawę.');
                }

                return $this->zapiszPrzywrocenie($swiezy, $recipe, $wersja, $reasonCode, null, $wiadomosc, $ip, zPowiadomieniem: true);
            },
        ));
    }

    /**
     * „Cofam decyzję” po uznanym odwołaniu autora (`ResolveAppeal::cofnij()`).
     * Wołane pod zamkiem aktora, który trzyma `ResolveAppeal::handle()`.
     * Bez powiadomienia — odpowiedź na odwołanie (`NotifyAppealOutcome`)
     * mówi to samo lepiej.
     *
     * Gdy decyzja PRZEJĘŁA ukrycie autora (`previous_status`), wersja wraca
     * do ukrycia autora i metoda zwraca zdanie dla autora (decyzja z 30.09.2026).
     *
     * @throws WlasnejTresciNiePrzywracasz gdy rozpatrujący jest autorem przepisu
     * @throws BladDlaCzlowieka gdy nie ma czego przywracać (połyka `ResolveAppeal`)
     */
    #[\Override]
    public function poOdwolaniu(User $swiezy, ModerationAction $decyzja, string $uzasadnienie, ?string $ip = null): ?string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DecyzjaOWersjiPrzepisu::poOdwolaniu() wymaga transakcji z ZamekUprzywilejowanegoAktora.');
        }

        $wersja = RecipeVersion::query()->with('recipe')->find($decyzja->target_id);
        $recipe = $wersja?->recipe;

        // Wersję mogła w międzyczasie usunąć retencja albo autor razem
        // z przepisem — odwołanie i tak ma dostać odpowiedź.
        if ($wersja === null || $recipe === null) {
            throw new BladDlaCzlowieka('Tej wersji już nie ma — nie ma czego przywracać.');
        }

        if ($recipe->author_id === $swiezy->getKey()) {
            throw new WlasnejTresciNiePrzywracasz;
        }

        $zapisz = fn (RecipeVersion $zablokowana): ModerationAction => $this->zapiszPrzywrocenie(
            $swiezy,
            $recipe,
            $zablokowana,
            'appeal_overturned',
            'Cofnięte po odwołaniu.',
            $uzasadnienie,
            $ip,
            zPowiadomieniem: false,
        );

        // Decyzja, która PRZEJĘŁA ukrycie autora (decyzja 30.09.2026): stan
        // sprzed przejęcia zapisano przy samej decyzji, więc czytamy go stąd,
        // nie z audytu. Wersja wraca do ukrycia autora, nie do widoczności.
        if ($decyzja->previous_status === RecipeVersion::STAN_PRZED_PRZEJECIEM) {
            $wynik = $this->ukrywanie->zwrocAutorowi($swiezy, $recipe, $wersja->version_number, $ip, $zapisz);

            return $wynik === UkrywanieWersji::WROCILA_DO_AUTORA
                ? 'Wersja '.$wersja->version_number.' przepisu „'.$recipe->title.'” nie stała się publiczna — wróciła do stanu sprzed przejęcia, czyli do ukrycia przez autora. '
                    .'Możesz ją przywrócić sam w historii zmian przepisu, przyciskiem „Przywróć wersję '.$wersja->version_number.'”.'
                : null;
        }

        $this->ukrywanie->przywroc($swiezy, $recipe, $wersja->version_number, $ip, $zapisz);

        return null;
    }

    /** Ostatnia decyzja `hide`/`unhide` o tej wersji — `null`, gdy nie ma żadnej. */
    public static function ostatnieUkrycie(RecipeVersion $wersja): ?ModerationAction
    {
        $ostatnia = ModerationAction::query()
            ->with('moderator')
            ->where('target_type', self::TYP)
            ->where('target_id', $wersja->getKey())
            ->whereIn('action', [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_UNHIDE])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $ostatnia?->action === ModerationAction::ACTION_HIDE ? $ostatnia : null;
    }

    private function zapiszPrzywrocenie(
        User $swiezy,
        Recipe $recipe,
        RecipeVersion $wersja,
        string $reasonCode,
        ?string $note,
        ?string $wiadomosc,
        ?string $ip,
        bool $zPowiadomieniem,
    ): ModerationAction {
        $decyzja = ModerationAction::create([
            'moderator_id' => $swiezy->getKey(),
            'report_id' => null,
            'target_type' => self::TYP,
            'target_id' => $wersja->getKey(),
            'subject_user_id' => $recipe->author_id,
            'action' => ModerationAction::ACTION_UNHIDE,
            'previous_status' => null,
            'reason_code' => $reasonCode,
            'note' => $note,
            'user_message' => $wiadomosc,
        ]);

        $autor = $recipe->author;
        if ($zPowiadomieniem && $autor !== null) {
            $this->powiadom->handle(
                osoba: $autor,
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
                'target_type' => self::TYP,
                'target_id' => (string) $wersja->getKey(),
            ],
            ip: $ip,
        );

        return $decyzja;
    }
}
