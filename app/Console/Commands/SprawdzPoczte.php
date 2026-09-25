<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MailFailure;
use App\Support\Poczta;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * `kuking:sprawdz-poczte {adres}` — jedna prawdziwa wiadomość i uczciwa
 * odpowiedź na pytanie „czy poczta wychodzi”.
 *
 * PO CO TO ISTNIEJE
 * Do dziś jedynym sposobem sprawdzenia poczty było założenie konta na
 * produkcji i czekanie, czy przyjdzie list. To zły test z czterech powodów:
 * zostawia śmieciowe konto, nie mówi NIC, gdy list nie przychodzi (padł SMTP?
 * nie chodzi worker? poszło do „Spamu”?), nie da się go powtórzyć na czterech
 * polskich skrzynkach z rzędu i nie pokazuje, z jakiego adresu wyszedł.
 *
 * Gorzej: przy `MAIL_MAILER=log` — czyli przy tym, co stoi dziś w
 * `.env.example` i w produkcyjnym runbooku — Laravel przyjmuje wiadomość,
 * zapisuje ją do dziennika i ZWRACA SUKCES. Rejestracja kończy się zieloną
 * stroną, zadanie w kolejce kończy się bez błędu, monitoring milczy, a do
 * nikogo nic nie dociera. Pierwsza osoba, która zapomni hasła, traci konto
 * bezpowrotnie — i to jest dziś blokada numer jeden przed wpuszczeniem
 * pierwszych ludzi.
 *
 * Ta komenda ma po wybraniu dostawcy odpowiedzieć w kilkanaście sekund:
 * czym wysyłamy, z jakiego adresu, czy poszło synchronicznie czy przez
 * kolejkę — a przy błędzie powiedzieć, CO ZROBIĆ, nie wypluć wyjątku.
 *
 * CZEGO ŚWIADOMIE NIE ROBIMY
 *
 * 1. NIE WYSYŁAMY NICZEGO PRZY STEROWNIKU `log` ANI `array`. Kusi, żeby
 *    wysłać „na próbę” i pokazać treść w dzienniku. Dwa powody przeciw:
 *    pełna wiadomość z adresem odbiorcy w logu to dane osobowe w logu
 *    (AGENTS.md §7), a zielony wynik przy sterowniku, który nic nie
 *    dostarcza, jest dokładnie tym kłamstwem, które ta komenda ma tępić.
 *    Zamiast tego komenda kończy się porażką i mówi, co ustawić.
 *
 * 2. NIE SPRAWDZAMY REKORDÓW DNS (SPF, DKIM, DMARC). Dałoby się przez
 *    `dns_get_record()`, ale odpowiedź byłaby wróżeniem: selektor DKIM
 *    nadaje dostawca (nie znamy go z konfiguracji), a poprawność podpisu
 *    widać dopiero w nagłówkach DORĘCZONEJ wiadomości. Udawana kontrola
 *    byłaby gorsza niż jej brak — komenda mówi więc, gdzie sprawdzić te
 *    trzy linijki naprawdę (`docs/infra/POCZTA_URUCHOMIENIE.md`).
 *
 * 3. NIE MÓWIMY „DORĘCZONO”. Brak wyjątku znaczy tylko tyle, że dostawca
 *    PRZYJĄŁ wiadomość. O tym, czy weszła do skrzynki, czy do „Spamu”,
 *    mówi panel dostawcy i sama skrzynka. Podsumowanie mówi to wprost.
 *
 * 4. NIE ZAKŁADAMY WŁASNEJ KLASY `Mailable` ANI WIDOKU. Wiadomość
 *    diagnostyczna nie ma przycisku ani stopki wypisania — jest zwykłym
 *    tekstem przez `Mail::raw()`. Osobna klasa i osobny szablon byłyby
 *    drugim miejscem, w którym trzeba pamiętać o zmianie nadawcy.
 *
 * 5. NIE ZMIENIAMY KONFIGURACJI. Komenda niczego nie naprawia i niczego
 *    nie zapisuje — czyta stan i mówi prawdę. Zmienne ustawia człowiek
 *    w panelu Railway, świadomie i w jednym miejscu.
 */
class SprawdzPoczte extends Command
{
    protected $signature = 'kuking:sprawdz-poczte
                            {adres : Skrzynka, na którą ma przyjść wiadomość testowa — najlepiej wp.pl, o2.pl, interia.pl albo onet.pl}
                            {--kolejka : Wyślij przez kolejkę zamiast synchronicznie — sprawdza dodatkowo, czy chodzi worker}';

    protected $description = 'Wysyła jedną wiadomość testową i mówi po polsku, czy poczta naprawdę wychodzi';

