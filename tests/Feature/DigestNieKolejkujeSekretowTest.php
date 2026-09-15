<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\TrescDigestu;
use App\Mail\PodsumowanieTygodnia;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DigestNieKolejkujeSekretowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pelny_payload_kolejki_nie_zawiera_sekretow_a_list_zachowuje_tresc_bez_odczytow_bazy(): void
    {
        config()->set('queue.default', 'database');
        config()->set('mail.default', 'array');
        $odbiorca = $this->user('odbiorca583', ['display_name' => 'Odbiorca migawki']);
        $kucharz = $this->user('kucharz583', ['display_name' => 'Kucharz migawki']);
        $obserwujacy = $this->user('obserwujacy583', ['display_name' => 'Obserwujący migawki']);
        $autor = $this->user('autor583', ['display_name' => 'Autor migawki']);
        $sekrety = [];

        foreach ([$odbiorca, $kucharz, $obserwujacy, $autor] as $i => $osoba) {
            $osoba->load('profile');
            // W pamięci, bez zapisu do users: rozpoznawalne, fałszywe wartości.
            // Nowa nieznana kolumna i relacja też nie mogą trafić do payloadu.
            foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_backup_codes', 'przyszly_sekret'] as $pole) {
                $marker = 'FAKE_SECRET_583_'.$i.'_'.$pole;
                $sekrety[] = $marker;
                $osoba->setRawAttributes([...$osoba->getAttributes(), $pole => $marker]);
            }
            $osoba->setRelation('niepotrzebnaRelacja', (new User)->setRawAttributes(['password' => 'FAKE_NESTED_SECRET_583']));
        }
        $sekrety[] = 'FAKE_NESTED_SECRET_583';
        $przepis = (new Recipe)->setRawAttributes(['title' => 'Rosół migawki', 'private_note' => 'FAKE_RECIPE_SECRET_583']);
        $przepis->setRelation('author', $odbiorca);
        $wykonanie = (new CookedEvent)->setRawAttributes(['id' => '00000000-0000-4000-8000-000000000583', 'note' => 'Notatka migawki']);
        $wykonanie->setRelation('user', $kucharz)->setRelation('recipe', $przepis);
        $wpis = (new Post)->setRawAttributes(['id' => '00000000-0000-4000-8000-000000000584', 'body' => 'Treść migawki']);
        $wpis->setRelation('author', $autor)->setRelation('recipe', $przepis);
        $sekrety[] = 'FAKE_RECIPE_SECRET_583';

        $tresc = new TrescDigestu($odbiorca, [$wykonanie], [$obserwujacy], 3, [$wpis], 'Pytanie migawki?');
        $list = new PodsumowanieTygodnia($tresc);
        $html = $list->render();
        $tekst = view('mail.podsumowanie-tygodnia-tekst', $list->content()->with)->render();
        $temat = $list->envelope()->subject;
        $naglowki = $list->headers()->text;

        // Prawdziwe Mail::queue -> DatabaseQueue -> jobs.payload, bez Mail::fake.
        // Osobny egzemplarz: render() przygotowuje callbacki Symfony dla HTML.
        Mail::to($odbiorca->email)->queue(new PodsumowanieTygodnia($tresc));
        $this->assertDatabaseCount('jobs', 1);
        $payload = (string) DB::table('jobs')->value('payload');
        $dane = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $command = $dane['data']['command'];
        foreach ($sekrety as $sekret) {
            $this->assertStringNotContainsString($sekret, $payload);
            $this->assertStringNotContainsString($sekret, $command);
        }
        $this->assertStringNotContainsString('App\\Models\\User', $command);
        $this->assertStringNotContainsString('App\\Models\\Recipe', $command);
        foreach ([$kucharz, $obserwujacy, $autor] as $osoba) {
            $this->assertStringNotContainsString($osoba->email, $command);
        }
        $this->assertStringContainsString('Notatka migawki', $command);
        $this->assertStringContainsString('Pytanie migawki?', $command);

        // Zmiany po zakolejkowaniu nie mogą zmienić wcześniej dobranego listu.
        DB::table('profiles')->where('user_id', $kucharz->getKey())->update(['display_name' => 'Późniejsza nazwa']);
        $zapytania = [];
        DB::listen(static function ($query) use (&$zapytania): void {
            $zapytania[] = $query->sql;
        });
        $odczytany = unserialize($command);
        $this->assertInstanceOf(SendQueuedMailable::class, $odczytany);
        $mail = $odczytany->mailable;
        $this->assertInstanceOf(PodsumowanieTygodnia::class, $mail);
        $this->assertTrue($mail->hasTo($odbiorca->email));
        $this->assertSame($html, $mail->render());
        $this->assertSame($tekst, view('mail.podsumowanie-tygodnia-tekst', $mail->content()->with)->render());
        $this->assertSame($temat, $mail->envelope()->subject);
        $this->assertSame($naglowki, $mail->headers()->text);
        $this->assertSame([], $zapytania);
        $this->assertSame(3, $mail->tresc->ileNowychObserwujacych);
        // Bez mutowania oryginalnych modeli dla pozostałych odbiorców paczki.
        $this->assertSame('FAKE_SECRET_583_1_password', $kucharz->getAttributes()['password']);
    }

    public function test_odczytuje_stary_format_a_kolejny_zapis_usuwa_modele_i_sekrety(): void
    {
        $osoba = $this->user('stary583', ['display_name' => 'Stara migawka']);
        $osoba->load('profile');
        $osoba->setRawAttributes([...$osoba->getAttributes(), 'remember_token' => 'FAKE_LEGACY_SECRET_583']);
        $pola = ['odbiorca' => $osoba, 'wykonania' => [], 'nowiObserwujacy' => [$osoba],
            'ileNowychObserwujacych' => 1, 'wpisyObserwowanych' => [], 'pytanieGospodarza' => null];
        // Format PHP dawnego DTO, zanim klasa dostała __serialize().
        $klasa = TrescDigestu::class;
        $stary = 'O:'.strlen($klasa).':"'.$klasa.'":'.substr(serialize($pola), 2);
        $this->assertStringContainsString('FAKE_LEGACY_SECRET_583', $stary);
        $tresc = unserialize($stary);
        $this->assertInstanceOf(TrescDigestu::class, $tresc);
        $this->assertSame('Stara migawka', $tresc->odbiorca->displayName());
        $this->assertFalse($tresc->jestPusty());
        $this->assertStringNotContainsString('FAKE_LEGACY_SECRET_583', serialize($tresc));
    }

    public function test_migawka_zachowuje_brak_relacji_i_wpis_z_samym_przepisem(): void
    {
        $osoba = (new User)->setRawAttributes(['id' => '00000000-0000-4000-8000-000000000585']);
        $osoba->setRelation('profile', null);
        $przepis = (new Recipe)->setRawAttributes(['title' => 'Sam przepis']);
        $wpis = (new Post)->setRawAttributes(['id' => '00000000-0000-4000-8000-000000000586', 'body' => null]);
        $wpis->setRelation('author', null)->setRelation('recipe', $przepis);
        $tresc = new TrescDigestu($osoba, [], [], 0, [$wpis], null);
        $list = new PodsumowanieTygodnia($tresc);
        $odczytany = unserialize(serialize($list));
        $this->assertInstanceOf(PodsumowanieTygodnia::class, $odczytany);
        $this->assertSame($list->render(), $odczytany->render());
        $this->assertSame('Użytkownik Kuking', $odczytany->tresc->odbiorca->displayName());
        $this->assertNull($odczytany->tresc->wpisyObserwowanych[0]->author);
        $this->assertSame('Sam przepis', $odczytany->tresc->wpisyObserwowanych[0]->recipe->title);
        $this->assertFalse($odczytany->tresc->jestPusty());
        $this->assertTrue(unserialize(serialize(TrescDigestu::pusta($osoba)))->jestPusty());
    }
}
