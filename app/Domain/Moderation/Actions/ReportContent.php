<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Zgłoszenie treści.
 *
 * Wymóg DSA (art. 16): mechanizm zgłaszania musi być łatwo dostępny i
 * przyjazny. U nas to znaczy: wyraźny przycisk z napisem "Zgłoś", nie ikonka
 * flagi, oraz powody napisane po polsku, a nie w żargonie prawniczym.
 *
 * To samo zgłoszenie od tej samej osoby nie tworzy duplikatów — zgłaszający
 * dostaje potwierdzenie, a kolejka moderacji nie puchnie od podwójnych kliknięć.
 *
 * TA OBIETNICA MA TERAZ POKRYCIE TAKŻE W BAZIE (ADR
 * `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` §3.4, §4.2).
 * `SELECT` niżej działał — zmierzone, przy podwójnym kliknięciu powstawał
 * jeden wiersz. Ale był to check-then-act: dwa równoległe żądania widziały
 * „brak zgłoszenia" jednocześnie, a ręczny `INSERT` z seedera albo komendy
 * konsolowej nie przechodził tą drogą wcale. Ograniczenie siedzi więc
 * w indeksie `reports_one_open_per_pair`, a ten `SELECT` ZOSTAJE — on daje
 * ciepłą ścieżkę „już to mamy" zamiast wyjątku z bazy. Indeks jest POD nim,
 * nie zamiast niego.
 *
 * `lockForUpdate()` NIE ZOSTAŁ DODANY i nie jest tu naprawą: `SELECT ... FOR
 * UPDATE`, który nie zwrócił wiersza, nie blokuje niczego, więc oba
 * połączenia wstawiają bez czekania (zmierzone, ADR §1.4.2). Dopisanie go
 * i napisanie „naprawione" byłoby obietnicą bez pokrycia w kodzie.
 *
 * BRAMKA WIDOCZNOŚCI ŻYJE TUTAJ, NIE W KONTROLERZE (audyt W7-05, AGENTS.md §4).
 * `ReportController` ma DWA wejścia na cel zgłoszenia — `create()` (formularz)
 * i `store()` (zapis, przez `handle()` niżej). Reguła sprawdzona tylko
 * w jednym z nich dałaby się ominąć drugim. Dlatego `authorize()` jest
 * publiczną metodą tej klasy: kontroler woła ją jawnie w `create()`, a
 * `handle()` woła ją SAMA na wstępie — więc nawet gdyby w przyszłości
 * powstał trzeci sposób wywołania `handle()` z pominięciem kontrolera,
 * bramka i tak zadziała.
 */
final class ReportContent
{
    private const TARGET_TYPES = [
        User::class => 'user',
        Post::class => 'post',
        Recipe::class => 'recipe',
        Comment::class => 'comment',
        CookedEvent::class => 'cooked_event',
    ];

    /**
     * Nazwa zdolności w Policy dla każdego typu celu. Domyślnie `view` —
     * `User` jest wyjątkiem, bo `UserPolicy` nie zna `view()`, tylko
     * `viewProfile()` (to ta sama bramka, co strona profilu pod `/@login`).
     */
    private const VIEW_ABILITY = [
        User::class => 'viewProfile',
    ];

    /**
     * Bramka widoczności celu (audyt W7-05).
     *
     * Zanim COKOLWIEK powstanie w tabeli `reports`, zgłaszający musi mieć
     * prawo ZOBACZYĆ to, co zgłasza — inaczej zgłoszenie samo w sobie jest
     * przeciekiem: wskazuje istnienie i typ treści, do której nie ma dostępu.
     *
     * Sprawdzenie idzie przez ISTNIEJĄCĄ Policy każdego typu celu, nie przez
     * powtórzenie jej warunków tutaj — warunki widoczności (blokady,
     * widoczność `followers`/`private`, zawieszone/zbanowane konto autora)
     * już raz są rozstrzygnięte w Policy i mają tam własne testy. Kopia
     * tych warunków w drugim miejscu prędzej czy później rozjedzie się
     * z oryginałem.
     *
     * Przy odmowie rzucamy TEN SAM wyjątek co przy nieznalezionym celu
     * (`findOrFail`/`firstOrFail` w kontrolerze rzucają dokładnie
     * `ModelNotFoundException`) — odpowiedź HTTP musi być 404 w OBU
     * przypadkach i NIEODRÓŻNIALNA. Inny status albo inny komunikat
     * zostawiałby otwartą furtkę: zalogowany mógłby po kodzie odpowiedzi
     * stwierdzić, które prywatne sluggi istnieją w bazie.
     *
     * Autor WŁASNEJ treści przechodzi przez tę bramkę bez przeszkód —
     * `RecipePolicy`/`PostPolicy::view()` i tak wpuszczają właściciela,
     * a zgłoszenie własnej treści ma sens (np. przejęte konto, które
     * publikuje coś w czyimś imieniu). Moderator przechodzi z tego samego
     * powodu — jego Policy już wpuszcza go wszędzie tam, gdzie ma zaglądać
     * z urzędu.
     */
    public function authorize(?User $reporter, Model $target): void
    {
        $ability = self::VIEW_ABILITY[$target::class] ?? 'view';

        if (Gate::forUser($reporter)->denies($ability, $target)) {
            throw (new ModelNotFoundException)->setModel($target::class, [$target->getKey()]);
        }
    }

