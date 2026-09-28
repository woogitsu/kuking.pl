<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\AdresKanoniczny;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * „Wejście na konto — najpierw hasło" (issue #317).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO OSOBNA KLASA, SKORO TOKEN I TRASA SĄ TE SAME
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo różni się POWÓD, a nie mechanizm. Człowiek, który dostaje tę wiadomość,
 * kliknął „Wyślij mi link do zalogowania" i czeka na przycisk wpuszczający
 * go na konto. Dostaje przycisk do ustawienia hasła — czyli coś innego, niż
 * prosił.
 *
 * Do 11 września 2026 szło w tym miejscu zwykłe `UstawienieNowegoHasla`,
 * to samo co przy „nie pamiętam hasła". Jego pierwsze zdanie brzmi „ktoś
 * poprosił o nowe hasło do konta" — a to jest po prostu NIEPRAWDA: nikt
 * o nowe hasło nie prosił. Osoba czytająca tę wiadomość nie miała więc
 * ŻADNEJ szansy dowiedzieć się, czemu dostała nie to, o co prosiła, i jedyny
 * wniosek, jaki mogła z tego wyciągnąć, to „coś jest nie tak z tą stroną".
 * Przy grupie 50+ kończy się to porzuceniem serwisu, a nie dopytaniem.
 *
 * Decyzja właściciela: własna treść, tłumacząca sytuację.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TA WIADOMOŚĆ NIE ROBI — TO JEST WAŻNIEJSZE NIŻ TO, CO ROBI
 * ────────────────────────────────────────────────────────────────────────
 *
 *  1. NIE STRASZY. Ani słowa o „próbie przejęcia konta". My tego NIE WIEMY:
 *     konto bez potwierdzonego adresu bierze się równie dobrze z tego, że
 *     ktoś założył je sobie sam i nie dokończył potwierdzenia. Straszenie
 *     osoby 60+ zdaniem o napastniku nie robi z niej osoby czujnej, tylko
 *     osoby, która więcej tu nie wraca — ten sam powód, dla którego D-085
 *     zakazało alarmu w zaproszeniu dla adresu bez konta.
 *  2. NIE TŁUMACZY MECHANIKI ATAKU. „Pre-account-hijacking", „sesje"
 *     i „token" nie mają tu wstępu. Wyjaśnienie ma być prawdziwe i wystarczyć
 *     do podjęcia decyzji, a nie kompletne.
 *  3. NIE OBWINIA ODBIORCY. Nic się tu nie stało z jego winy i ani jedno
 *     zdanie tego nie sugeruje.
 *  4. NIE PRZYPISUJE RODZAJU (COPY_STYLE §2). Zdania są przebudowane, a nie
 *     wypisane w dwóch wariantach.
 *
 * Nie mówimy też „list": to jest poczta w kopercie, a tu chodzi o wiadomość
 * e-mail (zgłoszenie właściciela, pilnuje tego
 * `DrzwiWejsciowePrawdaTest::test_ekrany_nie_mowia_o_liscie_tam_gdzie_chodzi_o_e_mail`).
 *
 * Bez gry słowem „kuKING" — D-009 zabrania jej w komunikatach technicznych.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  BEZ POWITANIA PO IMIENIU — ŚWIADOMIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * `UstawienieNowegoHasla` i `LinkDoLogowania` witają nazwą z profilu. Tutaj
 * NIE, i to jest jedyna różnica w budowie wobec tamtych dwóch. Nazwa
 * w profilu tego konta należy do osoby, która je założyła — a cała ta
 * wiadomość istnieje dlatego, że NIE WIEMY, czy to ta sama osoba, która ją
 * czyta. „Halina," na górze wiadomości do kogoś, kto nie ma na imię Halina,
 * jest w najlepszym razie myląca, a w najgorszym ujawnia właścicielowi
 * skrzynki cudzą nazwę, o którą nikt nie pytał.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TOKEN, TRASA I WAŻNOŚĆ SĄ TE SAME CO PRZY RESECIE HASŁA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Klasa rozszerza `ResetPassword` i buduje adres tym samym `resetUrl()`, więc
 * kliknięcie prowadzi na `password.reset` i kończy się w
 * `PasswordResetController::reset()` — a ten ustawia hasło, kasuje sesje,
 * anuluje zamówioną zmianę adresu ORAZ POTWIERDZA ADRES (issue #317).
 * To ostatnie jest powodem, dla którego wolno tu obiecać, że następnym razem
 * będzie zwyczajnie.
 *
 * `ShouldQueue` z tego samego powodu co przy `UstawienieNowegoHasla`
 * (audyt W3-13) i przy `LinkDoLogowania` (D-056): awaria poczty nie ma prawa
 * zamienić udanej czynności w błąd 500, bo 500 zdarzałoby się WYŁĄCZNIE tam,
 * gdzie konto istnieje i jest niepotwierdzone — czyli sam kod odpowiedzi
 * stałby się wyrocznią.
 */
final class UstawienieHaslaZamiastLinku extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    // Martwy link po zastąpionym tokenie nie wychodzi (audyt B8-04).
    use SwiezyTokenResetuHasla;

    /**
     * Kolejka `high` (audyt B8-06): ten list wpuszcza człowieka na konto
     * i ma krótki termin ważności, więc nie staje w FIFO za podsumowaniem
     * tygodnia na `default`. Worker czyta `high` pierwszą (`docker/entrypoint.sh`,
     * pilnuje `UmowaKolejkiTest`).
     *
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'high'];
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $minut = (int) config(
            'auth.passwords.'.config('auth.defaults.passwords').'.expire',
            60,
        );

        return (new MailMessage)
            /*
             * TEMAT MÓWI, ŻE TO ODPOWIEDŹ NA PROŚBĘ O WEJŚCIE NA KONTO,
             * I OD RAZU ZAPOWIADA RÓŻNICĘ.
             *
             * Sam „Ustaw nowe hasło do Kuking" (temat z odzyskiwania hasła)
             * wygląda w skrzynce na odpowiedź na zupełnie inną prośbę i daje
             * powód, żeby wiadomości nie otworzyć.
             */
            ->subject('Wejście na konto w Kuking — najpierw ustaw hasło')
            ->view('mail.haslo-zamiast-linku', [
                // `resetUrl()` z klasy nadrzędnej — ten sam adres, co przy
                // odzyskiwaniu hasła, łącznie z hakiem
                // `ResetPassword::createUrlUsing()`.
                //
                // W `AdresKanoniczny`, bo host tego adresu nie ma prawa
                // zależeć od nagłówków żądania (S2, D-071) — dokładnie ten
                // sam zabieg co w `UstawienieNowegoHasla`.
                'linkUrl' => AdresKanoniczny::zbuduj(fn (): string => $this->resetUrl($notifiable)),
                'waznoscTekst' => self::waznosc($minut),
            ]);
    }

    /**
     * Ważność po ludzku, BEZ godziny zegarowej — uzasadnienie w
     * `UstawienieNowegoHasla::waznosc()`: `config/app.php` ma na sztywno
     * `'timezone' => 'UTC'`, więc „działa do 09:15" pokazywałoby czas
     * przesunięty względem zegara w polskiej kuchni.
     */
    private static function waznosc(int $minut): string
    {
        return $minut === 60 ? 'przez godzinę' : "przez {$minut} min.";
    }
}
