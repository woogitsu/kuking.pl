<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * `RecipePolicy::view()` i zapytanie o LISTĘ przepisów muszą dawać tę samą
 * odpowiedź — pilnowane REGUŁĄ, nie listą znanych przypadków.
 *
 * PO CO TO ISTNIEJE
 * Ten projekt traci najwięcej na jednym wzorcu: reguła jest poprawna
 * w Policy, a zapytanie budujące listę implementuje ją inaczej albo wcale
 * (`docs/HANDOVER.md`). Osobne testy per powierzchnia łapią to dopiero wtedy,
 * gdy ktoś napisze test dla TEJ powierzchni. Ten test nie pyta o powierzchnię
 * — pyta o dwie warstwy i przechodzi całą macierz:
 *
 *     3 widoczności × 4 statusy przepisu × 4 statusy konta autora
 *       × 5 typów widza  =  240 komórek
 *
 * DWIE GRANICE, OBIE OBOWIĄZKOWE (ustalenie W5-08)
 * `Recipe::scopeWidoczneDla()` CELOWO nie liczy statusu konta autora —
 * dokładnie tak samo jak `Post::scopeWidoczneDla()`, i z tego samego powodu:
 * odpowiada na pytanie „czy TEN widz ma prawo to zobaczyć", nie „czy autor
 * ma dziś prawo być czytany". Zapytanie o listę musi więc dokładać
 * `User::scopeDostepnyJakoAutor()`, a ten test mierzy, że para tych dwóch
 * zakresów daje dokładnie to, co Policy.
 *
 * Zmierzone: samo `widoczneDla()`, bez tej pary, rozjeżdża się z Policy
 * w 8 komórkach macierzy — przepis opublikowany autora `banned` albo
 * `pending_delete` przechodzi przez zapytanie i dostaje 403 pod adresem:
 *
 *     widocznosc=public    status=published autor=banned         widz=obcy   policy=nie scope=TAK
 *     widocznosc=public    status=published autor=pending_delete widz=gosc   policy=nie scope=TAK
 *     widocznosc=followers status=published autor=banned    widz=obserwujacy policy=nie scope=TAK
 *     (… i pięć pozostałych kombinacji tych samych dwóch statusów)
 *
 * KIERUNEK ASERCJI MA ZNACZENIE
 * Dwie nierówności, nie jedna równość, bo dwie różnice są ŚWIADOME:
 *
 *  1. ZAPYTANIE NIGDY SZERSZE NIŻ POLICY — to jest asercja bezpieczeństwa
 *     i obowiązuje bez wyjątku. Wpuszczenie wiersza, któremu Policy mówi
 *     „nie", to opisany wyżej wyciek.
 *  2. POLICY NIGDY SZERSZA NIŻ ZAPYTANIE — tylko dla widza, który NIE jest
 *     autorem. Policy ma jawny wyjątek dla właściciela i moderatora (autor
 *     zbanowany widzi własny przepis; moderator widzi wszystko), a lista
 *     tych wyjątków nie ma i mieć nie musi.
 */
class PrzepisyPolicyIZapytanieOdpowiadajaTakSamoTest extends TestCase
{
    use RefreshDatabase;

    public function test_zapytanie_o_liste_nigdy_nie_wpuszcza_wiecej_niz_policy(): void
    {
        $rozjazdy = [];
        $wpuszczoneRazem = 0;

        foreach ($this->macierz() as $komorka) {
            [$opis, $przepis, $widz, $czyAutor] = $komorka;

            // Relacja `author` jest w pamięci z chwili tworzenia przepisu,
            // czyli PRZED zmianą statusu konta. Bez tego Policy czytałaby
            // status nieaktualny i test mierzyłby własne złudzenie.
            $przepis->unsetRelation('author');

            $policy = Gate::forUser($widz)->allows('view', $przepis);

            $zapytanie = Recipe::query()
                ->widoczneDla($widz)
                ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
                ->whereKey($przepis->getKey())
                ->exists();

            if ($zapytanie) {
                $wpuszczoneRazem++;
            }

            if ($zapytanie && ! $policy) {
                $rozjazdy[] = $opis.' — zapytanie WPUSZCZA, Policy odmawia (wyciek)';
            }

            if (! $czyAutor && $policy && ! $zapytanie) {
                $rozjazdy[] = $opis.' — Policy wpuszcza, zapytanie ODMAWIA (treść znika z listy)';
            }
        }

        $this->assertSame([], $rozjazdy, "Rozjazd Policy ↔ zapytanie:\n".implode("\n", $rozjazdy));

        // ASERCJA KONTROLNA. Bez niej cały test przechodziłby także wtedy,
        // gdyby zapytanie nie wpuszczało NICZEGO — a to jest stan, w którym
        // „nie widać przepisu zbanowanego autora" jest prawdą z powodu awarii.
        $this->assertGreaterThan(
            0,
            $wpuszczoneRazem,
            'Zapytanie nie wpuściło ani jednego przepisu — test mierzy pustkę, nie regułę.',
        );
    }

