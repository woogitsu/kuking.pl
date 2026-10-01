<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\ModeratedContent;
use App\Domain\Moderation\PodstawaDecyzji;
use App\Domain\Users\OdmowaOstatniegoAdministratora;
use App\Domain\Users\ZamekUprzywilejowanegoAktora;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\PrzeniesPubliczneWariantyDowodu;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\Media;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use App\Models\ZabezpieczenieDowodu;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use App\Support\ZabezpieczoneDowody;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * „CSAM — natychmiast ukryj i zabezpiecz” (D-333, 1 października 2026).
 *
 * Jedna akcja moderatora, która wykonuje kroki procedury zero-tolerancji
 * z `docs/legal/MODERATION_PLAYBOOK.md` §7.1 i `docs/flota/CSAM_JEDNA_KARTKA.md`
 * — nic ponad nie:
 *
 *  1. UKRYWA treść publicznie istniejącą drogą „Usuń treść” (miękkie
 *     usunięcie, `deleted_at`; §7.1 pkt 1–2). Wiersz, ID treści, ID konta
 *     i czas zostają;
 *  2. ZABEZPIECZA dowód przed każdą drogą kasowania: wiersz w
 *     `zabezpieczenia_dowodow` dla treści i dla KAŻDEGO jej zdjęcia, a zdjęcia
 *     dostają `status = secured` (nikt ich nie zobaczy przez aplikację —
 *     także moderator, §7.1 pkt 1 „nie ściągaj pliku”);
 *  3. BLOKUJE konto autora trwale (§7.1 pkt 4), jeśli rola moderatora na to
 *     pozwala (`sanctionAccount`); gdy nie — mówi to wprost w wyniku, a ukrycie
 *     i zabezpieczenie zostają wykonane (czekanie jest gorsze od połowicznego
 *     skutku);
 *  4. powiadamia autora JEDNYM neutralnym komunikatem (§7.1 pkt 4–5);
 *  5. zapisuje decyzję w `moderation_actions`, wpis w dzienniku audytu
 *     i — gdy akcja wychodzi ze zgłoszenia — zamyka to zgłoszenie
 *     i odpowiada zgłaszającemu jak przy każdej decyzji (DSA art. 16 ust. 5).
 *
 * CZEGO NIE ROBI: niczego nie wysyła na zewnątrz. Zgłoszenie do Dyżurnet.pl
 * albo Policji składa człowiek, według ekranu z instrukcją
 * (`ZabezpieczenieDowoduController::wynik()`).
 *
 * ZAKRES: wpis, przepis (razem z całą historią wersji), komentarz, zdjęcie.
 * „Ugotowałem” i konto — nie (patrz pytania do właściciela w D-333).
 */
final class ZabezpieczDowodCsam
{
    /** Zdanie z procedury (playbook §7.1 pkt 4) — dosłownie, bez własnych dopisków. */
    public const WIADOMOSC_BLOKADY = 'Twoje konto zostało trwale zablokowane z powodu naruszenia prawa.';

    /** @var array<string, class-string<Model>> */
    public const TYPY = [
        'post' => Post::class,
        'recipe' => Recipe::class,
        'comment' => Comment::class,
        'media' => Media::class,
    ];

    public function __construct(
        private readonly NotifyModerationDecision $powiadom,
        private readonly NotifyReporterDecision $powiadomZglaszajacego,
    ) {}

