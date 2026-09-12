<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dwa kliknięcia „Wyślij" pod komentarzem dają JEDEN komentarz i JEDNO
 * powiadomienie (audyt podwójnego wysłania z 12 września 2026).
 *
 * CO BYŁO ZMIERZONE PRZED ZMIANĄ
 * Dwa identyczne `POST /wpisy/{post}/komentarz` dawały DWA wiersze
 * w `comments` i DWA powiadomienia u autora wpisu. Podwójne kliknięcie na
 * wolnym łączu nie jest w grupie 50+ pomyłką, tylko sposobem obsługi
 * komputera — to samo zdanie stoi w `IdempotentnyZapisDoZeszytuTest`
 * od issue #43.
 *
 * JAK TO JEST ZROBIONE I DLACZEGO NIE `klucz_wyslania`
 * Formularz komentarza jest JEDEN dla trzech ekranów
 * (`resources/views/components/comment-thread.blade.php`), więc nie ma gdzie
 * dołożyć ukrytego pola bez ruszania tego komponentu. Reguła stoi w akcji
 * domenowej `PublishComment`: BLOKADA W BAZIE na tożsamości wysłania
 * i REWALIDACJA pod nią — nie samo `exists()` w PHP (D-079).
 *
 * DRUGA POŁOWA TEGO PLIKU TO KONTROLE DODATNIE.
 * Mechanizm ma odróżnić podwójne kliknięcie od rozmowy. Bez testów „to samo
 * słowo pod innym wpisem", „to samo słowo po minucie" i „dwie różne osoby"
 * pierwsza asercja przechodziłaby także wtedy, gdyby komentarze przestały
 * się zapisywać w ogóle (pułapka 4 z `docs/PULAPKI_TESTOW.md`).
 *
 * CZEGO TEN PLIK NIE DOWODZI (pułapka 6)
 * Zachowania przy DWÓCH POŁĄCZENIACH. `RefreshDatabase` trzyma wszystko
 * w jednej, niezatwierdzonej transakcji, więc oba żądania idą tym samym
 * połączeniem — blokada doradcza jest w obrębie sesji wznawialna, a widoczna
 * jest tu rewalidacja, nie serializacja. Prawdziwy przeplot mierzy
 * `Tests\Dwa\KomentarzNiePowielaSieNaDwochPolaczeniachTest`
 * (`./scripts/testy-dwa-polaczenia.sh`).
 */
class IdempotencjaKomentarzaTest extends TestCase
{
    use RefreshDatabase;

    private const TRESC = 'Wygląda przepięknie, muszę spróbować.';