    /**
     * Adresy nadawcy, które są ustawieniem-zaślepką, a nie decyzją.
     *
     * `hello@example.com` to domyślna wartość Laravela. List z linkiem do
     * zmiany hasła od takiego nadawcy jest phishingiem w czystej postaci.
     *
     * @var list<string>
     */
    private const DOMENY_ZASLEPKI = ['example.com', 'example.org', 'example.net', 'localhost'];

    /**
     * Początki adresu zakazane przez `docs/brand/BRAND_EXTENDED.md`.
     *
     * Na list z Kuking ma się dać odpisać — także wtedy (a właściwie
     * zwłaszcza wtedy), gdy ktoś nie rozumie, co dostał. Skrzynka, która
     * odbija odpowiedzi, zamyka jedyny kanał kontaktu, jaki zna osoba
     * niezalogowana.
     *
     * @var list<string>
     */
    private const ZAKAZANE_POCZATKI_NADAWCY = ['noreply@', 'no-reply@', 'donotreply@', 'do-not-reply@', 'nie-odpowiadaj@'];

    public function handle(): int
    {
        $adres = trim((string) $this->argument('adres'));

        if (filter_var($adres, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('„'.$this->bezZnacznikow($adres).'” nie wygląda na adres e-mail.');
            $this->line('Podaj skrzynkę, do której masz dostęp, na przykład:');
            $this->line('  php artisan kuking:sprawdz-poczte ty@wp.pl');

            return self::FAILURE;
        }

        $sterownik = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$sterownik}.transport", '');
        $przezKolejke = (bool) $this->option('kolejka');

        $this->newLine();
        $this->line('<options=bold>Sprawdzenie poczty Kuking</>');
        $this->newLine();
        $this->table(['Co', 'Wartość'], $this->stanKonfiguracji($sterownik, $transport, $adres, $przezKolejke));

        if (! $this->sterownikJestZdefiniowany($sterownik)) {
            return self::FAILURE;
        }

        // TU JEST CAŁY SENS TEJ KOMENDY. `Poczta::dziala()` odpowiada tym
        // samym pytaniem, którym ekran „Nie pamiętam hasła” decyduje, czy
        // w ogóle pokazać formularz — jedno źródło prawdy, nie drugie.
        if (! Poczta::dziala()) {
            $this->wyjasnijSterownikBezDostawy($sterownik);

            return self::FAILURE;
        }

        $ostrzezen = $this->ostrzezenia($sterownik, $transport);
        $this->stanKolejki($przezKolejke);

        $temat = 'Kuking — sprawdzenie poczty ('.now()->format('Y-m-d H:i').' UTC)';
        $tresc = $this->tresc($sterownik, $adres);

        $this->newLine();
        $this->line($przezKolejke
            ? "Wkładam wiadomość do kolejki, adresat: {$adres}"
            : "Wysyłam wiadomość synchronicznie, adresat: {$adres}");

        try {
            $this->wyslij($adres, $temat, $tresc, $przezKolejke);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Nie udało się wysłać.');
            $this->newLine();
            $this->coZrobicPoBledzie($e, $sterownik, $transport);
            $this->newLine();
            $this->line('<options=bold>Szczegół techniczny</> (do zgłoszenia, nie do czytania na głos):');
            $this->line('  '.$e::class.': '.$this->bezZnacznikow($e->getMessage()));

            return self::FAILURE;
        }

        $this->newLine();

        if ($przezKolejke) {
            $this->info('Zadanie trafiło do kolejki.');
            $this->coDalejPoKolejce();
        } else {
            $this->info('Serwer poczty przyjął wiadomość bez błędu.');
        }

        $this->coDalej($ostrzezen);

        return self::SUCCESS;
    }

