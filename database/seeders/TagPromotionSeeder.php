<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Database\Seeder;

/**
 * Startowa lista tagów promowanych — „lista gospodarza" (D-021).
 *
 * PO CO TO JEST
 * `tag_promotions` była pusta, a czytają ją dwa miejsca: krok onboardingu
 * „co Cię interesuje" (`OnboardingController::interests` przez
 * `Tag::promowane()`) i szyna na stronie głównej. Pusta lista nie psuje
 * serwisu — onboarding pomija wtedy krok, zamiast pokazywać pustą siatkę
 * checkboxów — ale nowa osoba nie dostaje ANI JEDNEJ podpowiedzi, od czego
 * zacząć obserwowanie. `docs/product/COLD_START.md` §6.1 stawia dokładnie na
 * to, żeby pierwszy feed nie był pusty.
 *
 * DOBÓR DWUNASTU NAZW JEST DECYZJĄ REDAKCYJNĄ, NIE TECHNICZNĄ
 * Uzasadnienie każdej z nich, i wprost wypisane, czego świadomie NIE ma na
 * liście, leży w `docs/decyzje/TAGI_PROMOWANE.md`. Dane są w pliku
 * `dane/tagi-promowane.json`, żeby zmiana listy nie wymagała ruszania kodu —
 * ten sam powód co przy słowniku tagów (D-026).
 *
 * NAJWAŻNIEJSZA REGUŁA TEJ KLASY: DZIAŁA TYLKO NA PUSTEJ LIŚCIE.
 * Jeśli `tag_promotions` ma choćby jeden wiersz, seeder nie robi NICZEGO
 * i mówi to w raporcie. Lista promowanych jest wyborem gospodarza,
 * dokonywanym w panelu `/admin/tagi-promowane`, i seeder nie ma prawa go
 * nadpisać ani — co gorsza — przywrócić tego, co gospodarz świadomie z listy
 * ZDJĄŁ. Bez tego warunku każde `db:seed` po deployu cofałoby jego decyzje,
 * a on nie miałby jak się zorientować, dlaczego zdjęty tag wraca.
 *
 * Dlatego ta klasa JEST wołana z `DatabaseSeeder` także na produkcji: przy
 * pustej liście robi dokładnie to, co trzeba, a przy niepustej jest
 * bezpiecznym no-opem.
 *
 * CZEGO TA LISTA NIE ROZWIĄZUJE
 * Gospodarz musi pod tymi tagami cokolwiek opublikować. `COLD_START.md` §4.2
 * każe mu publikować pierwszemu i to jest jedyna część, której nie da się
 * załatwić konfiguracją: tag promowany z zerem wpisów mówi nowej osobie
 * „tu nikogo nie ma" wyraźniej niż brak listy.
 */
class TagPromotionSeeder extends Seeder
{
    public function run(): void
    {
        if (TagPromotion::query()->exists()) {
            $this->command?->info(
                'TagPromotionSeeder: lista promowanych nie jest pusta — nie ruszam jej. '
                .'To wybór gospodarza z panelu /admin/tagi-promowane.',
            );

            return;
        }

        $wpisy = $this->wczytaj();

        $utworzone = 0;
        $brakujace = [];

        foreach ($wpisy as $wpis) {
            $nazwa = trim((string) ($wpis['nazwa'] ?? ''));
            $znormalizowana = Tag::znormalizujNazwe($nazwa);

            $tag = Tag::query()
                ->where('normalized_name', $znormalizowana)
                ->where('status', Tag::STATUS_ACTIVE)
                ->first();

            if ($tag === null) {
                // Nazwa spoza słownika albo tag scalony/ukryty. Zgłaszamy,
                // zamiast tworzyć tag pod promocję: promowanie tagu, którego
                // nikt nie używa, to dokładnie odwrotność sensu tej listy.
                $brakujace[] = $nazwa;

                continue;
            }

            $notatka = $wpis['notatka'] ?? null;

            TagPromotion::query()->create([
                'tag_id' => $tag->getKey(),
                'position' => (int) ($wpis['pozycja'] ?? 0),
                'note' => is_string($notatka) && trim($notatka) !== '' ? trim($notatka) : null,
            ]);

            $utworzone++;
        }

        $this->command?->info("TagPromotionSeeder: {$utworzone} tagów promowanych, ".count($brakujace).' pominiętych.');

        foreach ($brakujace as $nazwa) {
            $this->command?->warn("  pominięto: „{$nazwa}” (nie ma takiego aktywnego tagu)");
        }
    }

    /** @return list<array<string, mixed>> */
    private function wczytaj(): array
    {
        $sciezka = database_path('seeders/dane/tagi-promowane.json');
        $surowe = file_get_contents($sciezka);

        if ($surowe === false) {
            throw new \RuntimeException("Nie da się wczytać listy tagów promowanych: {$sciezka}");
        }

        $dane = json_decode($surowe, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($dane) || ! is_array($dane['tagi'] ?? null)) {
            throw new \RuntimeException('Lista tagów promowanych nie ma tablicy `tagi`.');
        }

        /** @var list<array<string, mixed>> $tagi */
        $tagi = array_values(array_filter($dane['tagi'], 'is_array'));

        return $tagi;
    }
}
