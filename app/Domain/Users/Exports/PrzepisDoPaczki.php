<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Models\Recipe;
use Carbon\CarbonInterface;
use Closure;

/**
 * Pola jednego własnego przepisu w `dane.json` — JEDNO mapowanie dla dwóch dróg
 * (#2531): pełnej paczki konta (`CollectUserExportData::recipes()`) i kopii
 * jednego przepisu (`KopiaJednegoPrzepisu`). Dzięki temu dwie drogi nie
 * rozjeżdżają się w nazwach ani znaczeniu pól, a importer paczki
 * (`PodgladPaczkiEksportu`) czyta oba tak samo.
 *
 * Klasa mapuje TYLKO to, co jest tekstem i ustawieniami samego przepisu. Nie
 * dotyka komentarzy innych osób, liczby wykonań przez innych ani tytułu cudzego
 * oryginału („Moja wersja”) — te trzy są dopiero w pełnej paczce, w miejscu
 * wywołania. Zdjęcia rozwiązuje wywołujący (`$sciezkaZdjecia`): pełna paczka
 * podaje ścieżki plików, kopia jednego przepisu — zawsze `null`.
 *
 * Relacje `ingredients.ingredient`, `ingredients.unit` i `steps` muszą być
 * wczytane przez wywołującego (bez N+1).
 */
final class PrzepisDoPaczki
{
    /**
     * @param  Closure(mixed): ?string  $data  ujednolicenie daty (ISO 8601 albo `null`)
     * @param  Closure(?string): ?string  $sciezkaZdjecia  ścieżka pliku zdjęcia dla `media_id`
     * @return array<string, mixed>
     */
    public static function pola(Recipe $recipe, string $plikDoCzytania, Closure $data, Closure $sciezkaZdjecia): array
    {
        return [
            'tytul' => $recipe->title,
            'adres_w_serwisie' => $recipe->slug,
            'plik_do_czytania' => $plikDoCzytania,
            'krotki_opis' => $recipe->summary,
            'porcje' => $recipe->servings,
            // Ile gotowych sztuk wychodzi z przepisu (#2645); osobno od porcji, `null` = nie podano.
            'gotowe_sztuki' => $recipe->yield_count,
            'gotowe_sztuki_co' => $recipe->yield_unit,
            // Wybór autora musi przetrwać przeniesienie danych; brak pola
            // odróżniałby ukrycie od domyślnej widoczności (D-299, #1993).
            'pokazuj_wartosci_odzywcze' => (bool) $recipe->pokazuj_wartosci_odzywcze,
            // Szacunek autora w złotych za CAŁY przepis (D-286); `null` = nie podano.
            'szacunkowy_koszt_zl' => $recipe->estimated_cost_pln,
            'przygotowanie_minuty' => $recipe->prep_minutes,
            'gotowanie_minuty' => $recipe->cook_minutes,
            'czas_laczny_zrodla_minuty' => $recipe->czas_laczny_zrodla_minut,
            'trudnosc' => $recipe->difficulty,
            'widocznosc' => $recipe->visibility,
            'status' => $recipe->status,
            // Prywatne „Odłożone na później” (#2550): kiedy autor odłożył szkic; `null` = szkic bieżący.
            'odlozony_na_pozniej' => $data($recipe->odlozony_at),
            'skad_przepis' => $recipe->source_type,
            'skad_przepis_opis' => Recipe::SOURCE_LABELS[$recipe->source_type] ?? null,
            'zrodlo_adres' => $recipe->source_url,
            'od_kogo' => $recipe->source_person,
            'notatka_o_zrodle' => $recipe->source_note,
            'w_rodzinie_od_roku' => $recipe->family_since_year,
            // Alergeny według autora (#1902) — ZAWSZE razem stan i lista: sama
            // pusta lista mogłaby zostać odczytana jako „brak alergenów”, a to
            // tylko `declared` z pustą listą (autor potwierdził, że żadnego
            // z 14 nie zaznaczył). Kody jak w bazie (`gluten`, `milk`, …).
            'alergeny_stan' => $recipe->allergen_status ?? Recipe::ALERGENY_NIESPRAWDZONE,
            'alergeny' => $recipe->allergens,
            'alergeny_potwierdzone' => $data($recipe->allergens_declared_at),
            // „Moja wersja" (issue #23, D-301): kiedy ta osoba zaczęła swoją wersję.
            'moja_wersja_od' => $data($recipe->forked_at),
            'zdjecie_glowne' => $sciezkaZdjecia($recipe->hero_media_id),
            'skan_zeszytu' => $sciezkaZdjecia($recipe->source_scan_media_id),
            'utworzono' => $data($recipe->created_at),
            'opublikowano' => $data($recipe->published_at),
            'skladniki' => $recipe->ingredients->map(static fn ($item): array => [
                'grupa' => $item->group_name,
                // `ingredient_text` to dokładnie to, co wpisał człowiek
                // („2 szklanki mąki”). Rozbite pola są obok, dla programów.
                'zapis' => $item->ingredient_text,
                'ile' => $item->quantity,
                // Jawny wybór autora; `ile = null` samo nie odróżnia „Bez ilości”
                // od nieprzeliczonego tekstu. Nie dopisujemy go do migawek.
                'bez_ilosci' => (bool) $item->no_amount,
                'jednostka' => $item->unit?->name,
                'skladnik_ze_slownika' => $item->ingredient?->canonical_name,
                'uwaga' => $item->note,
                // Zamiennik(i) wpisane przez autora (D-284).
                'zamienniki' => $item->substitutes,
            ])->all(),
            'kroki' => $recipe->steps->map(static fn ($step): array => [
                // W bazie `position` liczy się od zera — w eksporcie numerujemy
                // kroki tak, jak czyta je człowiek: od jedynki.
                'numer' => $step->position + 1,
                'opis' => $step->instruction,
                // Nazwa etapu, od którego zaczyna się ten krok (#2652); null = brak nagłówka.
                'etap' => $step->section_name,
                'minutnik_sekundy' => $step->timer_seconds,
                'zdjecie' => $sciezkaZdjecia($step->media_id),
            ])->all(),
        ];
    }

    /** Pomocnik dla wywołujących, którzy mają tylko `CarbonInterface`. */
    public static function dataIso(mixed $data): ?string
    {
        return $data instanceof CarbonInterface ? $data->toIso8601String() : null;
    }
}