    /**
     * @throws BladDlaCzlowieka gdy treści nie da się zabezpieczyć (nie istnieje, już zabezpieczona, własna, zgłoszenie nie pasuje)
     * @throws AuthorizationException gdy Policy odmawia
     */
    public function handle(
        User $moderator,
        string $typ,
        string $id,
        ?string $reportId = null,
        ?string $note = null,
        ?string $ip = null,
    ): WynikZabezpieczeniaDowodu {
        // Wstępna odmowa nieautoryzowanemu żądaniu przed blokadą zdjęć;
        // rozstrzygający test roli powtarzamy na świeżym aktorze pod zamkiem.
        Gate::forUser($moderator)->authorize('secureCsam', User::class);

        return DB::transaction(function () use ($moderator, $typ, $id, $reportId, $note, $ip): WynikZabezpieczeniaDowodu {
            $klasa = self::TYPY[$typ] ?? throw new BladDlaCzlowieka('Tego rodzaju treści nie da się zabezpieczyć tą drogą.');

            // PublishRecipe blokuje zdjęcia przed kontem i przepisem (D-103).
            // Także tu zdjęcia muszą iść pierwsze: odwrotna kolejność daje
            // 40P01, gdy autor równocześnie zapisuje przepis ze zdjęciem.
            // Odczyt bez blokady wyznacza tylko kandydatów; po blokadzie treści
            // porównujemy listę ponownie i odmawiamy, gdy doszło nowe zdjęcie.
            $wstepny = $this->wczytaj($klasa, $id);

            if ($wstepny === null) {
                throw new BladDlaCzlowieka('Tej treści już nie ma w bazie, więc nie ma czego zabezpieczać.');
            }

            $mediaId = $this->zdjeciaTresci($typ, $wstepny);
            sort($mediaId, SORT_STRING);
            $wlasciciele = [];
            foreach ($mediaId as $media) {
                $zdjecie = Media::query()->whereKey($media)->lockForUpdate()->first();
                if ($zdjecie?->owner_id !== null) {
                    $wlasciciele[] = (string) $zdjecie->owner_id;
                }
            }

            $autorId = ModeratedContent::osoba($wstepny)?->getKey();
            if ($autorId !== null) {
                $wlasciciele[] = (string) $autorId;
            }
            $konta = array_values(array_unique($wlasciciele));

            return ZamekUprzywilejowanegoAktora::wykonaj($moderator, function (User $swiezy) use ($klasa, $typ, $id, $reportId, $note, $ip, $mediaId, $konta): WynikZabezpieczeniaDowodu {
                Gate::forUser($swiezy)->authorize('secureCsam', User::class);

                // Blokada wiersza treści: dwa kliknięcia (dwie karty, dwóch
                // moderatorów) dają jedno zabezpieczenie, nie dwa.
                $cel = $this->wczytaj($klasa, $id, zablokuj: true);

                if ($cel === null) {
                    throw new BladDlaCzlowieka('Tej treści już nie ma w bazie, więc nie ma czego zabezpieczać.');
                }

                if (ZabezpieczoneDowody::dotyczy($typ, $id)) {
                    throw new BladDlaCzlowieka('Ta treść jest już zabezpieczona jako dowód. Nie trzeba robić tego drugi raz.');
                }

                $osoba = ModeratedContent::osoba($cel);

                if ($osoba !== null && $osoba->getKey() === $swiezy->getKey()) {
                    throw new BladDlaCzlowieka('To Twoja własna treść. Przekaż sprawę innej osobie z moderacji albo właścicielowi serwisu.');
                }

                $zgloszenie = $reportId === null ? null : $this->zgloszenie($swiezy, $reportId, $typ, $id);

                $aktualneMedia = $this->zdjeciaTresci($typ, $cel);
                sort($aktualneMedia, SORT_STRING);
                if ($aktualneMedia !== $mediaId || ($osoba !== null && ! in_array((string) $osoba->getKey(), $konta, true))) {
                    throw new BladDlaCzlowieka('Treść zmieniła się w trakcie zabezpieczania. Nic nie zostało zrobione — spróbuj jeszcze raz.');
                }

                $decyzja = ModerationAction::create([
                    'moderator_id' => $swiezy->getKey(),
                    'report_id' => $zgloszenie?->getKey(),
                    'target_type' => $typ,
                    'target_id' => $id,
                    'subject_user_id' => $osoba?->getKey(),
                    'action' => ModerationAction::ACTION_REMOVE,
                    'previous_status' => $typ === 'media' ? null : ($cel->status ?? null),
                    'reason_code' => PodstawaDecyzji::KRZYWDZENIE_DZIECI,
                    'note' => $note,
                    // Puste: pójdzie neutralne zdanie domyślne (playbook §2, wiersz CSAM).
                    'user_message' => null,
                ]);

                // WPIS DLA TREŚCI (wpis, przepis, komentarz), potem dla każdego jej
                // zdjęcia. Zdjęcie jako cel dostaje wyłącznie własny wpis z pętli
                // niżej — z pamiętanym stanem sprzed zabezpieczenia.
                $wpis = $typ === 'media' ? null : $this->zarejestruj($swiezy, $typ, $id, $osoba?->getKey(), $zgloszenie, $decyzja, $note, null);

                [$zabezpieczone, $pominiete, $pierwszeZdjecie, $zabezpieczoneId] = $this->zabezpieczZdjecia($swiezy, $mediaId, $zgloszenie, $decyzja, $note);

                $wpis ??= $pierwszeZdjecie ?? throw new BladDlaCzlowieka(
                    'To zdjęcie jest już w trakcie kasowania i nie da się go zabezpieczyć. Przekaż sprawę właścicielowi serwisu — plik może jeszcze być w magazynie.',
                );

                // UKRYCIE: ta sama droga co „Usuń treść” (`RozstrzygnijZgloszenie`,
                // `ZdejmijZUrzedu`) — miękkie usunięcie. Zdjęcie ukrywa już status.
                if ($typ !== 'media' && method_exists($cel, 'trashed') && ! $cel->trashed()) {
                    $cel->delete();
                }

                // Po zatwierdzeniu: publiczne warianty zdjęć idą do prywatnego
                // magazynu, a cache CDN jest czyszczony. Plik oryginału zostaje.
                if ($zabezpieczoneId !== []) {
                    PrzeniesPubliczneWariantyDowodu::dispatch($zabezpieczoneId)->afterCommit();
                }

                $blokada = $this->zablokujKonto($swiezy, $osoba, $zgloszenie, $decyzja);

                // JEDNO powiadomienie dla autora: o blokadzie (zdanie z procedury),
                // a gdy blokady nie było — o usunięciu treści (zdanie domyślne).
                if ($osoba !== null) {
                    $blokada['zablokowano']
                        ? $this->powiadom->handle($osoba, ModerationAction::ACTION_BAN, self::WIADOMOSC_BLOKADY, null, $blokada['decyzja'])
                        : $this->powiadom->handle($osoba, ModerationAction::ACTION_REMOVE, null, null, $decyzja);
                }

                if ($zgloszenie !== null) {
                    $this->zamknijZgloszenie($zgloszenie, $swiezy, $decyzja, $note);
                }

                AuditLogEntry::record(
                    action: 'moderation.csam_secured',
                    actor: $swiezy,
                    subject: $wpis,
                    metadata: [
                        'target_type' => $typ,
                        'target_id' => $id,
                        'report_id' => $zgloszenie?->getKey(),
                        'moderation_action_id' => $decyzja->getKey(),
                        'media_secured' => $zabezpieczone,
                        'media_skipped' => $pominiete,
                        'account_banned' => $blokada['zablokowano'],
                    ],
                    ip: $ip,
                );

                return new WynikZabezpieczeniaDowodu(
                    zabezpieczenieId: (string) $wpis->getKey(),
                    zdjecZabezpieczonych: $zabezpieczone,
                    zdjecPominietych: $pominiete,
                    kontoZablokowane: $blokada['zablokowano'],
                    kontoJuzZablokowane: $blokada['juz'],
                    powodBrakuBlokady: $blokada['powod'],
                    zamknietoZgloszenie: $zgloszenie !== null,
                );
            }, $konta);
        });
    }

