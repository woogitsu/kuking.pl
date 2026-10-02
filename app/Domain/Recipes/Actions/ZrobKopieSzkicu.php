<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use App\Support\LimityTekstuPrzepisu;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * „Zrób kopię” własnego szkicu do pracy nad drugim wariantem (#2507, V2, D-333 — paczka E).
 *
 * Osobna, nazwana akcja — NIE poszerzenie `fork` (to jest „Moja wersja” CUDZEGO,
 * opublikowanego przepisu, D-301). Powstaje niezależny, prywatny szkic
 * (`draft` / `private`, bez daty publikacji); źródło zostaje bez zmian.
 *
 * CO SIĘ KOPIUJE: tytuł (z dopiskiem „ — kopia”), opis, porcje i gotowe sztuki,
 * czasy, trudność, składniki (grupy, uwagi, zamienniki, „bez ilości”, ilość i
 * jednostka), kroki z minutnikami i nazwami etapów oraz rodzinne pochodzenie
 * własnej pracy (`source_*`, `family_since_year`). Nowe identyfikatory
 * przepisu, składników i kroków.
 *
 * CZEGO NIE: zdjęć (głównego, przy krokach, skanu kartki) — pierwszy etap
 * pomija media, żeby usunięcie jednego szkicu nie uszkodziło drugiego (bez
 * współdzielenia obiektów i bez kopiowania surowych uploadów); oznaczenia
 * alergenów i kosztu (autor ustala je od nowa dla wariantu); wersji publikacji,
 * wykonań, komentarzy, powiadomień, postępu gotowania, daty publikacji, „odłożenia”.
 *
 * ATRYBUCJA „MOJEJ WERSJI” ZOSTAJE. Gdy źródło jest adaptacją cudzego przepisu,
 * kopia dziedziczy `forked_from_id` i `forked_at` BEZ ZMIAN (także gdy oryginał
 * jest niedostępny) i `source_type = adaptation` — nie udaje przepisu własnego,
 * a `MojaWersja::pilnujRoznicy` nadal pilnuje oryginału przy publikacji. Kopia
 * dodatkowo nie wychodzi do ludzi bez zmian względem źródła
 * (`MojaWersja::pilnujRoznicyKopii`).
 *
 * JEDNO WYSŁANIE = JEDNA KOPIA. `$klucz` to tożsamość wysłania formularza;
 * powtórka (drugie kliknięcie, dwie karty) zwraca tę samą kopię, a nowy
 * formularz (nowy klucz) zakłada kolejny wariant. Stan i prawo sprawdzane są
 * POD BLOKADĄ (`users` → `recipes`), w jednej transakcji — błąd częściowy
 * nie zostawia pół kopii. Bez powiadomień, wpisów w strumieniach i wykonań.
 */
final class ZrobKopieSzkicu
{
    private const DOPISEK = ' — kopia';

    public function __construct(private readonly GenerateRecipeSlug $slugs) {}

    /**
     * @throws AuthorizationException
     * @throws BladDlaCzlowieka
     */
    public function handle(User $user, Recipe $zrodlo, string $klucz, ?string $ip = null): Recipe
    {
        return DB::transaction(function () use ($user, $zrodlo, $klucz, $ip): Recipe {
            // Konto tej samej osoby: serializuje dwa równoległe kliknięcia (drugie widzi kopię pierwszego).
            $swiezyUser = User::query()->whereKey($user->getKey())->lock('for no key update')->first();
            $swiezeZrodlo = Recipe::query()->whereKey($zrodlo->getKey())->lockForUpdate()->first();

            if ($swiezyUser === null || $swiezeZrodlo === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            // Ponowienie TEGO SAMEGO wysłania zwraca istniejącą kopię (klucz należy do tej osoby).
            $istniejaca = Recipe::withTrashed()
                ->where('author_id', $swiezyUser->getKey())
                ->where('klucz_wyslania', $klucz)
                ->first();

            if ($istniejaca !== null) {
                if ($istniejaca->trashed()) {
                    throw new BladDlaCzlowieka('Ta kopia została już usunięta. Zrób nową kopię z listy szkiców.');
                }

                return $istniejaca;
            }

            // Policy na ŚWIEŻYM stanie (sankcja konta, status szkicu, właściciel).
            Gate::forUser($swiezyUser)->authorize('copyDraft', $swiezeZrodlo);

            $atrybuty = [
                'author_id' => $swiezyUser->getKey(),
                'title' => $this->tytul($swiezeZrodlo->title),
                'summary' => $swiezeZrodlo->summary,
                'servings' => $swiezeZrodlo->servings,
                'yield_count' => $swiezeZrodlo->yield_count,
                'yield_unit' => $swiezeZrodlo->yield_unit,
                'prep_minutes' => $swiezeZrodlo->prep_minutes,
                'cook_minutes' => $swiezeZrodlo->cook_minutes,
                'difficulty' => $swiezeZrodlo->difficulty,
                'visibility' => 'private',
                'status' => Recipe::STATUS_DRAFT,
                'source_type' => $swiezeZrodlo->source_type,
                'source_url' => $swiezeZrodlo->source_url,
                'source_person' => $swiezeZrodlo->source_person,
                'source_note' => $swiezeZrodlo->source_note,
                'family_since_year' => $swiezeZrodlo->family_since_year,
                'published_at' => null,
            ];

            $kopia = $this->slugs->zapisz($swiezeZrodlo->title, static function (string $slug) use ($atrybuty, $swiezeZrodlo, $klucz): Recipe {
                $nowa = new Recipe([...$atrybuty, 'slug' => $slug]);
                // Pola sterujące i podpis — wyłącznie tu, nigdy z żądania.
                $nowa->forceFill([
                    'kopia_z_id' => $swiezeZrodlo->getKey(),
                    // Tożsamość wysłania (unikalna w parze z autorem, D-027) — to ten sam mechanizm co przy nowym przepisie.
                    'klucz_wyslania' => $klucz,
                    // Podpis „Mojej wersji” dziedziczony bez zmian (także gdy oryginał niedostępny).
                    'forked_from_id' => $swiezeZrodlo->forked_from_id,
                    'forked_at' => $swiezeZrodlo->forked_at,
                ])->save();

                return $nowa;
            });

            foreach ($swiezeZrodlo->ingredients()->get() as $skladnik) {
                RecipeIngredient::create([
                    'recipe_id' => $kopia->getKey(),
                    'group_name' => $skladnik->group_name,
                    'ingredient_id' => $skladnik->ingredient_id,
                    'ingredient_text' => $skladnik->ingredient_text,
                    'quantity' => $skladnik->quantity,
                    'unit_id' => $skladnik->unit_id,
                    'note' => $skladnik->note,
                    'substitutes' => $skladnik->substitutes,
                    'position' => $skladnik->position,
                    'no_amount' => $skladnik->no_amount,
                ]);
            }

            foreach ($swiezeZrodlo->steps()->get() as $krok) {
                RecipeStep::create([
                    'recipe_id' => $kopia->getKey(),
                    'position' => $krok->position,
                    'instruction' => $krok->instruction,
                    'media_id' => null,
                    'timer_seconds' => $krok->timer_seconds,
                    'section_name' => $krok->section_name,
                ]);
            }

            AuditLogEntry::record(
                action: 'recipe.draft_copied',
                actor: $swiezyUser,
                subject: $kopia,
                metadata: ['kopia_z_id' => (string) $swiezeZrodlo->getKey()],
                ip: $ip,
            );

            return $kopia;
        });
    }

    private function tytul(string $tytul): string
    {
        $maks = LimityTekstuPrzepisu::POLA['title'] - mb_strlen(self::DOPISEK);

        return mb_substr($tytul, 0, $maks).self::DOPISEK;
    }
}