    /**
     * Jedna, konkretna komórka macierzy wprost — po to, żeby przy przyszłej
     * awarii było widać JAKI przypadek padł, a nie tylko „coś w 240".
     */
    public function test_przepis_zbanowanego_autora_wypada_z_listy_i_daje_403(): void
    {
        $autor = $this->user('zbanowana');
        $obcy = $this->user('obca');
        $aktywny = $this->user('aktywna');

        $ukarany = Recipe::factory()->for($autor, 'author')->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'slug' => 'przepis-zbanowanej',
        ]);

        // KONTROLA: identyczny przepis autora bez sankcji.
        $czysty = Recipe::factory()->for($aktywny, 'author')->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'slug' => 'przepis-aktywnej',
        ]);

        $autor->ban();

        $widoczne = Recipe::query()
            ->widoczneDla($obcy)
            ->whereHas('author', fn ($a) => $a->dostepnyJakoAutor())
            ->pluck('slug')
            ->all();

        $this->assertContains('przepis-aktywnej', $widoczne, 'Przepis autora bez sankcji musi zostać na liście.');
        $this->assertNotContains('przepis-zbanowanej', $widoczne);

        $this->actingAs($obcy)->get('/przepisy/przepis-zbanowanej')->assertStatus(403);
        $this->actingAs($obcy)->get('/przepisy/przepis-aktywnej')->assertOk();

        // Nieużywane poza czytelnością nazw wyżej.
        $this->assertNotNull($ukarany->getKey());
        $this->assertNotNull($czysty->getKey());
    }

    /**
     * @return list<array{0: string, 1: Recipe, 2: ?User, 3: bool}>
     */
    private function macierz(): array
    {
        $komorki = [];

        $statusyPrzepisu = [
            Recipe::STATUS_DRAFT,
            Recipe::STATUS_PUBLISHED,
            Recipe::STATUS_HIDDEN,
            Recipe::STATUS_REMOVED,
        ];

        $statusyKonta = [
            User::STATUS_ACTIVE,
            User::STATUS_SUSPENDED,
            User::STATUS_BANNED,
            User::STATUS_PENDING_DELETE,
        ];

        foreach (['public', 'followers', 'private'] as $widocznosc) {
            foreach ($statusyPrzepisu as $statusPrzepisu) {
                foreach ($statusyKonta as $statusKonta) {
                    // Świeży zestaw kont na komórkę: obserwowanie i blokada są
                    // wierszami w bazie, więc współdzielenie kont między
                    // komórkami mieszałoby relacje z poprzednich iteracji.
                    $autor = $this->user();
                    $obserwujacy = $this->user();
                    $obcy = $this->user();
                    $zablokowany = $this->user();

                    app(FollowUser::class)->handle($obserwujacy, $autor);
                    app(BlockUser::class)->handle($autor, $zablokowany);

                    $przepis = Recipe::factory()->for($autor, 'author')->create([
                        'visibility' => $widocznosc,
                        'status' => $statusPrzepisu,
                        'published_at' => $statusPrzepisu === Recipe::STATUS_PUBLISHED ? now()->subDay() : null,
                    ]);

                    $autor->forceFill(['status' => $statusKonta])->save();
                    $autor->refresh();

                    foreach ([
                        'autor' => $autor,
                        'obserwujący' => $obserwujacy,
                        'obcy' => $obcy,
                        'zablokowany' => $zablokowany,
                        'niezalogowany' => null,
                    ] as $ktoNazwa => $widz) {
                        $komorki[] = [
                            sprintf(
                                'widoczność=%s / status przepisu=%s / konto autora=%s / widz=%s',
                                $widocznosc,
                                $statusPrzepisu,
                                $statusKonta,
                                $ktoNazwa,
                            ),
                            $przepis,
                            $widz,
                            $ktoNazwa === 'autor',
                        ];
                    }
                }
            }
        }

        return $komorki;
    }
}
