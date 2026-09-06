<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Topic;
use Illuminate\Database\Seeder;

/**
 * Zamknięta lista tematów (issue #31, SOUL.md 4.7).
 *
 * SKĄD TE POZYCJE
 * Dwanaście pierwszych to dokładnie ta lista, o którą onboarding pytał
 * do tej pory (`OnboardingController::INTERESTS`) — ludzie już na nią
 * odpowiadali, więc zmiana nazw wyrzuciłaby dane, których jeszcze nie mamy.
 * Reszta pochodzi z dokumentów produktowych, nie z sufitu:
 *
 *   * kuchnie regionalne — SOUL.md 4.7 („tożsamość regionalna w Polsce jest
 *     silniejsza niż tożsamość kulinarna; kartacze i kluski śląskie to spory,
 *     w które ludzie wchodzą z sercem");
 *   * tematy sezonowe i świąteczne — COLD_START.md, kalendarz tematów tygodnia
 *     (zupa na listopad, wigilia, tłusty czwartek, „co z resztek");
 *   * reszta domknięcia codzienności, o którą pytają wprost badania
 *     w docs/RESEARCH.md.
 *
 * DLACZEGO NIE WIĘCEJ
 * Trzydzieści pozycji to granica, przy której lista jeszcze da się przeczytać
 * w całości na telefonie. Przy pięćdziesięciu człowiek wybiera pierwsze trzy
 * z góry i przewija dalej — a wtedy dane mówią więcej o kolejności listy
 * niż o nim.
 *
 * SLUG TYLKO Z MYŚLNIKAMI, BEZ PODKREŚLNIKÓW
 * Poprzednie klucze zainteresowań miały podkreślniki (`dla_dzieci`), bo żyły
 * w sesji i nikt ich nie widział. Slug jest w ADRESIE strony tematu, a tam
 * obowiązuje myślnik — i tak mówi CHECK w migracji. Nie jest to kosmetyka:
 * ograniczenie w bazie odrzuciło pierwszą wersję tej listy w połowie
 * przebiegu, zanim ktokolwiek zobaczył ją na ekranie.
 *
 * DLACZEGO `updateOrCreate`, A NIE `insert`
 * Seeder chodzi także na istniejącej bazie (`db:seed` bez `migrate:fresh`).
 * Wstawianie na ślepo dublowałoby tematy albo wywalało się na unikalnym
 * slugu, a nazwy i opisy MAJĄ się dać poprawić redakcyjnie bez migracji.
 * Świadomie NIE nadpisujemy `is_active`: temat wycofany ręcznie ma zostać
 * wycofany po ponownym uruchomieniu seedera.
 */
class TopicSeeder extends Seeder
{
    /**
     * Kolejność w tablicy JEST kolejnością na ekranie — pole `position`
     * bierze się z indeksu. Alfabetyczna postawiłaby „Bez mięsa" przed
     * „Obiadami na co dzień", a to nie jest kolejność, w jakiej ludzie
     * o tym myślą.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const TEMATY = [
        // --- Codzienność: to, co ludzie gotują najczęściej ---
        ['obiady', 'Obiady na co dzień', 'To, co się gotuje w zwykły wtorek.'],
        ['szybkie', 'Szybkie, na jedną patelnię', 'Kiedy jest mało czasu i jeszcze mniej ochoty na zmywanie.'],
        ['zupy', 'Zupy', 'Od rosołu po krem z tego, co zostało w lodówce.'],
        ['bezmiesne', 'Bez mięsa', 'Nie z ideologii — po prostu bez mięsa.'],
        ['salatki', 'Sałatki i surówki', 'Do obiadu, na przyjęcie, do słoika na jutro.'],
        ['sniadania', 'Śniadania', 'Pierwsza rzecz dnia, często najlepiej dopracowana.'],
        ['kolacje', 'Kolacje', 'Coś ciepłego wieczorem albo coś, co nie wymaga garnka.'],

        // --- Rzemiosło: to, co się robi z czasem i cierpliwością ---
        ['chleb', 'Chleb i zakwas', 'Własny zakwas, własny bochenek, własne tempo.'],
        ['ciasta', 'Ciasta i wypieki', 'Drożdżowe, kruche, ucierane i te na blachę.'],
        ['przetwory', 'Przetwory i weki', 'Sierpień w słoiku, otwierany w lutym.'],
        ['kiszonki', 'Kiszonki', 'Ogórki, kapusta i wszystko inne, co da się ukisić.'],
        ['wedzenie', 'Wędzenie i peklowanie', 'Dla tych, którzy lubią wiedzieć, dlaczego coś działa.'],
        ['nalewki', 'Nalewki i syropy', 'Z tego, co urosło w ogrodzie albo przy drodze.'],

        // --- Okazje i pory roku ---
        ['swieta', 'Święta i uroczystości', 'Wigilia, Wielkanoc, imieniny cioci.'],
        ['grill', 'Grill i ognisko', 'Od kiełbasy po warzywa, których nikt się nie spodziewał.'],
        ['sezonowe', 'Sezonowe', 'To, co akurat jest — i będzie tylko przez chwilę.'],
        ['zresztek', 'Co z resztek', 'Wczorajszy obiad w nowej roli. Bez wyrzucania.'],

        // --- Dla kogoś ---
        ['dla-dzieci', 'Dla dzieci i wnuków', 'Sprawdzone na najtrudniejszej publiczności.'],
        ['dla-dwojga', 'Dla dwojga', 'Kiedy gotuje się na dwie osoby, a nie na dziesięć.'],
        ['dieta', 'Lżej i zdrowiej', 'Bez cudów, bez wyrzeczeń nie do wytrzymania.'],

        // --- Kuchnie regionalne (SOUL.md 4.7) ---
        ['regionalne', 'Kuchnia regionalna', 'Przepisy, które gdzie indziej robi się inaczej.'],
        ['slaska', 'Kuchnia śląska', 'Rolada, kluski, modro kapusta.'],
        ['podlaska', 'Kuchnia podlaska', 'Kartacze, babka ziemniaczana, sękacz.'],
        ['kresowa', 'Kuchnia kresowa', 'To, co przyjechało ze Wschodu i zostało w rodzinie.'],
        ['gorska', 'Kuchnia góralska', 'Kwaśnica, moskole, oscypek.'],
        ['nadmorska', 'Kuchnia nadmorska', 'Ryba, ale nie tylko w piątek.'],

        // --- Rzeczy, o które ludzie pytają wprost ---
        ['po-babci', 'Przepisy po babci', 'Zapisane w zeszycie, przepisane, żeby nie zginęły.'],
        ['taniej', 'Taniej, ale dobrze', 'Dobry obiad nie musi być drogi.'],
        ['dla-poczatkujacych', 'Dla początkujących', 'Krok po kroku, bez zakładania, że ktoś już umie.'],
        ['napoje', 'Napoje i kompoty', 'Kompot z rabarbaru, herbata z czymś, lemoniada z ogrodu.'],
    ];

    public function run(): void
    {
        foreach (self::TEMATY as $pozycja => [$slug, $nazwa, $opis]) {
            Topic::updateOrCreate(
                ['slug' => $slug],
                ['name' => $nazwa, 'description' => $opis, 'position' => $pozycja],
            );
        }
    }
}