    /**
     * Stan konfiguracji — to samo, co człowiek musiałby wyklikać w panelu
     * Railway i posklejać z trzech plików.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function stanKonfiguracji(string $sterownik, string $transport, string $adres, bool $przezKolejke): array
    {
        $nadawca = (string) config('mail.from.address');
        $nazwaNadawcy = (string) config('mail.from.name');

        $wiersze = [
            ['Sterownik (MAIL_MAILER)', $this->pusteJakoMyslnik($sterownik)],
            ['Transport Symfony', $this->pusteJakoMyslnik($transport)],
            ['Nadawca (MAIL_FROM_*)', trim($nazwaNadawcy.' <'.$nadawca.'>')],
            ['Adresat', $adres],
            ['Środowisko (APP_ENV)', (string) config('app.env')],
            ['Sposób wysyłki', $przezKolejke
                ? 'przez kolejkę — wyśle worker'
                : 'synchronicznie — komenda czeka na odpowiedź serwera'],
        ];

        if ($transport === 'smtp') {
            $wiersze[] = ['Serwer SMTP', (string) config("mail.mailers.{$sterownik}.host").':'.(string) config("mail.mailers.{$sterownik}.port")];
            $wiersze[] = ['Szyfrowanie (MAIL_SCHEME)', $this->pusteJakoMyslnik((string) config("mail.mailers.{$sterownik}.scheme"))];
            $wiersze[] = ['Login SMTP', $this->czyUstawione((string) config("mail.mailers.{$sterownik}.username"))];
            $wiersze[] = ['Hasło SMTP', $this->czyUstawione((string) config("mail.mailers.{$sterownik}.password"))];
        }

        if ($transport === 'emaillabs') {
            $wiersze[] = ['Adres API', (string) config('services.emaillabs.endpoint')];
            $wiersze[] = ['Konto SMTP w API (EMAILLABS_SMTP_ACCOUNT)', $this->pusteJakoMyslnik((string) config('services.emaillabs.smtp_account'))];
            $wiersze[] = ['Klucz aplikacji (EMAILLABS_APP_KEY)', $this->czyUstawione((string) config('services.emaillabs.key'))];
            $wiersze[] = ['Klucz autoryzacyjny (EMAILLABS_SECRET_KEY)', $this->czyUstawione((string) config('services.emaillabs.secret'))];
            $wiersze[] = ['Śledzenie odnośników', config('services.emaillabs.tracking') ? 'WŁĄCZONE' : 'wyłączone (zalecane)'];
        }

        if ($transport === 'postmark') {
            $wiersze[] = ['Klucz Postmarka (POSTMARK_API_KEY)', $this->czyUstawione((string) config('services.postmark.key'))];
        }

        if ($transport === 'resend') {
            $wiersze[] = ['Klucz Resend (RESEND_API_KEY)', $this->czyUstawione((string) config('services.resend.key'))];
        }

        if ($transport === 'ses' || $transport === 'ses-v2') {
            $wiersze[] = ['Region SES', $this->pusteJakoMyslnik((string) config('services.ses.region'))];
            $wiersze[] = ['Klucz SES', $this->czyUstawione((string) config('services.ses.key'))];
        }

        return $wiersze;
    }

    /** Nazwa sterownika musi istnieć w `config/mail.php` — inaczej wysyłka pada dopiero przy pierwszym liście. */
    private function sterownikJestZdefiniowany(string $sterownik): bool
    {
        $mailery = config('mail.mailers');

        if (! is_array($mailery) || array_key_exists($sterownik, $mailery)) {
            return true;
        }

        $this->error("W `config/mail.php` nie ma sterownika o nazwie „{$sterownik}”.");
        $this->newLine();
        $this->line('Laravel przewróci się dopiero przy pierwszym liście — czyli przy czyjejś rejestracji, nie tutaj.');
        $this->line('Dostępne nazwy: '.implode(', ', array_map('strval', array_keys($mailery))).'.');
        $this->line('Ustaw MAIL_MAILER na jedną z nich albo dopisz brakujący sterownik do `config/mail.php`.');

        return false;
    }

    /**
     * Sterownik przyjmuje wiadomość i nie dostarcza jej nikomu.
     *
     * To jest dokładnie ten stan, w którym serwis jest dziś — i jedyny,
     * w którym wynik „wysłano” byłby kłamstwem.
     */
    private function wyjasnijSterownikBezDostawy(string $sterownik): void
    {
        // Powód bierzemy z tej samej klasy, która o tym decyduje — inaczej
        // przy nowym sterowniku ta lista rozjechałaby się z rzeczywistością
        // i komenda tłumaczyłaby coś innego, niż realnie stoi na drodze.
        $opis = Poczta::przeszkoda() ?? 'Sterownik „'.$sterownik.'” nie dostarcza wiadomości.';

        $this->error('Poczta nie wychodzi i nie ma czego sprawdzać.');
        $this->newLine();
        $this->line($opis);
        $this->newLine();
        $this->line('<options=bold>Świadomie nie wysyłam nic „na próbę”</> — pełna wiadomość z adresem odbiorcy trafiłaby');
        $this->line('do logu, a to są dane osobowe w logu (AGENTS.md §7). Zielony wynik przy sterowniku,');
        $this->line('który nic nie dostarcza, byłby też dokładnie tym kłamstwem, które ta komenda ma tępić.');
        $this->newLine();
        $this->line('<options=bold>Co zrobić</>');
        $this->line('  1. Wybierz dostawcę i przejdź krok po kroku przez `docs/infra/POCZTA_URUCHOMIENIE.md`.');
        $this->line('  2. Ustaw w Railway MAIL_MAILER (na dziś: `emaillabs`) i resztę zmiennych z tego dokumentu.');
        $this->line('     Uwaga: `smtp` NIE zadziała na planach Railway Free i Hobby — tam ruch SMTP jest wyłączony,');
        $this->line('     a połączenie nie tyle pada, co wisi bez odpowiedzi. Patrz `docs/DECISIONS.md` D-047.');
        $this->line('  3. Zrestartuj serwisy — konfiguracja jest zapiekana przy starcie kontenera (`php artisan optimize`).');
        $this->line('  4. Uruchom tę komendę jeszcze raz.');
        $this->newLine();
        $this->line('Dopóki to nie jest zrobione, ekran „Nie pamiętam hasła” świadomie nie przyjmuje adresu');
        $this->line('i odsyła do '.(string) config('kuking.community.contact_email').' — patrz `App\Support\Poczta`.');
    }

