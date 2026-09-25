<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CookedEvent;
use App\Models\User;

class CookedEventPolicy
{
    /**
     * Kto ma prawo zobaczyć to wykonanie pod adresem `/ugotowane/{id}`.
     *
     * Ta metoda pilnuje też dostępu do ZDJĘĆ wykonania — `DostepDoZdjecia`
     * pyta o `view` na obiekcie nadrzędnym, nie o osobną zdolność.
     */
    public function view(?User $user, CookedEvent $event): bool
    {
        return $this->dostep($user, $event, doKomentarza: false);
    }

    /**
     * Kto może skomentować to wykonanie (`cooked.comment`, `PublishComment`).
     *
     * To samo co `view()` z JEDNYM wyjątkiem: zbanowany kucharz nie zamyka
     * komentowania. Decyzja właściciela z 25.09.2026 do D-261: „Nie,
     * komentarze zostają”. Ban chowa wykonanie przed obcymi (strona, zdjęcia,
     * listy), ale rozmowa pod nim nie jest zamykana. Blokada, status
     * i widoczność przepisu, karencja usunięcia kucharza — obowiązują jak
     * przy `view()`.
     */
    public function comment(User $user, CookedEvent $event): bool
    {
        return $this->dostep($user, $event, doKomentarza: true);
    }

    private function dostep(?User $user, CookedEvent $event, bool $doKomentarza): bool
    {
        // 1. Blokada — pierwsza, bezwarunkowa, w obie strony (`AGENTS.md` §4).
        if ($user !== null && $user->hasBlockRelationWith($event->user)) {
            return false;
        }

        $jestKucharzem = $user !== null && $user->getKey() === $event->user_id;
        $jestKucharzemLubModeratorem = $jestKucharzem || ($user !== null && $user->isModerator());

        // 2. STATUS KONTA KUCHARZA — patrz punkt 3a niżej (D-261).
        //
        // Do audytu A5 (znalezisko A5-07, dawniej B-03) ta metoda celowo NIE
        // patrzyła na status kucharza: wykonanie osoby ZBANOWANEJ zostawało
        // publiczne pod bezpośrednim adresem, choć `RecipePolicy::view()`,
        // `UserPolicy::viewProfile()` i `Notification::widoczneDla()` w tym
        // stanie odmawiają. Gość dostawał 200 z notatką, nazwą i awatarem,
        // a profil tej samej osoby — 403. D-261 rozstrzyga to w wariancie
        // bezpieczniejszym: bezpośredni adres odpowiada tak samo jak lista.

        // 3. WŁASNE WYKONANIE WIDAĆ ZAWSZE, NIEZALEŻNIE OD STANU PRZEPISU.
        //
        // Wcześniej ta furtka istniała tylko dla przepisu SKASOWANEGO
        // (soft delete, audyt A23), a dla przepisu UKRYTEGO przez moderację
        // wykonanie wpadało prosto do `RecipePolicy::view()` — która przy
        // nieopublikowanym przepisie wpuszcza wyłącznie jego autora. Kucharz
        // autorem przepisu nie jest, więc tracił dostęp do WŁASNEGO zdjęcia
        // i własnej notatki. Zmierzone: zakładka „Ugotowane" na własnym
        // profilu dalej wymieniała to wykonanie (`ProfileController`
        // przepuszcza właściciela bez filtra), a kliknięcie dawało 403 —
        // lista i polityka odpowiadały na to samo pytanie inaczej.
        //
        // „Poprawne dane nigdy nie znikają" (AGENTS.md §5). Notatka, czas
        // i zdjęcie należą do kucharza; decyzja moderacyjna wobec CUDZEGO
        // przepisu nie ma prawa mu ich odebrać — choćby dlatego, że musi
        // móc je skasować. Tytuł przepisu, który karta przy okazji pokazuje,
        // ta osoba i tak zna: ugotowała z niego.
        //
        // Moderator z tego samego powodu co wszędzie — ma zaglądać z urzędu.
        //
        // JEDEN WYJĄTEK OD TEJ FURTKI: BLOKADA Z AUTOREM PRZEPISU.
        // Blokada ma pierwszeństwo przed wszystkim innym (AGENTS.md §4), także
        // przed prawem do własnej treści — bo karta wykonania renderuje TYTUŁ
        // i ADRES przepisu (`components/cooked-card`, `showRecipe`), czyli
        // treść osoby, z którą blokada wiąże. Bez tego warunku ta furtka
        // stałaby się obejściem blokady, którego wcześniej nie było: dotąd
        // blokadę wycinała po drodze `RecipePolicy::view()`, a teraz to
        // wywołanie już nie następuje. Dla kucharza wynik jest więc dokładnie
        // taki jak przed tą zmianą — to naprawa stanu przepisu, nie blokady.
        if ($jestKucharzemLubModeratorem) {
            if ($user !== null && $event->recipe !== null && $user->hasBlockRelationWith($event->recipe->author)) {
                return false;
            }

            return true;
        }

        // 3a. KUCHARZ, KTÓREGO TREŚCI SERWIS NIE POKAZUJE (D-261).
        //
        // Ten sam warunek co `CookedEvent::scopeWidoczneDla()` (galeria
        // „Komu wyszło" i listy): `jestDostepnyJakoAutor()`, czyli ani
        // `banned`, ani `pending_delete`. Wcześniej stał tu tylko
        // `pending_delete` (G01) — lista i polityka odpowiadały na to samo
        // pytanie inaczej i bezpośredni adres zbanowanego zostawał otwarty.
        //
        // Dane nie są zmieniane: po zdjęciu bana wykonanie wraca dla
        // wszystkich samo. Kucharz i moderator przeszli już punktem 3 —
        // moderator ma zaglądać z urzędu, a człowiek w `pending_delete`
        // do tej Policy nie dociera (`EnsureAccountIsActive` go wylogowuje).
        //
        // Wyjątek: komentowanie (`comment()`) przy zbanowanym kucharzu zostaje
        // otwarte — decyzja właściciela z 25.09.2026. Karencja usunięcia
        // zamyka także komentowanie.
        $zbanowanyPrzyKomentarzu = $doKomentarza && $event->user->status === User::STATUS_BANNED;

        if (! $event->user->jestDostepnyJakoAutor() && ! $zbanowanyPrzyKomentarzu) {
            return false;
        }

        // 4. Bez przepisu nie ma na czym oprzeć pokazania wykonania OBCYM.
        //    `withTrashed()` rozwiązałoby `TypeError` i jednocześnie
        //    przywróciło widoczność treści, którą autor świadomie usunął —
        //    czyli naprawiłoby wyjątek kosztem prywatności.
        if ($event->recipe === null) {
            return false;
        }

        // 5. Dla wszystkich pozostałych widoczność wykonania idzie za
        //    widocznością przepisu.
        return app(RecipePolicy::class)->view($user, $event->recipe);
    }

