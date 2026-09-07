<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Jedno wysłanie formularza „Opublikuj" to jeden wpis
 * (ADR `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`, wariant A3).
 *
 * CO BYŁO ZMIERZONE PRZED ZMIANĄ (ADR §1.1)
 * Dwa razy POST /dodaj/zdjecie z identycznym ciałem dawało DWA wpisy i dwa
 * różne adresy w `Location` — serwis odsyłał człowieka do DRUGIEGO wpisu,
 * o którego istnieniu ten człowiek nie wiedział.
 *
 * Podwójne kliknięcie nie jest w grupie 50+ pomyłką, tylko sposobem obsługi
 * komputera: strona myśli chwilę, więc klika się drugi raz
 * (`IdempotentnyZapisDoZeszytuTest`, issue #43).
 *
 * CZEGO TEN PLIK PILNUJE W DRUGĄ STRONĘ
 * Mechanizm ma NIE blokować wysłań prawdziwie różnych: ktoś gotuje rosół co
 * niedzielę i pisze o tym za każdym razem, a dwa podobne zdjęcia pod rząd to
 * dwa wpisy. Testy „nowy formularz — nowy wpis" i „dwie karty" są tu równie
 * ważne jak te o duplikacie.
 */
class IdempotencjaWpisuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Klucz wysłania z wyrenderowanego formularza — dokładnie ta wartość,
     * którą przeglądarka odeśle w ukrytym polu.
     */
    private function kluczZFormularza(string $html): ?string
    {
        return preg_match('/name="klucz_wyslania" value="([^"]+)"/', $html, $trafienia) === 1
            ? $trafienia[1]
            : null;
    }

    private function kluczZEkranuDodawania(User $osoba): ?string
    {
        return $this->kluczZFormularza(
            $this->actingAs($osoba)->get(route('posts.create'))->getContent(),
        );
    }

    public function test_dwa_klikniecia_opublikuj_daja_jeden_wpis(): void
    {
        $osoba = $this->user('klikajaca');
        $klucz = $this->kluczZEkranuDodawania($osoba);

        $tresc = [
            'body' => 'Rosół na niedzielę, z kaczki od sąsiada.',
            'visibility' => 'public',
            'klucz_wyslania' => $klucz,
        ];

        $pierwsze = $this->actingAs($osoba)->post(route('posts.store'), $tresc);
        $drugie = $this->actingAs($osoba)->post(route('posts.store'), $tresc);

        $drugie->assertSessionHasNoErrors();

        $this->assertSame(1, Post::query()->count(), 'Podwójne kliknięcie „Opublikuj" utworzyło dwa wpisy.');

        // Najkrótszy dowód, że człowiek trafia tam, gdzie miał trafić:
        // oba żądania odsyłają na TEN SAM adres.
        $this->assertSame(
            $pierwsze->headers->get('Location'),
            $drugie->headers->get('Location'),
            'Drugie kliknięcie odesłało człowieka pod inny adres niż pierwsze.',
        );

        // Wpis w logu audytu też jest jeden — inaczej „post.published"
        // liczyłoby publikacje, których nie było.
        $this->assertSame(1, DB::table('audit_log')->where('action', 'post.published')->count());

        // Komunikat obiecuje dokładnie to, co kod dowozi: wpis jest jeden,
        // a ekran, na który człowiek trafia, jest tym wpisem. Tekst jest
        // przypięty w teście, żeby nikt nie dopisał do niego obietnicy,
        // której nie da się spełnić (§7.1 i §7.3 ADR-u).
        $drugie->assertSessionHas(
            'status',
            'Ten wpis jest już opublikowany. Kliknięcie drugi raz nic nie zepsuło — wpis jest jeden. '
            .'Chcesz dodać osobny wpis? Otwórz „Dodaj zdjęcie” jeszcze raz — wtedy powstanie nowy.',
        );
        $this->assertSame(route('posts.show', Post::query()->firstOrFail()), $drugie->headers->get('Location'));
    }

    public function test_powrot_wstecz_i_ponowne_wyslanie_nie_tworzy_drugiego_wpisu(): void
    {
        // Przy powrocie „wstecz" przeglądarka nie odtwarza pola z plikiem,
        // a ukryte `media_ids` wskazują zdjęcie JUŻ PODPIĘTE do pierwszego
        // wpisu — dlatego duplikat powstawał wcześniej bez zdjęcia (ADR §1.1).
        $osoba = $this->user('wracajaca');
        $zdjecie = Media::factory()->create(['owner_id' => $osoba->getKey()]);
        $klucz = $this->kluczZEkranuDodawania($osoba);

        $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => 'Placki z jabłkami.',
            'visibility' => 'public',
            'media_ids' => [$zdjecie->getKey()],
            'klucz_wyslania' => $klucz,
        ]);

        $ponowne = $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => 'Placki z jabłkami.',
            'visibility' => 'public',
            'media_ids' => [$zdjecie->getKey()],
            'klucz_wyslania' => $klucz,
        ]);

        $ponowne->assertSessionHasNoErrors();

        $this->assertSame(1, Post::query()->count(), 'Powrót „wstecz" i ponowne wysłanie dały drugi wpis.');
        $this->assertSame(1, DB::table('post_media')->count(), 'Zdjęcie odpięło się od wpisu albo zostało podpięte dwa razy.');
    }

    public function test_dwie_karty_kazda_z_wlasnym_kluczem_daja_dwa_wpisy(): void
    {
        // GRANICA MECHANIZMU, nazwana wprost w ADR §3.1: dwa osobno otwarte
        // formularze to dwa świadome wysłania i dwa wpisy. Ten test pilnuje,
        // żeby mechanizm nie zaczął blokować drugiej karty — utrata cudzego
        // wpisu jest gorsza niż duplikat (ADR §4.3).
        $osoba = $this->user('dwiekarty');

        $pierwszaKarta = $this->kluczZEkranuDodawania($osoba);
        $drugaKarta = $this->kluczZEkranuDodawania($osoba);

        $this->assertNotSame($pierwszaKarta, $drugaKarta, 'Dwie karty dostały ten sam klucz wysłania.');

        foreach ([$pierwszaKarta, $drugaKarta] as $klucz) {
            $this->actingAs($osoba)->post(route('posts.store'), [
                'body' => 'Sernik wiedeński.',
                'visibility' => 'public',
                'klucz_wyslania' => $klucz,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Post::query()->count(), 'Druga karta nie mogła opublikować wpisu.');
    }

    public function test_te_same_slowa_i_takie_samo_zdjecie_z_nowego_formularza_daja_nowy_wpis(): void
    {
        // KONTROLA W DRUGĄ STRONĘ. Mechanizm liczy WYSŁANIA, nie treść —
        // dwa podobne zdjęcia pod rząd (ta sama suma kontrolna, bo ten sam
        // plik wgrany dwa razy, ADR §1.4.1) muszą dać dwa wpisy.
        $osoba = $this->user('gotujacaco');
        $suma = str_repeat('a', 64);

        foreach ([1, 2] as $ktore) {
            $zdjecie = Media::factory()->create([
                'owner_id' => $osoba->getKey(),
                'checksum_sha256' => $suma,
            ]);

            $this->actingAs($osoba)->post(route('posts.store'), [
                'body' => 'Rosół na niedzielę.',
                'visibility' => 'public',
                'media_ids' => [$zdjecie->getKey()],
                'klucz_wyslania' => $this->kluczZEkranuDodawania($osoba),
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Post::query()->count(), 'Drugi wpis o tej samej treści nie powstał — mechanizm łapie treść, a nie wysłanie.');
    }

    public function test_klucz_z_cudzego_formularza_nie_daje_dostepu_do_cudzego_wpisu(): void
    {
        // UUID W ŻĄDANIU NIE JEST AUTORYZACJĄ (AGENTS.md §7). Klucz wysłania
        // podstawiony z cudzego formularza nie może ani pokazać cudzego
        // wpisu, ani zablokować własnego.
        $autorka = $this->user('autorkawpisu');
        $ktosInny = $this->user('ktosinny');

        $klucz = $this->kluczZEkranuDodawania($autorka);

        $this->actingAs($autorka)->post(route('posts.store'), [
            'body' => 'Mój wpis.',
            'visibility' => 'public',
            'klucz_wyslania' => $klucz,
        ]);

        $wpisAutorki = Post::query()->firstOrFail();

        $obcy = $this->actingAs($ktosInny)->post(route('posts.store'), [
            'body' => 'Wpis kogoś innego.',
            'visibility' => 'public',
            'klucz_wyslania' => $klucz,
        ]);

        $obcy->assertSessionHasNoErrors();

        $this->assertSame(2, Post::query()->count(), 'Cudzy klucz zablokował własne wysłanie.');
        $this->assertNotSame(
            route('posts.show', $wpisAutorki),
            $obcy->headers->get('Location'),
            'Cudzy klucz odesłał kogoś obcego na wpis autorki.',
        );
        $this->assertSame(
            $autorka->getKey(),
            $wpisAutorki->fresh()->author_id,
            'Wpis autorki zmienił właściciela.',
        );
    }

    public function test_wyslanie_bez_klucza_dalej_publikuje(): void
    {
        // ZAWODZENIE OTWARTE (ADR §4.3): brak klucza znaczy „wyślij
        // normalnie", nigdy „odmawiam". Stara zakładka, seeder i komenda
        // konsolowa muszą przejść.
        $osoba = $this->user('bezklucza');

        $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => 'Wpis bez ukrytego pola.',
            'visibility' => 'public',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Post::query()->count());
    }

    public function test_klucz_przezywa_blad_walidacji_i_poprawiony_wpis_powstaje(): void
    {
        // NAJGROŹNIEJSZA AWARIA TEGO MECHANIZMU (ADR §8.4): gdyby klucz
        // zginął albo został „zużyty" przez wysłanie, które wpisu nie
        // utworzyło, poprawiony wpis nigdy by nie powstał.
        $osoba = $this->user('poprawiajaca');
        $klucz = $this->kluczZEkranuDodawania($osoba);

        $zaDlugi = $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => str_repeat('a', 4001),
            'visibility' => 'public',
            'klucz_wyslania' => $klucz,
        ]);

        $zaDlugi->assertSessionHasErrors('body');
        $this->assertSame(0, Post::query()->count());

        // Formularz wystawiony od nowa niesie TEN SAM klucz — inaczej
        // ochrona znikałaby po pierwszym błędzie walidacji.
        $poBledzie = $this->actingAs($osoba)->get(route('posts.create'));

        $this->assertSame($klucz, $this->kluczZFormularza($poBledzie->getContent()));

        $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => 'Poprawiony, krótszy wpis.',
            'visibility' => 'public',
            'klucz_wyslania' => $klucz,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Post::query()->count(), 'Poprawiony wpis nie powstał.');
        $this->assertSame('Poprawiony, krótszy wpis.', Post::query()->firstOrFail()->body);
    }

    public function test_dodanie_tagu_nie_zuzywa_klucza(): void
    {
        // Formularz publikacji ma trzy osobne przyciski wysyłające w tym
        // samym `<form>` („Szukaj tagów", „Dodaj", „Usuń") — żaden z nich
        // nie tworzy wpisu, więc żaden nie może zużyć klucza (ADR §8.1,
        // krok 3: „to jest najbardziej prawdopodobne miejsce błędu").
        $osoba = $this->user('tagujaca');
        $klucz = $this->kluczZEkranuDodawania($osoba);

        $this->actingAs($osoba)
            ->from(route('posts.create'))
            ->post(route('posts.store'), [
                'body' => 'Rosół.',
                'visibility' => 'public',
                'dodaj_tag' => 'rosol',
                'klucz_wyslania' => $klucz,
            ]);

        $this->assertSame(0, Post::query()->count(), 'Kliknięcie „Dodaj" opublikowało wpis.');

        $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => 'Rosół.',
            'visibility' => 'public',
            'tag_names' => ['rosol'],
            'klucz_wyslania' => $klucz,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Post::query()->count(), 'Po kliknięciu „Dodaj" klucz był już zużyty i wpis nie powstał.');
    }

    public function test_po_usunieciu_wpisu_ten_sam_formularz_publikuje_od_nowa(): void
    {
        // Indeks obejmuje też wpisy usunięte miękko, więc klucz zostaje
        // zajęty po usunięciu. Mechanizm ma wtedy ZAWIEŚĆ OTWARCIE: wpis
        // powstaje (bez klucza), a nie „nie da się opublikować".
        $osoba = $this->user('usuwajaca');
        $klucz = $this->kluczZEkranuDodawania($osoba);

        $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => 'Pierwsza wersja.',
            'visibility' => 'public',
            'klucz_wyslania' => $klucz,
        ])->assertSessionHasNoErrors();

        $wpis = Post::query()->firstOrFail();
        $this->actingAs($osoba)->delete(route('posts.destroy', $wpis))->assertSessionHasNoErrors();

        $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => 'Druga wersja, po usunięciu pierwszej.',
            'visibility' => 'public',
            'klucz_wyslania' => $klucz,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Post::query()->count(), 'Po usunięciu wpisu ten sam formularz nie zapisał nowego wpisu.');
        $this->assertSame('Druga wersja, po usunięciu pierwszej.', Post::query()->firstOrFail()->body);
        $this->assertNull(
            Post::query()->firstOrFail()->klucz_wyslania,
            'Wpis zapisany po kolizji klucza powinien mieć `klucz_wyslania` puste — inaczej klucz byłby w indeksie dwa razy.',
        );
    }

    public function test_baza_odbija_drugi_wpis_z_tym_samym_kluczem_z_pominieciem_kontrolera(): void
    {
        // Kontroler i akcja domenowa chronią jedną drogę. Komenda konsolowa,
        // seeder albo przyszły endpoint pójdą inną — dlatego ograniczenie
        // siedzi w bazie (AGENTS.md §6).
        $osoba = $this->user('pozakontrolerem');
        $klucz = (string) Str::uuid7();

        $wiersz = [
            'author_id' => $osoba->getKey(),
            'body' => 'Wpis wstawiony wprost do bazy.',
            'visibility' => 'public',
            'status' => 'published',
            'display_mode' => 'normal',
            'klucz_wyslania' => $klucz,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('posts')->insert(['id' => (string) Str::uuid7()] + $wiersz);

        $this->expectException(QueryException::class);

        DB::table('posts')->insert(['id' => (string) Str::uuid7()] + $wiersz);
    }

    public function test_dwa_wpisy_bez_klucza_przechodza_bo_indeks_jest_czesciowy(): void
    {
        // Wiersze z `NULL` są poza indeksem częściowym — tak muszą być,
        // inaczej `NOT NULL` rozwaliłoby fabryki i seedery (ADR §8.2 pkt 3).
        $osoba = $this->user('fabryczna');

        Post::factory()->count(2)->for($osoba, 'author')->create();

        $this->assertSame(2, Post::query()->whereNull('klucz_wyslania')->count());
    }
}