    /**
     * Rzeczy, które nie blokują wysyłki, ale sprawiają, że list nie dojdzie
     * albo dojdzie do „Spamu”. Zwraca liczbę wypisanych ostrzeżeń.
     */
    private function ostrzezenia(string $sterownik, string $transport): int
    {
        $ostrzezenia = [];

        // FAILOVER / ROUNDROBIN: pokazujemy, przez co naprawdę idzie list.
        // Łańcucha z `log` albo `array` tu już nie ma — od issue #1084
        // odrzuca go `Poczta::dziala()` wyżej, zanim cokolwiek wyjdzie.
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $skladowe = config("mail.mailers.{$sterownik}.mailers");

            if (is_array($skladowe)) {
                $ostrzezenia[] = 'Sterownik `'.$sterownik.'` wysyła przez: '.implode(', ', array_map('strval', $skladowe)).'.';
            }
        }

        if ($transport === 'emaillabs') {
            $konto = (string) config('services.emaillabs.smtp_account');

            // Kształt `1.nazwa.smtp` bierze się z panelu i ze specyfikacji API
            // (`smtpAccount`, przykład „1.test.smtp"). Wpisanie tu loginu SMTP
            // albo samej nazwy konta kończy się odmową dostawcy, której powód
            // trudno zgadnąć z kodu błędu.
            if ($konto !== '' && preg_match('/^\d+\..+\.smtp$/', $konto) !== 1) {
                $ostrzezenia[] = 'EMAILLABS_SMTP_ACCOUNT to `'.$konto.'`, a panel EmailLabs podaje tę wartość w kształcie '
                    .'`1.nazwa.smtp`. Jeśli w tym polu jest login SMTP zamiast nazwy konta, API odrzuci wysyłkę.';
            }

            if (config('services.emaillabs.tracking')) {
                $ostrzezenia[] = 'Śledzenie odnośników jest WŁĄCZONE (EMAILLABS_TRACKING). EmailLabs podmieni wtedy każdy link '
                    .'w liście na własny adres przekierowujący — także link do zmiany hasła, który przestanie wyglądać na adres kuking.pl. '
                    .'Dla osoby 60+ to jest kształt phishingu, przed którym ostrzegają banki.';
            }
        }

        if ($transport === 'smtp') {
            $ostrzezenia[] = 'Sterownik `smtp` NIE DZIAŁA na planach Railway Free, Trial i Hobby — SMTP jest tam wyłączony. '
                .'Połączenie nie kończy się błędem, tylko wisi: zadanie w kolejce wchodzi w `RUNNING` i nigdy nie osiąga ani `DONE`, ani `FAIL`. '
                .'Na tych planach właściwą wartością MAIL_MAILER jest `emaillabs` (docs/DECISIONS.md D-047).';

            $host = (string) config("mail.mailers.{$sterownik}.host");

            if (in_array($host, ['127.0.0.1', 'localhost', ''], true)) {
                $ostrzezenia[] = 'MAIL_HOST to `'.($host === '' ? 'puste' : $host).'` — czyli wartość z `.env.example`, a nie serwer dostawcy. '
                    .'Na Railway nic nie nasłuchuje pod tym adresem, więc każda wysyłka skończy się błędem połączenia.';
            }

            if ((string) config("mail.mailers.{$sterownik}.username") === '') {
                $ostrzezenia[] = 'MAIL_USERNAME jest pusty. Prawie każdy dostawca wymaga logowania — bez tego dostaniesz odmowę 535.';
            }

            $scheme = (string) config("mail.mailers.{$sterownik}.scheme");
            $port = (string) config("mail.mailers.{$sterownik}.port");

            if ($port === '465' && $scheme !== 'smtps') {
                $ostrzezenia[] = 'Port 465 to szyfrowanie od pierwszego bajtu — MAIL_SCHEME powinien być `smtps`. '
                    .'Przy `tls` nie dojdzie do żadnego połączenia: Symfony nie zna tego schematu i transport wywraca się przy budowie (PR #198).';
            }

            if ($port === '587' && $scheme === 'smtps') {
                $ostrzezenia[] = 'Port 587 to STARTTLS — MAIL_SCHEME powinien być `smtp`, nie `smtps`. '
                    .'NIE `tls`: tej wartości Symfony nie zna i transportu nie da się wtedy nawet zbudować (PR #198).';
            }
        }