    /**
     * Zwykłe usunięcie (`DELETE` ze strony treści) — wyłącznie autor.
     *
     * Issue #932: moderator NIE usuwa tędy cudzej treści, nawet z 2FA.
     * Ta droga omija panel `/admin` (2FA — `moderator.2fa`), uzasadnienie,
     * wiersz w `moderation_actions`, powiadomienie i odwołanie (DSA art. 17
     * i 20). Cudzą treść zdejmuje się decyzją „Usuń" w `/admin/zgloszenia`.
     */
    public function delete(User $user, CookedEvent $event): bool
    {
        return $user->getKey() === $event->user_id;
    }

    /**
     * „Ugotowałem” Z URZĘDU NIE ZDEJMUJE NIKT (G31, D-251) — i to nie jest
     * przeoczenie.
     *
     * `cooked_events` nie ma soft delete: `delete()` kasuje wiersz na stałe,
     * razem z komentarzami pod nim. Od decyzji przysługuje odwołanie, a przy
     * „cofam” powiadomienie obiecuje, że treść wraca od razu
     * (`UzasadnienieDecyzji`). Przy tej tabeli nie ma czego przywrócić, więc
     * decyzja z urzędu byłaby obietnicą bez pokrycia — ten sam powód, dla
     * którego zdjęcie (`media`) nie ma `remove` w `ModerationAction::DOZWOLONE`.
     * Wraca po dodaniu soft delete do `cooked_events` (osobna praca).
     */
    public function removeExOfficio(User $user, CookedEvent $event): bool
    {
        return false;
    }

    /**
     * Ekran „Komuś wyszło" (issue #17) — świętowanie cudzego wykonania.
     *
     * Świadomie WĘŻSZE niż `view()` wyżej, z dwóch powodów:
     *
     * 1. `view()` odpowiada „kto ma prawo zobaczyć ten wpis" (autor wykonania,
     *    moderator, każdy kogo widoczność przepisu wpuszcza). Ten ekran to
     *    inne pytanie: „komu ten produkt ma AKTYWNIE wypychać na wierzch
     *    cudze zdjęcie z gratulacją" — a to ma sens wyłącznie dla osoby, dla
     *    której to wykonanie faktycznie coś znaczy, czyli autora przepisu.
     *    Zwykły widz albo moderator dostają tu 403, nie pustą stronę — mają
     *    zwykły `cooked.show`, tak jak każdy inny wpis.
     *
     * 2. Blokada sprawdzana PIERWSZA i w OBIE strony, dokładnie jak
     *    w `scopeWidoczneDla` (ten sam wzorzec, patrz `CookedEvent::scopeWidoczneDla`).
     *
     * 3. Wykonanie osoby zbanowanej albo czekającej na usunięcie konta NIE
     *    dostaje tego wyróżnienia. To NIE jest to samo co ukrycie treści —
     *    `cooked.show` pod zwykłym adresem działa bez zmian (patrz punkt 2
     *    w `view()` wyżej: ta różnica jest przypięta testem i świadoma).
     *    Różnica jest w tym, że serwis przestaje SAM z siebie podsuwać ten
     *    moment jako powód do radości — tym samym wzorcem, którym
     *    `CookedEvent::scopeWidoczneDla()` przestało podsuwać takie wykonanie
     *    w galerii pod cudzym przepisem, a `Post::scopeTylkoOdAktywnychAutorow`
     *    w treściach polecanych nieznajomym.
     *
     *    Jedno źródło reguły: `User::jestDostepnyJakoAutor()`. Wcześniej stały
     *    tu dwa porównania `status !== …` wypisane wprost, czyli trzecia kopia
     *    listy statusów — a to jest ta klasa błędu, którą ten audyt zamyka.
     */
    public function celebrate(User $user, CookedEvent $event): bool
    {
        if ($event->recipe === null || $user->getKey() !== $event->recipe->author_id) {
            return false;
        }

        if ($user->hasBlockRelationWith($event->user)) {
            return false;
        }

        return $event->user->jestDostepnyJakoAutor();
    }
}
