<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzyskiwanie;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\KosztPrzepisu;
use App\Domain\Recipes\Porcje\GotoweSztuki;
use App\Domain\Recipes\StepTimer;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\DraftRestorePoint;
use App\Models\Recipe;
use App\Models\User;
use App\Support\KreatorPrzepisu\DanePublikacji;
use App\Support\KreatorPrzepisu\PodgladPrzepisu;
use App\Support\KreatorPrzepisu\WierszePrzepisu;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Odzyskanie wcześniejszego TEKSTU prywatnego szkicu (#2512, V2, D-333).
 *
 * JEDEN PUNKT NA SZKIC, nie historia. Punkt powstaje, gdy autor otwiera swój
 * istniejący szkic w kreatorze (`zachowajPrzedEdycja`) i szkic nie ma jeszcze
 * ważnego punktu. Autozapis NIE tworzy wersji ani punktu — reguła „autozapis
 * nie tworzy wersji” (24.09.2026) zostaje. Ponowne otwarcie po pomyłce NIE
 * podmienia punktu: użyteczna kopia nie zostaje zastąpiona już uszkodzonym
 * stanem. Punkt znika po `kuking.przepisy.szkic_punkt_odzyskania_dni` dniach
 * (`PrzedawnionePunktyOdzyskaniaSzkicu`), przy usunięciu szkicu (klucz obcy)
 * i przy wymazaniu konta.
 *
 * ZAKRES: tytuł, opis, porcje, gotowe sztuki, czasy, trudność, „Od kogo albo
 * skąd…”, „Historia tego przepisu”, rok, składniki z grupami, zamiennikami i
 * „Bez ilości”, kroki z nazwą etapu i minutnikiem. NIE wchodzą: zdjęcia (kopia
 * trzyma tylko wskazanie przy kroku), alergeny, koszt, widoczność, rodzaj i
 * adres źródła oraz skan kartki — to pola sterujące albo zablokowane regułami
 * pochodzenia; przywrócenie ich nie dotyka.
 *
 * PRZYWRÓCENIE idzie przez `PublishRecipe` (zapis szkicu, nie publikacja), więc
 * ma te same blokady, walidację, kontrolę rewizji treści i ochronę zdjęć co
 * kreator. Po nim punkt zawiera tekst, który został zastąpiony — przywrócenie
 * można cofnąć tym samym przyciskiem; nie tracimy nowszej pracy.
 *
 * ZDJĘCIA: przywrócenie nie może po cichu odpiąć zdjęcia kroku. Jeśli jakiekolwiek
 * obecne zdjęcie kroku nie ma odpowiednika w kopii, przywrócenie jest odmawiane
 * z komunikatem (`ZDJECIA`) i nic się nie zmienia. Zdjęcie z kopii wraca do kroku
 * tylko wtedy, gdy nadal jest podpięte do któregoś kroku tego szkicu.
 */
final class PunktOdzyskaniaSzkicu
{
    public const ODZYSKANO = 'odzyskano';

    public const BRAK_KOPII = 'brak_kopii';

    public const BEZ_ZMIAN = 'bez_zmian';

    public const KONFLIKT = 'konflikt';

    public const ZDJECIA = 'zdjecia';

    public const BLAD = 'blad';

    public const NIE_SZKIC = 'nie_szkic';

    /** Format znacznika kopii w formularzu (mikrosekundy odróżniają dwie kopie w jednej sekundzie). */
    public const FORMAT_ZNACZNIKA = 'Y-m-d\TH:i:s.u\Z';

    /** @var array<string, string> klucz migawki => etykieta w podglądzie */
    public const POLA = [
        'title' => 'Nazwa przepisu',
        'summary' => 'Krótki opis',
        'servings' => 'Porcje',
        'yield_count' => 'Gotowe sztuki (liczba)',
        'yield_unit' => 'Gotowe sztuki (co)',
        'prep_minutes' => 'Czas przygotowania (minuty)',
        'cook_minutes' => 'Czas gotowania (minuty)',
        'difficulty' => 'Trudność',
        'source_person' => 'Od kogo albo skąd masz ten przepis',
        'source_note' => 'Historia tego przepisu',
        'family_since_year' => 'W rodzinie od roku',
    ];

    /**
     * Zapamiętuje tekst szkicu sprzed sesji edycji, jeśli szkic nie ma jeszcze
     * ważnego punktu. Nie rzuca wyjątku: otwarcie kreatora nie może zależeć od
     * tej funkcji.
     */
    public function zachowajPrzedEdycja(Recipe $szkic): bool
    {
        if ($szkic->status !== Recipe::STATUS_DRAFT || $szkic->published_at !== null) {
            return false;
        }

        try {
            $migawka = $this->migawka($szkic);
            $prog = now()->subDays($this->dni());

            return (bool) DB::transaction(function () use ($szkic, $migawka, $prog): int {
                // Przedawniony, jeszcze niesprzątnięty punkt nie blokuje nowego.
                DB::table('draft_restore_points')
                    ->where('recipe_id', $szkic->getKey())
                    ->where('taken_at', '<', $prog)
                    ->delete();

                return DB::table('draft_restore_points')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'recipe_id' => $szkic->getKey(),
                    'user_id' => $szkic->author_id,
                    'snapshot' => json_encode($migawka, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'taken_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Ważny punkt własnego szkicu albo `null`. */
    public function waznyPunkt(Recipe $szkic): ?DraftRestorePoint
    {
        return DraftRestorePoint::query()
            ->where('recipe_id', $szkic->getKey())
            ->where('user_id', $szkic->author_id)
            ->where('taken_at', '>', now()->subDays($this->dni()))
            ->first();
    }

    /**
     * Dane podglądu: co jest teraz, co jest w kopii, czym się różni.
     *
     * @return array{punkt: DraftRestorePoint, znacznik: string, rewizja: int, pola: list<array{etykieta: string, teraz: string, kopia: string}>, skladnikiTeraz: list<string>, skladnikiKopia: list<string>, krokiTeraz: list<string>, krokiKopia: list<string>, zmieniaSkladniki: bool, zmieniaKroki: bool, rozni: bool, zdjeciaBlokuja: bool, wygasa: CarbonImmutable}
     */
    public function podglad(Recipe $szkic): ?array
    {
        $punkt = $this->waznyPunkt($szkic);

        if ($punkt === null) {
            return null;
        }

        $teraz = $this->migawka($szkic);
        $kopia = $punkt->snapshot;

        $pola = [];
        foreach (self::POLA as $klucz => $etykieta) {
            $a = $this->tekstPola($teraz[$klucz] ?? null);
            $b = $this->tekstPola($kopia[$klucz] ?? null);
            if ($a !== $b) {
                $pola[] = ['etykieta' => $etykieta, 'teraz' => $a, 'kopia' => $b];
            }
        }

        $skladnikiTeraz = $this->opisSkladnikow($teraz['ingredients'] ?? []);
        $skladnikiKopia = $this->opisSkladnikow($kopia['ingredients'] ?? []);
        $krokiTeraz = $this->opisKrokow($teraz['steps'] ?? []);
        $krokiKopia = $this->opisKrokow($kopia['steps'] ?? []);

        $zmieniaSkladniki = $skladnikiTeraz !== $skladnikiKopia;
        $zmieniaKroki = $krokiTeraz !== $krokiKopia;

        return [
            'punkt' => $punkt,
            'znacznik' => $punkt->taken_at->utc()->format(self::FORMAT_ZNACZNIKA),
            'rewizja' => $szkic->content_revision,
            'pola' => $pola,
            'skladnikiTeraz' => $skladnikiTeraz,
            'skladnikiKopia' => $skladnikiKopia,
            'krokiTeraz' => $krokiTeraz,
            'krokiKopia' => $krokiKopia,
            'zmieniaSkladniki' => $zmieniaSkladniki,
            'zmieniaKroki' => $zmieniaKroki,
            'rozni' => $pola !== [] || $zmieniaSkladniki || $zmieniaKroki,
            'zdjeciaBlokuja' => $this->zdjeciaBlokuja($szkic, $kopia),
            'wygasa' => $punkt->taken_at->addDays($this->dni()),
        ];
    }

    /**
     * Przywraca tekst z kopii do szkicu, który widział autor (rewizja i znacznik
     * z podglądu). Nic nie publikuje i nikogo nie powiadamia.
     */
    public function przywroc(User $user, string $idSzkicu, int $widzianaRewizja, string $widzianyZnacznik): WynikOdzyskaniaTekstuSzkicu
    {
        $swiezy = User::query()->find($user->getKey());

        if ($swiezy === null || ! $swiezy->isActive() || $swiezy->data_erased_at !== null) {
            return new WynikOdzyskaniaTekstuSzkicu(self::BLAD, 'Stan Twojego konta zmienił się. Odśwież stronę i spróbuj ponownie.');
        }

        $szkic = Recipe::query()
            ->whereKey($idSzkicu)
            ->where('author_id', $swiezy->getKey())
            ->first();

        if ($szkic === null) {
            return new WynikOdzyskaniaTekstuSzkicu(self::BRAK_KOPII, null);
        }

        if ($szkic->status !== Recipe::STATUS_DRAFT || $szkic->published_at !== null) {
            return new WynikOdzyskaniaTekstuSzkicu(self::NIE_SZKIC, null);
        }

        $punkt = $this->waznyPunkt($szkic);

        if ($punkt === null) {
            return new WynikOdzyskaniaTekstuSzkicu(self::BRAK_KOPII, null);
        }

        // Kopia podmieniona w innej karcie (np. po przywróceniu) albo szkic
        // zapisany po otwarciu podglądu: nowsza praca nie jest nadpisywana.
        if ($punkt->taken_at->utc()->format(self::FORMAT_ZNACZNIKA) !== $widzianyZnacznik
            || $szkic->content_revision !== $widzianaRewizja) {
            return new WynikOdzyskaniaTekstuSzkicu(self::KONFLIKT, null);
        }

        $kopia = $punkt->snapshot;
        $przedPrzywroceniem = $this->migawka($szkic);

        if ($this->jednakowe($przedPrzywroceniem, $kopia)) {
            return new WynikOdzyskaniaTekstuSzkicu(self::BEZ_ZMIAN, null);
        }

        if ($this->zdjeciaBlokuja($szkic, $kopia)) {
            return new WynikOdzyskaniaTekstuSzkicu(self::ZDJECIA, null);
        }

        $zdjeciaTeraz = $szkic->steps->pluck('media_id')->filter()->values()->all();

        $atrybuty = DanePublikacji::atrybuty(
            title: (string) ($kopia['title'] ?? ''),
            summary: (string) ($kopia['summary'] ?? ''),
            servings: PodgladPrzepisu::liczbaNaTekst($kopia['servings'] ?? null),
            estimatedCostPln: KosztPrzepisu::doPola($szkic->estimated_cost_pln),
            prepMinutes: PodgladPrzepisu::liczbaNaTekst($kopia['prep_minutes'] ?? null),
            cookMinutes: PodgladPrzepisu::liczbaNaTekst($kopia['cook_minutes'] ?? null),
            difficulty: (string) ($kopia['difficulty'] ?? ''),
            visibility: (string) $szkic->visibility,
            sourceType: (string) $szkic->source_type,
            sourcePerson: (string) ($kopia['source_person'] ?? ''),
            sourceNote: (string) ($kopia['source_note'] ?? ''),
            sourceUrl: (string) $szkic->source_url,
            familySinceYear: PodgladPrzepisu::liczbaNaTekst($kopia['family_since_year'] ?? null),
            heroMediaId: $szkic->hero_media_id,
            sourceScanMediaId: $szkic->source_scan_media_id,
            sprawdzilemOdczyt: false,
            odczytSprawdzony: false,
            yieldCount: GotoweSztuki::doPola($kopia['yield_count'] ?? null),
            yieldUnit: (string) ($kopia['yield_unit'] ?? ''),
        );

        $skladniki = WierszePrzepisu::skladniki(array_map(static fn (array $w): array => [
            'text' => $w['text'] ?? '',
            'group_name' => $w['group_name'] ?? null,
            'note' => $w['note'] ?? null,
            'substitutes' => $w['substitutes'] ?? null,
            'no_amount' => (bool) ($w['no_amount'] ?? false),
        ], $kopia['ingredients'] ?? []));

        $kroki = WierszePrzepisu::kroki(array_map(static fn (array $k): array => [
            'instruction' => $k['instruction'] ?? '',
            'section_name' => $k['section_name'] ?? null,
            'timer_minutes' => PodgladPrzepisu::liczbaNaTekst(StepTimer::minutesFromSeconds(isset($k['timer_seconds']) ? (int) $k['timer_seconds'] : null)),
            // Zdjęcie z kopii wraca tylko, gdy wciąż jest podpięte w tym szkicu.
            'mediaId' => isset($k['media_id']) && in_array($k['media_id'], $zdjeciaTeraz, true) ? $k['media_id'] : null,
        ], $kopia['steps'] ?? []));

        try {
            app(PublishRecipe::class)->handle(
                author: $swiezy,
                attributes: $atrybuty,
                ingredients: $skladniki,
                steps: $kroki,
                publish: false,
                existing: $szkic,
                oczekiwanaRewizja: $widzianaRewizja,
                wersjaPoprawki: false,
                ip: request()->ip(),
            );
        } catch (BladDlaCzlowieka $e) {
            return new WynikOdzyskaniaTekstuSzkicu(self::BLAD, $e->getMessage());
        }

        // Punkt trzyma teraz tekst, który właśnie zastąpiono — przywrócenie da się cofnąć.
        DB::transaction(function () use ($punkt, $przedPrzywroceniem, $widzianyZnacznik): void {
            $wiersz = DraftRestorePoint::query()->whereKey($punkt->getKey())->lockForUpdate()->first();

            if ($wiersz === null || $wiersz->taken_at->utc()->format(self::FORMAT_ZNACZNIKA) !== $widzianyZnacznik) {
                return;
            }

            $wiersz->forceFill(['snapshot' => $przedPrzywroceniem, 'taken_at' => now()])->save();
        });

        return new WynikOdzyskaniaTekstuSzkicu(self::ODZYSKANO, null);
    }

    /**
     * Migawka tekstu szkicu. Sam tekst i wskazanie zdjęcia przy kroku.
     *
     * @return array<string, mixed>
     */
    public function migawka(Recipe $szkic): array
    {
        $szkic->unsetRelation('ingredients');
        $szkic->unsetRelation('steps');
        $szkic->load(['ingredients', 'steps']);

        return [
            'title' => $szkic->title,
            'summary' => $szkic->summary,
            'servings' => $szkic->servings,
            'yield_count' => $szkic->yield_count,
            'yield_unit' => $szkic->yield_unit,
            'prep_minutes' => $szkic->prep_minutes,
            'cook_minutes' => $szkic->cook_minutes,
            'difficulty' => $szkic->difficulty,
            'source_person' => $szkic->source_person,
            'source_note' => $szkic->source_note,
            'family_since_year' => $szkic->family_since_year,
            'ingredients' => $szkic->ingredients->map(static fn ($i): array => [
                'group_name' => $i->group_name,
                'text' => $i->ingredient_text,
                'note' => $i->note,
                'substitutes' => $i->substitutes,
                'no_amount' => (bool) $i->no_amount,
            ])->values()->all(),
            'steps' => $szkic->steps->map(static fn ($s): array => [
                'instruction' => $s->instruction,
                'section_name' => $s->section_name,
                'timer_seconds' => $s->timer_seconds,
                'media_id' => $s->media_id,
            ])->values()->all(),
        ];
    }

    /**
     * Czy istnieje obecne zdjęcie kroku, którego nie ma w kopii (przywrócenie
     * musiałoby je odpiąć).
     *
     * @param  array<string, mixed>  $kopia
     */
    private function zdjeciaBlokuja(Recipe $szkic, array $kopia): bool
    {
        $wKopii = [];
        foreach ($kopia['steps'] ?? [] as $krok) {
            if (! empty($krok['media_id'])) {
                $wKopii[] = $krok['media_id'];
            }
        }

        foreach ($szkic->steps as $krok) {
            if ($krok->media_id !== null && ! in_array($krok->media_id, $wKopii, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function jednakowe(array $a, array $b): bool
    {
        // Porównanie po zapisie JSON: jsonb nie zachowuje kolejności kluczy,
        // a liczby wracają z bazy w innym typie niż z modelu.
        return json_decode((string) json_encode($a), true) == json_decode((string) json_encode($b), true);
    }

    private function tekstPola(mixed $wartosc): string
    {
        $tekst = trim(PodgladPrzepisu::liczbaNaTekst(is_bool($wartosc) ? (int) $wartosc : $wartosc));

        return $tekst === '' ? '—' : $tekst;
    }

    /**
     * @param  list<array<string, mixed>>  $wiersze
     * @return list<string>
     */
    private function opisSkladnikow(array $wiersze): array
    {
        return array_map(static function (array $w): string {
            $tekst = (string) ($w['text'] ?? '');
            $dodatki = [];
            if (! empty($w['group_name'])) {
                $dodatki[] = 'grupa: '.$w['group_name'];
            }
            if (! empty($w['no_amount'])) {
                $dodatki[] = 'bez ilości';
            }
            if (! empty($w['note'])) {
                $dodatki[] = 'uwaga: '.$w['note'];
            }
            if (! empty($w['substitutes'])) {
                $dodatki[] = 'zamiennik: '.$w['substitutes'];
            }

            return $dodatki === [] ? $tekst : $tekst.' ('.implode('; ', $dodatki).')';
        }, array_values($wiersze));
    }

    /**
     * @param  list<array<string, mixed>>  $wiersze
     * @return list<string>
     */
    private function opisKrokow(array $wiersze): array
    {
        return array_map(static function (array $k): string {
            $tekst = (string) ($k['instruction'] ?? '');
            $dodatki = [];
            if (! empty($k['section_name'])) {
                $dodatki[] = 'etap: '.$k['section_name'];
            }
            $minuty = StepTimer::minutesFromSeconds(isset($k['timer_seconds']) ? (int) $k['timer_seconds'] : null);
            if ($minuty !== null) {
                $dodatki[] = 'minutnik: '.$minuty.' min';
            }

            return $dodatki === [] ? $tekst : $tekst.' ('.implode('; ', $dodatki).')';
        }, array_values($wiersze));
    }

    private function dni(): int
    {
        return max(1, (int) config('kuking.przepisy.szkic_punkt_odzyskania_dni'));
    }
}
