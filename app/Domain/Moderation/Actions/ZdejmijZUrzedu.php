<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\ModeratedContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * „Zdejmij z urzędu” — usunięcie treści, której NIKT NIE ZGŁOSIŁ (G31, D-251).
 *
 * PO CO
 * Od #1446 moderator usuwa cudzą treść wyłącznie z panelu, a panel usuwał
 * wyłącznie rozstrzygnięciem zgłoszenia. Zgłoszenia złożonego przez siebie
 * moderator nie rozstrzyga (D-244). Spamu, którego nikt nie zgłosił, nie
 * dało się więc zdjąć wcale — przy jednoosobowej moderacji nawet przez
 * obejście „zgłoszę sam i poproszę kogoś”.
 *
 * JAK ZAPISANA JEST DECYZJA
 * Tym samym wierszem `moderation_actions` co decyzja ze zgłoszenia, z
 * `report_id = NULL` — bez sztucznego zgłoszenia. Decyzja z urzędu nie jest
 * „własną sprawą” z D-244: nie ma zgłaszającego, któremu moderator mógłby
 * rozstrzygnąć na korzyść, ani nikogo, komu art. 16 DSA każe odpisać.
 * Fikcyjne zgłoszenie wstawiłoby moderatora w rolę zgłaszającego — czyli
 * dokładnie w sytuację, której `ReportPolicy::decide()` zabrania — i
 * zasiliłoby statystyki zgłoszeń czymś, czego nikt nie zgłosił.
 * Autor czyta w uzasadnieniu „Nikt tego nie zgłosił — sprawę znaleźliśmy
 * sami” (`UzasadnienieDecyzji::skadSprawa()`, art. 17 ust. 3 lit. b),
 * a odwołanie idzie tą samą ścieżką co od każdej decyzji (`FileAppeal`).
 *
 * GRANICE
 *  - kto: `removeExOfficio` w polityce treści (2FA, niższa rola autora);
 *  - co: wpis, przepis, komentarz. „Ugotowałem” nie — patrz
 *    `CookedEventPolicy::removeExOfficio()`;
 *  - czyje: tylko treść WIDOCZNA DLA INNYCH (`widocznaDlaInnych()`) —
 *    szkicu i treści prywatnej moderator z urzędu nie ogląda ani nie zdejmuje
 *    (D-251 pkt „Zakres”);
 *  - kiedy nie: treść już zdjęta albo z otwartym zgłoszeniem. Tamto
 *    zgłoszenie ma swojego zgłaszającego i swój termin odpowiedzi — decyzja
 *    zapada tam, żeby nie było dwóch spraw o jedną treść.
 */
final class ZdejmijZUrzedu
{
    /** Typy, dla których ta droga istnieje — klucz to `target_type` w rejestrze. */
    public const TYPY = [
        'post' => Post::class,
        'recipe' => Recipe::class,
        'comment' => Comment::class,
    ];