    /**
     * @param  class-string<Model>  $klasa
     */
    private function wczytaj(string $klasa, string $id, bool $zablokuj = false): ?Model
    {
        $zapytanie = $klasa::query();

        if (method_exists($klasa, 'bootSoftDeletes')) {
            $zapytanie->withoutGlobalScope(SoftDeletingScope::class);
        }

        $zapytanie->whereKey($id);

        return $zablokuj ? $zapytanie->lockForUpdate()->first() : $zapytanie->first();
    }

    /**
     * Zgłoszenie, z którego wychodzi akcja: otwarte, o tej samej treści,
     * rozstrzygane przez kogoś, kto wolno (`ReportPolicy::decide`, D-244).
     */
    private function zgloszenie(User $moderator, string $reportId, string $typ, string $id): Report
    {
        $zgloszenie = Report::query()->whereKey($reportId)->lockForUpdate()->first();

        if ($zgloszenie === null || $zgloszenie->target_type !== $typ || $zgloszenie->target_id !== $id) {
            throw new BladDlaCzlowieka('To zgłoszenie dotyczy innej treści. Wróć do kolejki i spróbuj jeszcze raz.');
        }

        if (! $zgloszenie->isOpen()) {
            throw new BladDlaCzlowieka('To zgłoszenie jest już rozstrzygnięte. Możesz zabezpieczyć treść bezpośrednio z jej strony.');
        }

        $odpowiedz = Gate::forUser($moderator)->inspect('decide', $zgloszenie);

        if ($odpowiedz->denied()) {
            throw new BladDlaCzlowieka((string) $odpowiedz->message());
        }

        return $zgloszenie;
    }