        // SES CZYTA TE SAME ZMIENNE, CO CLOUDFLARE R2 (`config/services.php`).
        // W tym repozytorium `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`
        // i `AWS_DEFAULT_REGION` należą do R2 (`.railway/railway.ts` ustawia
        // region literalnie na `auto`). Wpisanie MAIL_MAILER=ses bez własnych
        // kluczy oznaczałoby próbę logowania do Amazona kluczem Cloudflare —
        // i błąd, po którym nikt nie zgadnie, o co chodziło.
        if ($transport === 'ses' || $transport === 'ses-v2') {
            $region = (string) config('services.ses.region');

            if ($region === 'auto' || $region === '') {
                $ostrzezenia[] = 'Region SES to `'.($region === '' ? 'puste' : $region).'`. Amazon nie ma takiego regionu — `auto` należy do Cloudflare R2. '
                    .'Ustaw MAIL_SES_REGION na `eu-central-1` (Frankfurt) oraz osobne MAIL_SES_KEY i MAIL_SES_SECRET, '
                    .'żeby poczta nie logowała się kluczem od zdjęć.';
            }

            if ((string) config('services.ses.key') === '') {
                $ostrzezenia[] = 'Klucz SES jest pusty (MAIL_SES_KEY). SES nie przyjmie wysyłki anonimowo.';
            }
        }

        if ($transport === 'postmark' && (string) config('services.postmark.key') === '') {
            $ostrzezenia[] = 'POSTMARK_API_KEY jest pusty — Postmark odrzuci wysyłkę z błędem uwierzytelnienia.';
        }

        if ($transport === 'resend' && (string) config('services.resend.key') === '') {
            $ostrzezenia[] = 'RESEND_API_KEY jest pusty — Resend odrzuci wysyłkę z błędem uwierzytelnienia.';
        }

        if ($transport === 'sendmail') {
            $ostrzezenia[] = 'Sterownik `sendmail` wymaga lokalnego serwera poczty w kontenerze. Obraz Kuking go nie ma, '
                .'a nawet gdyby miał — listy z adresu IP Railway lądują w polskich skrzynkach w „Spamie”.';
        }

        foreach ($this->ostrzezeniaNadawcy() as $ostrzezenie) {
            $ostrzezenia[] = $ostrzezenie;
        }

        if ($ostrzezenia === []) {
            return 0;
        }

        $this->newLine();
        $this->warn('Uwagi do konfiguracji ('.count($ostrzezenia).'):');

        foreach ($ostrzezenia as $numer => $ostrzezenie) {
            $this->line('  '.($numer + 1).'. '.$ostrzezenie);
        }