    private function wpis(User $autor): Post
    {
        return Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function przepis(User $autor): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function ilePowiadomien(User $odbiorca, string $typ = Notification::TYPE_COMMENT): int
    {
        return Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->where('type', $typ)
            ->count();
    }

    public function test_dwa_klikniecia_daja_jeden_komentarz_pod_wpisem_i_jedno_powiadomienie(): void
    {
        $autor = $this->user('autorwpisu');
        $osoba = $this->user('komentujaca');
        $wpis = $this->wpis($autor);

        $pierwsze = $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC]);
        $drugie = $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC]);

        $drugie->assertSessionHasNoErrors();

        $this->assertSame(1, Comment::query()->count(), 'Podwójne kliknięcie zapisało dwa komentarze.');
        $this->assertSame(1, $this->ilePowiadomien($autor), 'Autor wpisu dostał dwa powiadomienia za jeden komentarz.');

        // Człowiek widzi to samo co za pierwszym razem: przekierowanie z
        // powrotem pod wpis i to samo potwierdzenie. Nic nie znika i nic nie
        // wygląda na błąd — bo błędu nie ma, komentarz jest.
        $this->assertSame($pierwsze->headers->get('Location'), $drugie->headers->get('Location'));
        $drugie->assertSessionHas('status', 'Komentarz dodany.');
    }

    public function test_dwa_klikniecia_daja_jeden_komentarz_takze_pod_przepisem_i_pod_ugotowalem(): void
    {
        // Trzy kontrolery, jedna reguła — dlatego stoi w akcji domenowej,
        // a nie w kontrolerze (AGENTS.md §4).
        $autor = $this->user('autorprzepisu');
        $osoba = $this->user('komentujacawszedzie');
        $przepis = $this->przepis($autor);

        $this->actingAs($osoba)->post(route('recipes.comment', $przepis->slug), ['body' => self::TRESC]);
        $this->actingAs($osoba)->post(route('recipes.comment', $przepis->slug), ['body' => self::TRESC]);

        $this->assertSame(1, Comment::query()->whereNotNull('recipe_id')->count(), 'Komentarz pod przepisem zapisał się dwa razy.');

        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $autor->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);

        $this->actingAs($osoba)->post(route('cooked.comment', $wykonanie), ['body' => self::TRESC]);
        $this->actingAs($osoba)->post(route('cooked.comment', $wykonanie), ['body' => self::TRESC]);

        $this->assertSame(
            1,
            Comment::query()->whereNotNull('cooked_event_id')->count(),
            'Komentarz pod „Ugotowałem" zapisał się dwa razy.',
        );
    }

    public function test_dwa_klikniecia_daja_jedna_odpowiedz_i_jedno_powiadomienie_dla_autora_watku(): void
    {
        $autor = $this->user('autorwpisu2');
        $pytajacy = $this->user('pytajaca');
        $odpowiadajacy = $this->user('odpowiadajaca');
        $wpis = $this->wpis($autor);

        $this->actingAs($pytajacy)->post(route('posts.comment', $wpis), ['body' => 'Ile soli?']);
        $watek = Comment::query()->firstOrFail();

        $odpowiedz = ['body' => 'Płaska łyżeczka.', 'parent_id' => $watek->getKey()];

        $this->actingAs($odpowiadajacy)->post(route('posts.comment', $wpis), $odpowiedz);
        $this->actingAs($odpowiadajacy)->post(route('posts.comment', $wpis), $odpowiedz);

        $this->assertSame(2, Comment::query()->count(), 'Odpowiedź zapisała się dwa razy.');
        $this->assertSame(1, $this->ilePowiadomien($pytajacy, Notification::TYPE_REPLY), 'Autor wątku dostał dwa powiadomienia za jedną odpowiedź.');
    }

    public function test_ta_sama_odpowiedz_w_dwoch_roznych_watkach_zapisuje_sie_dwa_razy(): void
    {
        // KONTROLA DODATNIA. „Też mi tak wyszło" pod dwoma różnymi pytaniami
        // to dwie odpowiedzi, nie jedna — tożsamość wysłania obejmuje wątek.
        $autor = $this->user('autorwpisu3');
        $osoba = $this->user('odpowiadajacadwom');
        $wpis = $this->wpis($autor);

        $this->actingAs($this->user('pierwszypytajacy'))->post(route('posts.comment', $wpis), ['body' => 'Ile soli?']);
        $this->actingAs($this->user('drugipytajacy'))->post(route('posts.comment', $wpis), ['body' => 'Ile pieprzu?']);

        $watki = Comment::query()->whereNull('parent_id')->orderBy('created_at')->get();
        $this->assertCount(2, $watki);

        foreach ($watki as $watek) {
            $this->actingAs($osoba)->post(route('posts.comment', $wpis), [
                'body' => 'Do smaku.',
                'parent_id' => $watek->getKey(),
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Comment::query()->whereNotNull('parent_id')->count(), 'Ta sama odpowiedź w drugim wątku nie zapisała się.');
    }

    public function test_dwa_rozne_komentarze_tej_samej_osoby_zapisuja_sie_oba(): void
    {
        // KONTROLA DODATNIA. Ochrona dotyczy POWTÓRZONEGO wysłania, nie
        // osoby i nie wpisu — inaczej wyciszalibyśmy rozmowę, czyli to,
        // po co ten serwis istnieje (`NotifyUser`, `config/kuking.php`).
        $autor = $this->user('autorwpisu4');
        $osoba = $this->user('rozmawiajaca');
        $wpis = $this->wpis($autor);

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => 'Pyszne!']);
        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => 'A ile to się piecze?']);

        $this->assertSame(2, Comment::query()->count());
        $this->assertSame(2, $this->ilePowiadomien($autor), 'Drugi, inny komentarz nie doszedł do autora.');
    }

    public function test_ten_sam_komentarz_po_uplywie_okna_zapisuje_sie_jako_nowy(): void
    {
        // KONTROLA DODATNIA i granica mechanizmu: „Pyszne!" napisane pod tym
        // samym wpisem po minucie to nowa reakcja, nie duplikat.
        $autor = $this->user('autorwpisu5');
        $osoba = $this->user('wracajaca');
        $wpis = $this->wpis($autor);

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => 'Pyszne!']);

        $this->travel((int) config('kuking.formularze.okno_powtorzenia_komentarza_sekund') + 1)->seconds();

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => 'Pyszne!']);

        $this->assertSame(2, Comment::query()->count(), 'Po upływie okna ten sam komentarz nie zapisał się.');
        $this->assertSame(2, $this->ilePowiadomien($autor));
    }

    public function test_ten_sam_komentarz_pod_dwoma_roznymi_wpisami_zapisuje_sie_dwa_razy(): void
    {
        // KONTROLA DODATNIA. Tożsamość wysłania obejmuje MIEJSCE.
        $autor = $this->user('autordwochwpisow');
        $osoba = $this->user('chwalaca');
        $pierwszy = $this->wpis($autor);
        $drugi = $this->wpis($autor);

        $this->actingAs($osoba)->post(route('posts.comment', $pierwszy), ['body' => 'Pyszne!']);
        $this->actingAs($osoba)->post(route('posts.comment', $drugi), ['body' => 'Pyszne!']);

        $this->assertSame(2, Comment::query()->count(), 'Ten sam komentarz pod innym wpisem nie zapisał się.');
    }

    public function test_dwie_rozne_osoby_pisza_to_samo_i_oba_komentarze_zostaja(): void
    {
        // KONTROLA DODATNIA. Tożsamość wysłania obejmuje OSOBĘ — „Pyszne!"
        // od dwóch osób to dwa głosy.
        $autor = $this->user('autorwpisu6');
        $wpis = $this->wpis($autor);

        $this->actingAs($this->user('pierwszachwalaca'))->post(route('posts.comment', $wpis), ['body' => 'Pyszne!']);
        $this->actingAs($this->user('drugachwalaca'))->post(route('posts.comment', $wpis), ['body' => 'Pyszne!']);

        $this->assertSame(2, Comment::query()->count(), 'Komentarz drugiej osoby przepadł.');
        $this->assertSame(2, $this->ilePowiadomien($autor));
    }

    public function test_okno_ustawione_na_zero_przywraca_zachowanie_sprzed_tej_zmiany(): void
    {
        // Wyjście awaryjne. Bez tego testu „`0` wyłącza mechanizm" z
        // `config/kuking.php` byłoby obietnicą bez pokrycia w kodzie — a to
        // jest w tym repozytorium osobno nazwany rodzaj błędu.
        config(['kuking.formularze.okno_powtorzenia_komentarza_sekund' => 0]);

        $autor = $this->user('autorwpisu7');
        $osoba = $this->user('klikajacadwarazy');
        $wpis = $this->wpis($autor);

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC]);
        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC]);

        $this->assertSame(2, Comment::query()->count(), 'Wyłącznik nie przywrócił zachowania sprzed tej zmiany.');
    }
}