    /**
     * Identyfikatory zdjęć, które trzeba zabezpieczyć razem z treścią.
     *
     * @return list<string>
     */
    private function zdjeciaTresci(string $typ, Model $cel): array
    {
        $id = (string) $cel->getKey();

        $lista = match ($typ) {
            'media' => [$id],
            'post' => DB::table('post_media')->where('post_id', $id)->pluck('media_id')->all(),
            'recipe' => $cel instanceof Recipe ? [
                $cel->hero_media_id,
                $cel->source_scan_media_id,
                ...DB::table('recipe_steps')->where('recipe_id', $id)->whereNotNull('media_id')->pluck('media_id')->all(),
            ] : [],
            default => [],
        };

        return array_values(array_unique(array_map('strval', array_filter($lista))));
    }

    /**
     * @param  list<string>  $mediaId
     * @return array{0: int, 1: int, 2: ZabezpieczenieDowodu|null, 3: list<string>} [zabezpieczone, pominięte, pierwszy nowy wpis, id zabezpieczonych zdjęć]
     */
    private function zabezpieczZdjecia(User $moderator, array $mediaId, ?Report $zgloszenie, ModerationAction $decyzja, ?string $note): array
    {
        if ($mediaId === []) {
            return [0, 0, null, []];
        }

        $zabezpieczone = 0;
        $pominiete = 0;
        $pierwszy = null;
        $identyfikatory = [];

        foreach (Media::query()->whereKey($mediaId)->orderBy('id')->lockForUpdate()->get() as $zdjecie) {
            // Już zabezpieczone (przez inną treść) — nic do zrobienia, to nie błąd.
            if ($zdjecie->status === Media::STATUS_SECURED || ZabezpieczoneDowody::zdjecie((string) $zdjecie->getKey())) {
                continue;
            }

            // `deleted` = kasowanie już trwa i pliki znikają; nie ma czego
            // ratować, a CHECK rejestru nie zna takiego stanu. Mówimy o tym w wyniku.
            if ($zdjecie->status === Media::STATUS_DELETED) {
                $pominiete++;

                continue;
            }

            $wpis = $this->zarejestruj($moderator, 'media', (string) $zdjecie->getKey(), $zdjecie->owner_id, $zgloszenie, $decyzja, $note, $zdjecie->status);
            $pierwszy ??= $wpis;

            $zdjecie->forceFill(['status' => Media::STATUS_SECURED])->save();
            $zabezpieczone++;
            $identyfikatory[] = (string) $zdjecie->getKey();
        }

        return [$zabezpieczone, $pominiete, $pierwszy, $identyfikatory];
    }