    public function handle(
        ?User $reporter,
        Model $target,
        string $reason,
        ?string $details = null,
        ?string $ip = null,
    ): Report {
        $this->authorize($reporter, $target);

        $targetType = self::TARGET_TYPES[$target::class] ?? null;

        if ($targetType === null) {
            throw new BladDlaCzlowieka('Tej treści nie można zgłosić.');
        }

        if (! array_key_exists($reason, Report::REASONS)) {
            throw new BladDlaCzlowieka('Wybierz powód zgłoszenia.');
        }

        $existing = $this->otwarteZgloszenie($reporter, $targetType, $target->getKey());

        if ($existing !== null) {
            return $existing;
        }

        try {
            // Transakcja wokół JEDNEGO `INSERT`-a nie jest tu po atomowość —
            // ten `INSERT` i tak jest atomowy. Jest po to, żeby odrzucenie
            // wiersza przez indeks nie zostawiło po sobie ZERWANEJ transakcji
            // u wołającego: w PostgreSQL błąd unieważnia całą transakcję i
            // każde następne zapytanie w niej dostaje 25P02. Wycofanie do
            // punktu zapisu przywraca połączenie do stanu, w którym da się
            // jeszcze odczytać sprawę, która wyścig wygrała — a bez tego
            // akcja wołana wewnątrz cudzej transakcji (komenda, przyszły
            // endpoint, test) rozbijałaby ją zamiast oddać istniejący wiersz.
            $report = DB::transaction(fn (): Report => Report::create([
                'reporter_id' => $reporter?->getKey(),
                'target_type' => $targetType,
                'target_id' => $target->getKey(),
                'reason' => $reason,
                'details' => $details,
                'status' => Report::STATUS_OPEN,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // Indeks `reports_one_open_per_pair` odbił wiersz: między naszym
            // `SELECT`-em a tym `INSERT`-em zgłoszenie tej pary już powstało
            // (drugie żądanie, komenda konsolowa, seeder). Odczytujemy je
            // i oddajemy tak samo, jak wyżej oddajemy `$existing` — dla
            // zgłaszającego to jest ta sama, jedna sprawa.
            $rownolegle = $this->otwarteZgloszenie($reporter, $targetType, $target->getKey());

            if ($rownolegle === null) {
                // Tego nie umiemy wyjaśnić: baza odrzuciła wiersz, a sprawy,
                // o którą się odbił, nie widać. Wyjątek leci dalej, zamiast
                // udawać, że zgłoszenie zostało przyjęte — przy obowiązku
                // z DSA art. 16 ciche zgubienie sprawy jest najgorszym
                // z możliwych zachowań.
                throw $e;
            }

            return $rownolegle;
        }

        AuditLogEntry::record(
            action: 'content.reported',
            actor: $reporter,
            subject: $report,
            metadata: ['target_type' => $targetType, 'reason' => $reason],
            ip: $ip,
        );

        return $report;
    }

    /**
     * Otwarte zgłoszenie tej pary (zgłaszający, treść), jeśli już jest.
     *
     * Jedno zapytanie dla dwóch dróg — ciepłej („już to mamy", przed
     * zapisem) i awaryjnej (po odbiciu się o indeks) — żeby warunek
     * „co znaczy otwarte" nie rozjechał się między nimi. Ten sam zestaw
     * stanów jest w warunku indeksu `reports_one_open_per_pair`.
     */
    private function otwarteZgloszenie(?User $reporter, string $targetType, mixed $targetId): ?Report
    {
        return Report::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->when($reporter !== null, fn ($query) => $query->where('reporter_id', $reporter->getKey()))
            ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING])
            ->first();
    }
}