    /**
     * Czy tę treść widzi ktoś poza autorem — czyli czy w ogóle jest czymś,
     * co moderacja „znajduje, przeglądając serwis” (D-251, zakres).
     *
     * Wpis i przepis: opublikowane, publiczne albo dla obserwujących. Szkic,
     * treść ukryta i treść prywatna — nie. Komentarz: opublikowany i pod
     * treścią widoczną dla innych (komentarz pod prywatnym wpisem widzi tylko
     * jego autor). „Ugotowałem” — tyle, ile przepis, z którego ugotowano.
     */
    public static function widocznaDlaInnych(?Model $tresc): bool
    {
        return match (true) {
            $tresc instanceof Post, $tresc instanceof Recipe => $tresc->isPublished()
                && in_array($tresc->visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS], true),
            $tresc instanceof Comment => $tresc->status === Comment::STATUS_PUBLISHED
                && self::widocznaDlaInnych($tresc->subject()),
            $tresc instanceof CookedEvent => self::widocznaDlaInnych($tresc->recipe),
            default => false,
        };
    }

    /** Czy przycisk „Zdejmij z urzędu” ma przy tej treści sens (bez martwych przycisków). */
    public static function dostepna(Model $tresc): bool
    {
        return self::widocznaDlaInnych($tresc) && ! ModeratedContent::jestZdjeta($tresc);
    }

    public function __construct(private readonly NotifyModerationDecision $powiadom) {}

    /**
     * @throws BladDlaCzlowieka gdy treść jest już zdjęta albo ma otwarte zgłoszenie
     * @throws AuthorizationException gdy Policy odmawia
     */
    public function handle(
        User $moderator,
        Model $target,
        string $reasonCode,
        string $userMessage,
        ?string $note = null,
        ?string $ip = null,
    ): ModerationAction {
        return DB::transaction(function () use ($moderator, $target, $reasonCode, $userMessage, $note, $ip): ModerationAction {
            $typ = ModeratedContent::typ($target);

            if ($typ === null || ! isset(self::TYPY[$typ])) {
                throw new BladDlaCzlowieka('Tej treści nie da się zdjąć z urzędu.');
            }

            // Blokada wiersza: dwa kliknięcia „Zdejmij” (dwie karty, dwóch
            // moderatorów) dają jedną decyzję, nie dwie.
            $cel = $target::query()->withTrashed()->whereKey($target->getKey())->lockForUpdate()->first();

            if ($cel === null || ModeratedContent::jestZdjeta($cel)) {
                throw new BladDlaCzlowieka('Ta treść jest już zdjęta. Odśwież stronę, żeby zobaczyć jej stan.');
            }

            // Zakres pod blokadą: autor mógł w międzyczasie schować treść.
            if (! self::widocznaDlaInnych($cel)) {
                throw new BladDlaCzlowieka('Tej treści nie widzi już nikt poza autorem, więc nie ma czego zdejmować z urzędu.');
            }

            // Policy pod blokadą — wynik ma zależeć od stanu, w którym
            // decyzja zapada (ten sam wzorzec co `decide()`, #1408).
            Gate::forUser($moderator)->authorize('removeExOfficio', $cel);

            $otwarte = Report::query()
                ->where('target_type', $typ)
                ->where('target_id', $cel->getKey())
                ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING])
                ->exists();

            if ($otwarte) {
                throw new BladDlaCzlowieka('Tę treść ktoś już zgłosił i sprawa czeka w kolejce zgłoszeń. '
                    .'Rozstrzygnij ją tam — zgłaszający dostanie wtedy odpowiedź. '
                    .'Jeśli to Twoje zgłoszenie, rozstrzygnie je ktoś inny z moderacji.');
            }

            $osoba = ModeratedContent::osoba($cel);

            $decyzja = ModerationAction::create([
                'moderator_id' => $moderator->getKey(),
                // NULL = decyzja z urzędu. Patrz komentarz klasy i D-251.
                'report_id' => null,
                'target_type' => $typ,
                'target_id' => $cel->getKey(),
                'subject_user_id' => $osoba?->getKey(),
                'action' => ModerationAction::ACTION_REMOVE,
                'previous_status' => $cel->status ?? null,
                'reason_code' => $reasonCode,
                'note' => $note,
                'user_message' => $userMessage,
            ]);

            // Ten sam mechanizm co „Usuń” ze zgłoszenia
            // (`RozstrzygnijZgloszenie::applyAction()`): miękkie usunięcie.
            // „Cofam” po odwołaniu przywraca je przez `RestoreContent`
            // — też tak samo jak decyzję ze zgłoszenia.
            $cel->delete();

            if ($osoba !== null) {
                $this->powiadom->handle(
                    osoba: $osoba,
                    decyzja: ModerationAction::ACTION_REMOVE,
                    wiadomoscModeratora: $userMessage,
                    decyzjaModeracyjna: $decyzja,
                );
            }

            AuditLogEntry::record(
                action: 'moderation.ex_officio',
                actor: $moderator,
                subject: $decyzja,
                metadata: [
                    'target_type' => $typ,
                    'target_id' => (string) $cel->getKey(),
                    'reason_code' => $reasonCode,
                ],
                ip: $ip,
            );

            return $decyzja;
        });
    }
}