    private function zarejestruj(
        User $moderator,
        string $typ,
        string $id,
        ?string $osobaId,
        ?Report $zgloszenie,
        ModerationAction $decyzja,
        ?string $note,
        ?string $poprzedniStatusZdjecia,
    ): ZabezpieczenieDowodu {
        $wpis = new ZabezpieczenieDowodu;
        $wpis->forceFill([
            'target_type' => $typ,
            'target_id' => $id,
            'subject_user_id' => $osobaId,
            'report_id' => $zgloszenie?->getKey(),
            'moderation_action_id' => $decyzja->getKey(),
            'secured_by' => $moderator->getKey(),
            'previous_media_status' => $poprzedniStatusZdjecia,
            'note' => $note,
        ])->save();

        return $wpis;
    }

    /**
     * Trwała blokada konta autora (playbook §7.1 pkt 4), o ile rola
     * moderatora na to pozwala.
     *
     * @return array{zablokowano: bool, juz: bool, powod: string|null, decyzja: ModerationAction|null}
     */
    private function zablokujKonto(User $moderator, ?User $osoba, ?Report $zgloszenie, ModerationAction $decyzjaTresci): array
    {
        $wynik = ['zablokowano' => false, 'juz' => false, 'powod' => null, 'decyzja' => null];

        if ($osoba === null) {
            return $wynik;
        }

        if ($osoba->status === User::STATUS_BANNED) {
            $wynik['juz'] = true;

            return $wynik;
        }

        if ($moderator->cannot('sanctionAccount', $osoba)) {
            $wynik['powod'] = $osoba->isAdmin()
                ? 'Konta administratora nie da się zablokować z panelu moderacji. Przekaż sprawę właścicielowi serwisu.'
                : 'Konto moderatora może zablokować tylko administrator. Przekaż mu sprawę.';

            return $wynik;
        }

        try {
            $osoba->ban();
        } catch (OdmowaOstatniegoAdministratora $odmowa) {
            $wynik['powod'] = $odmowa->getMessage();

            return $wynik;
        }

        // Druga decyzja jest „z urzędu” (bez `report_id`): jedno zgłoszenie =
        // jedna decyzja (indeks `moderation_actions_one_per_report`), a tę
        // jedną zajmuje usunięcie treści.
        $wynik['decyzja'] = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => null,
            'target_type' => 'user',
            'target_id' => $osoba->getKey(),
            'subject_user_id' => $osoba->getKey(),
            'action' => ModerationAction::ACTION_BAN,
            'previous_status' => null,
            'reason_code' => PodstawaDecyzji::KRZYWDZENIE_DZIECI,
            'note' => 'Blokada razem z zabezpieczeniem dowodu (decyzja '.$decyzjaTresci->getKey().').',
            'user_message' => self::WIADOMOSC_BLOKADY,
        ]);
        $wynik['zablokowano'] = true;

        return $wynik;
    }

    /** Zamknięcie zgłoszenia i odpowiedź zgłaszającemu — jak w `RozstrzygnijZgloszenie`. */
    private function zamknijZgloszenie(Report $zgloszenie, User $moderator, ModerationAction $decyzja, ?string $note): void
    {
        if ($zgloszenie->maAdresDoOdpowiedzi()) {
            Notification::route('mail', $zgloszenie->notifier_email)
                ->notify(new DecyzjaWSprawieZgloszenia($zgloszenie, $decyzja));
        }

        $this->powiadomZglaszajacego->handle($zgloszenie, $decyzja);

        $zgloszenie->update([
            'status' => Report::STATUS_RESOLVED,
            'resolution_note' => $note,
            'resolved_by' => $moderator->getKey(),
            'resolved_at' => now(),
        ]);
    }
}
