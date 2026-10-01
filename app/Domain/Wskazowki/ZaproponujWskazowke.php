<?php

declare(strict_types=1);

namespace App\Domain\Wskazowki;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Social\ZamekPary;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * „Zaproponuj jako wskazówkę" (#2352, D-333): autor przepisu prosi kucharza
 * o zgodę na pokazanie uwagi z jego wykonania przy przepisie. Prośba NIC nie
 * publikuje — wskazówka staje przy przepisie dopiero po „Zgadzam się".
 *
 * KOLEJNOŚĆ BLOKAD to ta sama co w całym serwisie (`ZamekPary`, D-080): oba
 * konta rosnąco po id, potem wiersz wykonania. Dzięki temu prośba nie mija się
 * z równoległą blokadą (`BlockUser` bierze te same dwa wiersze), banem,
 * wymazaniem konta ani usunięciem wykonania. Policy jest pytana POD blokadą,
 * na wierszach odczytanych po jej założeniu — autoryzacja z kontrolera mogła
 * się zestarzeć, zanim ktokolwiek dostał zamek.
 *
 * Odmowa mówi jedno zdanie bez wskazywania powodu: „Nie" kucharza i
 * wycofanie zgody są ostateczne i nie wolno ich zdradzać autorowi słowami
 * ani różnicą komunikatów (autor nie może się dowiedzieć, że ktoś odmówił).
 */
final class ZaproponujWskazowke
{
    public const ODMOWA = 'Tej uwagi nie można teraz zaproponować jako wskazówki.';

    public function __construct(private readonly NotifyUser $notify) {}

    /**
     * @throws BladDlaCzlowieka
     */
    public function handle(User $autor, CookedEvent $wykonanie, ?string $ip = null): RecipeHint
    {
        $kucharz = User::query()->whereKey($wykonanie->user_id)->first()
            ?? throw new BladDlaCzlowieka(self::ODMOWA);

        return ZamekPary::zablokuj($autor, $kucharz, function (?User $swiezyAutor, ?User $swiezyKucharz) use ($wykonanie, $ip): RecipeHint {
            if ($swiezyAutor === null || $swiezyKucharz === null) {
                throw new BladDlaCzlowieka(self::ODMOWA);
            }

            // Wykonanie pod blokadą: równoległe usunięcie poczeka na koniec tej
            // transakcji, a usunięte wcześniej zwróci `null`.
            $swieze = CookedEvent::query()->whereKey($wykonanie->getKey())->lockForUpdate()->first();
            $przepis = $swieze === null ? null : Recipe::query()->whereKey($swieze->recipe_id)->first();

            if ($swieze === null || $przepis === null || $swieze->user_id !== $swiezyKucharz->getKey()) {
                throw new BladDlaCzlowieka(self::ODMOWA);
            }

            $przepis->setRelation('author', $swiezyAutor);
            $swieze->setRelation('recipe', $przepis);
            $swieze->setRelation('user', $swiezyKucharz);

            if (Gate::forUser($swiezyAutor)->denies('propose', [RecipeHint::class, $swieze])) {
                throw new BladDlaCzlowieka(self::ODMOWA);
            }

            // Jedno wykonanie — jedna wskazówka w całym życiu. Odpowiedź ta sama
            // dla prośby czekającej, przyjętej, odrzuconej i wycofanej.
            if (RecipeHint::query()->where('cooked_event_id', $swieze->getKey())->exists()) {
                throw new BladDlaCzlowieka(self::ODMOWA);
            }

            $this->sprawdzLimity($swiezyAutor, $przepis);

            $wskazowka = new RecipeHint;
            $wskazowka->recipe_id = $przepis->getKey();
            $wskazowka->cooked_event_id = $swieze->getKey();
            $wskazowka->author_id = $swiezyAutor->getKey();
            $wskazowka->cook_id = $swiezyKucharz->getKey();
            $wskazowka->status = RecipeHint::STATUS_PROPOSED;
            $wskazowka->recipe_version_number = $przepis->versions()->reorder()->max('version_number');
            $wskazowka->save();

            // Powiadomienie w tej samej transakcji (jak „Ugotowałem"): prośba
            // bez wiadomości byłaby prośbą, o której nikt się nie dowie.
            $this->notify->handle(
                recipient: $swiezyKucharz,
                type: Notification::TYPE_HINT_PROPOSED,
                actor: $swiezyAutor,
                data: [
                    'hint_id' => (string) $wskazowka->getKey(),
                    'cooked_event_id' => (string) $swieze->getKey(),
                    'recipe_id' => (string) $przepis->getKey(),
                    'recipe_title' => $przepis->title,
                ],
            );

            AuditLogEntry::record(
                action: 'recipe_hint.proposed',
                actor: $swiezyAutor,
                subject: $wskazowka,
                metadata: ['recipe_id' => $przepis->getKey(), 'cooked_event_id' => $swieze->getKey()],
                ip: $ip,
            );

            return $wskazowka;
        });
    }

    private function sprawdzLimity(User $autor, Recipe $przepis): void
    {
        $naPrzepis = (int) config('kuking.wskazowki.na_przepis_max');
        // Miejsce zajmują przyjęte i czekające, które NIE wygasły; odrzucone,
        // wycofane, anulowane i wygasłe zwalniają je (decyzja z 1.10.2026).
        $aktywne = RecipeHint::query()
            ->where('recipe_id', $przepis->getKey())
            ->zajmujaceMiejsce()
            ->count();

        if ($aktywne >= $naPrzepis) {
            throw new BladDlaCzlowieka("Ten przepis ma już komplet wskazówek ({$naPrzepis}). Poczekaj na odpowiedzi albo wróć do tego później.");
        }

        $naDobe = (int) config('kuking.wskazowki.na_dobe_max');
        $dzis = RecipeHint::query()
            ->where('author_id', $autor->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($dzis >= $naDobe) {
            throw new BladDlaCzlowieka('Wysłano już dziś dużo próśb o wskazówki. Spróbuj jutro — kucharze dostają je w powiadomieniach i nie chcemy ich zasypywać.');
        }
    }
}
