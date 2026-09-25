<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

/**
 * Mapa: gdzie w bazie leżą dane odnoszące się do konta i co z nimi robi paczka (#953).
 *
 * Paczka deklaruje RODO art. 15, a do września 2026 pomijała po cichu całe
 * kategorie: wcześniejsze wersje przepisów, obserwowane tagi, dziennik zgód,
 * połączone konta Google/Facebooka i aktywne sesje. Nikt ich nie wyłączył
 * decyzją — po prostu nikt ich nie dopisał, gdy powstawały tabele.
 *
 * Dlatego ta lista NIE jest opisem, tylko rejestrem sprawdzanym testem
 * (`EksportObejmujeKazdaTabeleKontaTest`): test czyta ze schematu bazy
 * KAŻDĄ kolumnę wskazującą na `users` (klucze obce plus `sessions.user_id`,
 * który klucza obcego nie ma) i każdą kolumnę samej tabeli `users`, i oblewa,
 * gdy któraś nie ma tu wpisu. Nowa tabela z `user_id` nie wypadnie więc
 * z paczki po cichu — ktoś musi napisać, czy wchodzi, a jeśli nie, dlaczego.
 *
 * Trzy możliwe rozstrzygnięcia, dokładnie te z #953:
 *
 * - `EKSPORT` — trafia do samoobsługowej paczki, do sekcji `dane.json`
 *   nazwanej w drugim polu (test sprawdza, że taka sekcja istnieje);
 * - `NA_ZADANIE` — nie ma jej w paczce z konkretnego powodu (bezpieczeństwo,
 *   prawa innych osób, notatka wewnętrzna), ale wydajemy ją przy ręcznej
 *   obsłudze żądania. Te pozycje paczka wypisuje w `kategorie_poza_paczka`;
 * - `NIE_DOTYCZY` — to nie jest informacja o tej osobie, którą warto
 *   wydawać (poświadczenie, znacznik techniczny). Też z powodem.
 *
 * Poświadczenia (`password`, `remember_token`, `two_factor_*`, skróty
 * tokenów) nie wychodzą NIGDY — ani w paczce, ani na żądanie. Kto ma paczkę,
 * nie może mieć przez to klucza do konta.
 */
final class InwentarzDanychKonta
{
    public const EKSPORT = 'eksport';

    public const NA_ZADANIE = 'na_zadanie';

    public const NIE_DOTYCZY = 'nie_dotyczy';

    private const POSWIADCZENIE = 'Poświadczenie logowania. Nie wydajemy go nikomu, także właścicielowi konta: kto ma paczkę, miałby wtedy klucz do konta.';

    private const PRACA_W_SERWISIE = 'Czynność wykonana w roli moderatora albo prowadzącego serwis. To są sprawy innych osób; na żądanie powiemy, jakie czynności wykonało to konto.';