        return count($ostrzezenia);
    }

    /** @return list<string> */
    private function ostrzezeniaNadawcy(): array
    {
        $nadawca = mb_strtolower(trim((string) config('mail.from.address')));

        if ($nadawca === '') {
            return ['MAIL_FROM_ADDRESS jest pusty. Wiadomość bez nadawcy odrzuci każdy serwer.'];
        }

        $ostrzezenia = [];

        foreach (self::ZAKAZANE_POCZATKI_NADAWCY as $poczatek) {
            if (str_starts_with($nadawca, $poczatek)) {
                $ostrzezenia[] = 'Nadawcą jest `'.$nadawca.'`. `docs/brand/BRAND_EXTENDED.md` tego zabrania: na list z Kuking ma się dać odpisać. '
                    .'Ustaw MAIL_FROM_ADDRESS na skrzynkę, którą ktoś czyta.';
            }
        }

        if (in_array($this->domenaNadawcy(), self::DOMENY_ZASLEPKI, true)) {
            $ostrzezenia[] = 'Nadawcą jest `'.$nadawca.'`, czyli domena-zaślepka. List z linkiem do zmiany hasła od takiego nadawcy '
                .'wygląda dokładnie jak phishing, przed którym ostrzegają banki.';
        }

        $kontakt = mb_strtolower(trim((string) config('kuking.community.contact_email')));

        if ($kontakt !== '' && $nadawca !== $kontakt) {
            $ostrzezenia[] = 'Wysyłamy z `'.$nadawca.'`, a ludziom pokazujemy `'.$kontakt.'`. To mają być dwa razy te same znaki '
                .'(decyzja właściciela z 7 września 2026) — inaczej nie wiadomo, która skrzynka odbiera odpowiedzi. '
                .'Popraw MAIL_FROM_ADDRESS albo KUKING_CONTACT_EMAIL w Railway.';
        }

        return $ostrzezenia;
    }

    /**
     * Sterownik może działać, a listy i tak nie wyjdą — bo wszystkie
     * powiadomienia transakcyjne są kolejkowane (`ShouldQueue`, audyt W3-13),
     * a kolejka bez workera to tabela, do której się tylko dopisuje.
     */
    private function stanKolejki(bool $przezKolejke): void
    {
        $polaczenie = (string) config('queue.default');

        $this->newLine();
        $this->line('<options=bold>Kolejka</> (tędy idą prawdziwe listy serwisu — wszystkie powiadomienia są kolejkowane)');
        $this->line('  Połączenie (QUEUE_CONNECTION): '.$this->pusteJakoMyslnik($polaczenie));

        if ($polaczenie === 'sync') {
            $this->line('  Tryb `sync` wysyła list w tym samym żądaniu. Na produkcji znaczyłoby to, że awaria serwera poczty');
            $this->line('  przewraca rejestrację PO utworzeniu konta — dokładnie to naprawiał audyt W3-13.');

            return;
        }

        if ($polaczenie !== 'database') {
            return;
        }

        try {
            $czekajace = DB::table('jobs')->count();
            $nieudane = DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            $this->line('  Nie udało się odczytać tabel kolejki: '.$this->bezZnacznikow($e->getMessage()));

            return;
        }

        $this->line("  Zadania czekające: {$czekajace}");
        $this->line("  Zadania nieudane: {$nieudane}");

        if ($nieudane > 0) {
            $this->warn('  W `failed_jobs` leżą nieudane zadania. Zajrzyj tam — mogą to być listy, które nigdy nie wyszły:');
            $this->line('    php artisan queue:failed');
        }

        if ($czekajace > 50 && ! $przezKolejke) {
            $this->warn('  Kolejka rośnie. Jeśli worker nie chodzi, listy będą się w niej odkładać i nikt tego nie zauważy.');
        }

        $this->stanPrzepadlychListow();
    }

    /**
     * Listy, które PRZEPADŁY — czyli to, co `failed_jobs` wyżej liczy razem
     * z przetwarzaniem zdjęć i eksportami (issue #234, D-062).
     *
     * Dwa wiersze, nie tabela: pełną listę z kategorią odmowy i instrukcją
     * „co zrobić" wypisuje `kuking:nieudane-listy`. Tutaj chodzi tylko o to,
     * żeby człowiek diagnozujący pocztę nie musiał się domyślać, że taka
     * komenda istnieje.
     */
    private function stanPrzepadlychListow(): void
    {
        try {
            $nieodhaczone = MailFailure::query()->nieodhaczone()->count();
        } catch (Throwable) {
            // Tabeli może jeszcze nie być (kod wdrożony przed migracją).
            // To nie jest powód, żeby przewracać diagnostykę poczty.
            return;
        }

        if ($nieodhaczone === 0) {
            $this->line('  Listów, które przepadły: 0');

            return;
        }

        $this->newLine();
        $this->warn('  Listy, które PRZEPADŁY i nikt tego nie odhaczył: '.$nieodhaczone);
        $this->line('  To wiadomości do ludzi, które nie wyszły i już nie wyjdą. Przeczytaj, co i dlaczego:');
        $this->line('    php artisan kuking:nieudane-listy');
    }

    private function wyslij(string $adres, string $temat, string $tresc, bool $przezKolejke): void
    {
        // Domknięcie zamiast klasy `Mailable`: do kolejki trafiają trzy
        // napisy, a nie nowa klasa i nowy szablon do utrzymywania.
        // `Mail::raw()` używa globalnego nadawcy z `config/mail.php`,
        // czyli dokładnie tego, którego sprawdzamy.
        if ($przezKolejke) {
            dispatch(function () use ($adres, $temat, $tresc): void {
                Mail::raw($tresc, function (Message $wiadomosc) use ($adres, $temat): void {
                    $wiadomosc->to($adres)->subject($temat);
                });
            });

            return;
        }

        Mail::raw($tresc, function (Message $wiadomosc) use ($adres, $temat): void {
            $wiadomosc->to($adres)->subject($temat);
        });
    }

    /**
     * Treść wiadomości testowej.
     *
     * Pisana tak, żeby osoba, która dostanie ją przez pomyłkę, wiedziała
     * w pierwszym zdaniu, że nie musi nic robić. Zero emoji, zero
     * wykrzykników, bez gry słowem „kuKING” — to jest komunikat techniczny
     * (D-009, `docs/brand/COPY_STYLE.md`).
     */
    private function tresc(string $sterownik, string $adres): string
    {
        $nadawca = (string) config('mail.from.address');
        $srodowisko = (string) config('app.env');
        $czas = now()->format('Y-m-d H:i:s');

        return <<<TEKST
        Dzień dobry.

        To jest wiadomość testowa z Kuking. Ktoś sprawdza, czy poczta serwisu
        w ogóle wychodzi. Nie musisz nic robić ani odpisywać.

        Adresat: {$adres}
        Nadawca: {$nadawca}
        Sterownik: {$sterownik}
        Środowisko: {$srodowisko}
        Wysłano: {$czas} UTC

        Jeśli ten list trafił do folderu „Spam”, to jest wynik testu, a nie
        usterka Twojej skrzynki. Powiedz o tym osobie, która test uruchomiła.

        Zespół Kuking.pl
        TEKST;
    }

    /**
     * Co zrobić po błędzie. Kolejność dopasowań ma znaczenie: od najbardziej
     * konkretnego komunikatu do worka na resztę.
     */
    private function coZrobicPoBledzie(Throwable $e, string $sterownik, string $transport): void
    {
        $komunikat = mb_strtolower($e->getMessage());

        $kroki = match (true) {
            $this->zawiera($komunikat, ['authentication failed', 'authentication unsuccessful', '535', 'invalid credentials', 'unauthorized', '401', 'forbidden', '403']) => [
                'Dostawca odrzucił logowanie — dane dostępowe są złe albo unieważnione.',
                'Wygeneruj klucz (hasło SMTP) od nowa w panelu dostawcy i wklej go w Railway.',
                'Sprawdź, czy na końcu wartości nie ma spacji — kopiowanie z panelu lubi ją dokleić.',
                'Po zmianie zmiennej ZRESTARTUJ serwis: konfiguracja jest zapiekana przy starcie kontenera.',
                'Klucz „do wysyłki” to zwykle inny klucz niż „do API” — sprawdź, czy w konfiguracji jest ten pierwszy.',
                'Przy EmailLabs po API: dane SMTP (login i hasło z sekcji „Konta SMTP”) NIE działają na API. '
                    .'Potrzebne są dwa klucze z Konto → Ustawienia → API: EMAILLABS_APP_KEY i EMAILLABS_SECRET_KEY.',
            ],

            $this->zawiera($komunikat, ['connection could not be established', 'connection refused', 'could not connect', 'timed out', 'timeout', 'network is unreachable', 'name or service not known', 'getaddrinfo', 'no such host']) => [
                'Nie udało się nawiązać połączenia z serwerem poczty.',
                'Sprawdź MAIL_HOST — literówka w nazwie serwera wygląda dokładnie tak samo jak awaria dostawcy.',
                'Sprawdź MAIL_PORT: 587 (STARTTLS, MAIL_SCHEME=smtp) albo 465 (MAIL_SCHEME=smtps). Port 25 bywa blokowany.',
                'Jeśli host i port są dobre, dostawca może blokować ruch z tego adresu IP — zajrzyj do jego panelu.',
                'Na planach Railway Free, Trial i Hobby port SMTP jest WYŁĄCZONY i nic tego nie obejdzie — '
                    .'tam jedyną drogą jest sterownik `emaillabs`, który idzie przez HTTPS (docs/DECISIONS.md D-047).',
            ],

            $this->zawiera($komunikat, ['ssl', 'tls', 'certificate', 'stream_socket_enable_crypto']) => [
                'Połączenie się nawiązało, ale nie udało się go zaszyfrować.',
                'To prawie zawsze niezgodność portu i MAIL_SCHEME: 587 → `smtp`, 465 → `smtps`. Wartość `tls` NIE ISTNIEJE.',
                'Nie wyłączaj weryfikacji certyfikatu, żeby to obejść — to zdejmuje ochronę z hasła SMTP w locie.',
            ],

            $this->zawiera($komunikat, ['not verified', 'unverified', 'domain is not', 'sender identity', 'from address', '550', '553', '554', 'sender not allowed']) => [
                'Serwer przyjął połączenie, ale odrzucił NADAWCĘ.',
                'W panelu dostawcy domena `'.$this->domenaNadawcy().'` musi mieć status „zweryfikowana”.',
                'Rekordy SPF i DKIM w Cloudflare muszą być „DNS only” (szara chmurka), nie „Proxied” — proxowanie psuje weryfikację.',
                'Rekordy DNS rozchodzą się nawet kilkadziesiąt minut. Jeśli doszły przed chwilą, poczekaj i powtórz.',
                'Sprawdź, czy MAIL_FROM_ADDRESS jest z tej samej domeny co zweryfikowana.',
            ],

            $this->zawiera($komunikat, ['rate limit', 'too many', '429', 'quota', 'sending limit', 'daily limit']) => [
                'Dostawca odciął wysyłkę limitem.',
                'Sprawdź limit DZIENNY, nie miesięczny — to on odcina pierwszy (`docs/decyzje/POCZTA.md` §0).',
                'Konto Amazon SES w sandboksie ma 200 wiadomości na dobę i wysyła TYLKO na zweryfikowane adresy.',
                'Poczekaj albo podnieś plan; ta sama komenda sprawdzi to ponownie.',
            ],

            $this->zawiera($komunikat, ['is not defined', 'unsupported mail transport']) => [
                'Laravel nie umie zbudować transportu o tej nazwie.',
                'Sprawdź, czy MAIL_MAILER („'.$sterownik.'”) to nazwa z tablicy `mailers` w `config/mail.php`.',
                'Nazwa sterownika i nazwa transportu to dwie różne rzeczy — patrz komentarz w tamtym pliku.',
            ],

            $this->zawiera($komunikat, ['not found', 'please install', 'composer require']) => [
                'Brakuje paczki obsługującej tego dostawcę.',
                'Postmark: `composer require symfony/postmark-mailer`.',
                'Resend: `composer require resend/resend-php`.',
                'Amazon SES: `composer require aws/aws-sdk-php`.',
                'EmailLabs: NIE wymaga żadnej paczki — transport jest w tym repozytorium (`App\Poczta\TransportEmailLabs`).',
                'Po dodaniu paczki trzeba przebudować obraz — sam restart serwisu nie wystarczy.',
            ],

            default => [
                'Nie rozpoznaję tego błędu, więc nie będę zgadywać.',
                'Przeczytaj szczegół techniczny niżej i porównaj go z panelem dostawcy (zakładka z logami wysyłki).',
                'Jeśli błąd znika po restarcie serwisu, przyczyną była zmienna zmieniona bez restartu.',
            ],
        };

        $this->line('<options=bold>Co zrobić</>');

        foreach ($kroki as $numer => $krok) {
            $this->line('  '.($numer + 1).'. '.$krok);
        }

        if ($transport === 'ses' || $transport === 'ses-v2') {
            $this->newLine();
            $this->line('Uwaga do SES: w tym repozytorium zmienne AWS_* należą do Cloudflare R2 (zdjęcia).');
            $this->line('Poczta ma własne MAIL_SES_KEY, MAIL_SES_SECRET i MAIL_SES_REGION — patrz `config/services.php`.');
        }
    }

    private function coDalejPoKolejce(): void
    {
        $this->newLine();
        $this->line('<options=bold>Uwaga</> — to jeszcze nie znaczy, że list wyszedł.');
        $this->line('Zadanie leży w tabeli `jobs` i wyśle je worker. Jeśli worker nie chodzi, nikt go nie ruszy.');
        $this->newLine();
        $this->line('  Na produkcji: serwis `worker` w Railway ma mieć w logu „start queue:work”.');
        $this->line('  Lokalnie:     php artisan queue:work --queue=high,default --once');
        $this->line('  Po chwili:    php artisan queue:failed   (jeśli list nie doszedł, przyczyna jest tam)');
    }

    private function coDalej(int $ostrzezen): void
    {
        $this->newLine();
        $this->line('<options=bold>Co dalej</>');
        $this->line('  1. Zajrzyj do skrzynki — także do folderu „Spam” i do zakładek typu „Oferty”.');
        $this->line('  2. Brak błędu znaczy tylko tyle, że dostawca PRZYJĄŁ wiadomość. O doręczeniu mówi panel dostawcy.');
        $this->line('  3. W nagłówkach doręczonego listu sprawdź trzy słowa: `spf=pass`, `dkim=pass`, `dmarc=pass`.');
        $this->line('     (Gmail: „Pokaż oryginał”. WP i o2: „Więcej” → „Pokaż szczegóły”.)');
        $this->line('  4. Powtórz test na wp.pl, o2.pl, interia.pl i onet.pl. Tam siedzi grupa 50+ i tam filtry są najostrzejsze.');
        $this->line('  5. Na koniec przejdź prawdziwą drogą: załóż konto testowe i użyj „Nie pamiętam hasła”.');

        if ($ostrzezen > 0) {
            $this->newLine();
            $this->warn('Wyżej są uwagi do konfiguracji ('.$ostrzezen.'). Wiadomość poszła MIMO nich, nie dzięki nim.');
        }
    }

    /** @param  list<string>  $igly */
    private function zawiera(string $stog, array $igly): bool
    {
        foreach ($igly as $igla) {
            if (str_contains($stog, $igla)) {
                return true;
            }
        }

        return false;
    }

    private function domenaNadawcy(): string
    {
        $nadawca = mb_strtolower(trim((string) config('mail.from.address')));
        $ogon = strrchr($nadawca, '@');

        return $ogon === false ? $nadawca : substr($ogon, 1);
    }

    /**
     * Symfony traktuje `<coś>` w tekście jak znacznik stylu. Komunikaty
     * serwerów SMTP roją się od adresów w postaci `<basia@example.com>`,
     * więc bez tego kawałek błędu po prostu znika z ekranu — i to akurat
     * ten kawałek, który mówi, o który adres chodziło.
     */
    private function bezZnacznikow(string $tekst): string
    {
        return OutputFormatter::escape($tekst);
    }

    private function czyUstawione(string $wartosc): string
    {
        return $wartosc === '' ? 'BRAK' : 'ustawione';
    }

    private function pusteJakoMyslnik(string $wartosc): string
    {
        return $wartosc === '' ? '— puste —' : $wartosc;
    }
}