    /**
     * Każda kolumna wskazująca na konto, jako `tabela.kolumna`.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const KOLUMNY_WSKAZUJACE_NA_KONTO = [
        'profiles.user_id' => [self::EKSPORT, 'profil'],
        'recipes.author_id' => [self::EKSPORT, 'przepisy'],
        'recipe_versions.editor_id' => [self::EKSPORT, 'wersje_przepisow'],
        'posts.author_id' => [self::EKSPORT, 'wpisy'],
        'cooked_events.user_id' => [self::EKSPORT, 'ugotowalem'],
        'comments.author_id' => [self::EKSPORT, 'moje_komentarze'],
        'collections.owner_id' => [self::EKSPORT, 'kolekcje'],
        'follows.follower_id' => [self::EKSPORT, 'obserwuje'],
        'follows.followed_id' => [self::EKSPORT, 'obserwuja_mnie'],
        'blocks.blocker_id' => [self::EKSPORT, 'zablokowane_osoby'],
        'tag_follows.user_id' => [self::EKSPORT, 'obserwowane_tagi'],
        'notifications.user_id' => [self::EKSPORT, 'powiadomienia'],
        'media.owner_id' => [self::EKSPORT, 'zdjecia'],
        'dziennik_zgod.user_id' => [self::EKSPORT, 'dziennik_zgod'],
        'tozsamosci_zewnetrzne.user_id' => [self::EKSPORT, 'polaczone_konta'],
        'sessions.user_id' => [self::EKSPORT, 'aktywne_sesje'],
        'pending_email_changes.user_id' => [self::EKSPORT, 'zmiana_adresu_email'],
        'weekly_digest_sends.user_id' => [self::EKSPORT, 'wyslane_podsumowania_tygodnia'],
        'data_exports.user_id' => [self::EKSPORT, 'zamowione_paczki'],
        'product_signals.user_id' => [self::EKSPORT, 'zdarzenia_w_serwisie'],
        'contact_messages.user_id' => [self::EKSPORT, 'wiadomosci_do_serwisu'],
        'reports.reporter_id' => [self::EKSPORT, 'moje_zgloszenia'],
        'moderation_actions.subject_user_id' => [self::EKSPORT, 'decyzje_moderacji'],
        'appeals.user_id' => [self::EKSPORT, 'odwolania'],

        'blocks.blocked_id' => [self::NA_ZADANIE, 'Kto zablokował to konto. Ujawnienie tego naraziłoby osobę, która się odcięła (RODO art. 15 ust. 4); na żądanie powiemy, ile jest takich blokad.'],
        'notifications.actor_id' => [self::NA_ZADANIE, 'Powiadomienia, które inne osoby dostały o Twoich działaniach. To są ich skrzynki; same działania (wpisy, komentarze, „Ugotowałem”) są w paczce.'],
        'reports.autor_tresci_id' => [self::NA_ZADANIE, 'Zgłoszenia Twoich treści przez inne osoby. Chronimy zgłaszających; decyzje, które wtedy zapadły, są w sekcji decyzje_moderacji.'],
        'audit_log.actor_id' => [self::NA_ZADANIE, 'Dziennik bezpieczeństwa (logowania, zmiany konta, skrót adresu IP). Wydajemy go na żądanie, bo zestawienie w paczce ułatwiałoby przejęcie konta komuś, kto ją zdobędzie.'],
        'mail_failures.user_id' => [self::NA_ZADANIE, 'Techniczny ślad nieudanych wysyłek poczty z komunikatem dostawcy poczty. Wydajemy na żądanie, razem z wyjaśnieniem, co znaczy.'],
        'potwierdzenia_zadan_rodo.konto_id' => [self::NA_ZADANIE, 'Rejestr obsługi Twoich żądań RODO, prowadzony przez nas razem z notatkami o wyjątkach prawnych. Potwierdzenie każdej sprawy wysyłamy osobno.'],
        'appeals.decided_by' => [self::NA_ZADANIE, self::PRACA_W_SERWISIE],
        'contact_messages.handled_by' => [self::NA_ZADANIE, self::PRACA_W_SERWISIE],
        'contact_message_replies.author_id' => [self::NA_ZADANIE, self::PRACA_W_SERWISIE],
        'daily_picks.curator_id' => [self::NA_ZADANIE, self::PRACA_W_SERWISIE],
        'hero_picks.curator_id' => [self::NA_ZADANIE, self::PRACA_W_SERWISIE],
        'moderation_actions.moderator_id' => [self::NA_ZADANIE, self::PRACA_W_SERWISIE],
        'reports.resolved_by' => [self::NA_ZADANIE, self::PRACA_W_SERWISIE],

        'login_link_tokens.user_id' => [self::NIE_DOTYCZY, self::POSWIADCZENIE],
        'first_post_events.author_id' => [self::NIE_DOTYCZY, 'Znacznik techniczny „pierwszy wpis konta”. Nie niesie nic ponad listę wpisów, która jest w paczce.'],
    ];

    /**
     * Każda kolumna tabeli `users`. Sekcja eksportu to zawsze `konto`.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const KOLUMNY_KONTA = [
        'email' => [self::EKSPORT, 'konto'],
        'status' => [self::EKSPORT, 'konto'],
        'role' => [self::EKSPORT, 'konto'],
        'locale' => [self::EKSPORT, 'konto'],
        'text_scale' => [self::EKSPORT, 'konto'],
        'theme' => [self::EKSPORT, 'konto'],
        'wants_weekly_digest' => [self::EKSPORT, 'konto'],
        'weekly_digest_sent_at' => [self::EKSPORT, 'konto'],
        'memories_enabled' => [self::EKSPORT, 'konto'],
        'age_confirmed_at' => [self::EKSPORT, 'konto'],
        'email_verified_at' => [self::EKSPORT, 'konto'],
        'created_at' => [self::EKSPORT, 'konto'],
        'updated_at' => [self::EKSPORT, 'konto'],
        'status_expires_at' => [self::EKSPORT, 'konto'],
        'punishment_status' => [self::EKSPORT, 'konto'],
        'punishment_expires_at' => [self::EKSPORT, 'konto'],
        'delete_requested_at' => [self::EKSPORT, 'konto'],
        'delete_scope' => [self::EKSPORT, 'konto'],
        'data_erased_at' => [self::EKSPORT, 'konto'],
        'two_factor_confirmed_at' => [self::EKSPORT, 'konto'],
        'ostatnio_widziany_at' => [self::EKSPORT, 'konto'],
        'pwa_prompt_state' => [self::EKSPORT, 'konto'],

        'id' => [self::NIE_DOTYCZY, 'Wewnętrzny numer konta. Nie mówi nic o osobie, a paczka świadomie nie podaje identyfikatorów.'],
        'is_seeded' => [self::NIE_DOTYCZY, 'Znacznik kont przykładowych z danych demonstracyjnych; dla prawdziwego konta zawsze „nie”.'],
        'password' => [self::NIE_DOTYCZY, self::POSWIADCZENIE],
        'remember_token' => [self::NIE_DOTYCZY, self::POSWIADCZENIE],
        'two_factor_secret' => [self::NIE_DOTYCZY, self::POSWIADCZENIE],
        'two_factor_backup_codes' => [self::NIE_DOTYCZY, self::POSWIADCZENIE],
        'two_factor_last_used_at' => [self::NIE_DOTYCZY, self::POSWIADCZENIE],
    ];

    /**
     * Kategorie, których nie ma w paczce, ale wydajemy je na żądanie — to,
     * co `dane.json` wypisuje w `kategorie_poza_paczka`. Jeden wpis na powód,
     * żeby człowiek nie czytał siedem razy tego samego zdania.
     *
     * @return list<array{dane: list<string>, dlaczego_nie_w_paczce: string}>
     */
    public static function kategoriePozaPaczka(): array
    {
        $wedlugPowodu = [];

        foreach (self::KOLUMNY_WSKAZUJACE_NA_KONTO as $kolumna => [$tryb, $powod]) {
            if ($tryb === self::NA_ZADANIE) {
                $wedlugPowodu[$powod][] = $kolumna;
            }
        }

        $out = [];

        foreach ($wedlugPowodu as $powod => $kolumny) {
            $out[] = ['dane' => $kolumny, 'dlaczego_nie_w_paczce' => $powod];
        }

        return $out;
    }
}
