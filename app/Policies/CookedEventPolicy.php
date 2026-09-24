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
        // 1. Blokada — pierwsza, bezwarunkowa, w obie strony (`AGENTS.md` §4).
        if ($user !== null && $user->hasBlockRelationWith($event->user)) {
            return false;
        }

        $jestKucharzem = $user !== null && $user->getKey() === $event->user_id;

        // 2. STATUSU KONTA KUCHARZA TA METODA CELOWO NIE SPRAWDZA.
        //
        // To jedyne miejsce w serwisie, gdzie treść osoby ZBANOWANEJ zostaje
        // dostępna pod bezpośrednim adresem — `RecipePolicy::view()` (audyt A5),
        // `UserPolicy::viewProfile()` i `Notification::widoczneDla()` w tym
        // stanie odmawiają. Wygląda to na przeoczenie, ale nie jest: taką
        // decyzję zapisuje wprost `KomusWyszloWidocznoscTest::
        // test_wykonanie_zbanowanego_kucharza_nie_dostaje_celebracji`, razem
        // z uzasadnieniem („treść zostaje, znika tylko wyróżnienie").
        //
        // Doktryna z tamtego testu jest taka: powierzchnie, na których serwis
        // AKTYWNIE podsuwa treść, zbanowanych wycinają — bezpośredni adres nie.
        // Dlatego granicę ma `celebrate()` niżej i `scopeWidoczneDla()`
        // (galeria „Komu wyszło" pod cudzym przepisem), a `view()` jej nie ma.
        //
        // Zmierzone i ZGŁOSZONE właścicielowi jako pytanie produktowe, nie
        // naprawione tutaj: `cooked.show` wykonania zbanowanej osoby zwraca
        // 200 ze zdjęciem, notatką, nazwą i awatarem, a jej profil — 403.
        // Rozstrzygnięcie, ile z historii zbanowanego konta ma zostać
        // publiczne, jest decyzją produktową i wymaga zmiany w teście, który
        // ją dziś przypina — a nie cichej zmiany polityki obok niego.

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
        // BLOKADA Z AUTOREM PRZEPISU — FURTKA DLA KUCHARZA ZOSTAJE (#1394).
        //
        // Wcześniej blokada z autorem przepisu zamykała tę furtkę także
        // kucharzowi, bo karta wykonania renderowała TYTUŁ i ADRES przepisu,
        // czyli treść osoby, z którą blokada wiąże. Skutek zmierzony: własna
        // zakładka „Ugotowane" dalej pokazywała kartę z przyciskiem
        // „Zobacz i skomentuj", a przycisk i zdjęcie kończyły się 403 —
        // lista i polityka znów odpowiadały inaczej.
        //
        // Granica jest teraz w widoku, nie w wejściu: przy blokadzie karta
        // nie pokazuje tytułu ani adresu przepisu (`components/cooked-card`,
        // `przepisZaBlokada`), tytuł strony też go nie zawiera, a komentarze
        // tnie `Comment::widoczneDla()`. Zdjęcie i notatka należą do
        // kucharza, więc zostają dla niego dostępne — także po to, żeby mógł
        // je skasować.
        //
        // Moderatora ta zmiana nie dotyczy: jego blokada z autorem przepisu
        // dalej zamyka wejście, jak dotąd.
        if ($jestKucharzem) {
            return true;
        }

        if ($user !== null && $user->isModerator()) {
            if ($event->recipe !== null && $user->hasBlockRelationWith($event->recipe->author)) {
                return false;
            }

            return true;
        }

        // 3a. KONTO W KARENCJI USUNIĘCIA — TU DOKTRYNA Z PUNKTU 2 SIĘ KOŃCZY.
        //
        // Punkt 2 wyżej mówi, że ta metoda CELOWO nie patrzy na status
        // kucharza, i dla konta ZBANOWANEGO to jest przemyślana decyzja
        // (D-018/D-022: treść zostaje, znika tylko wyróżnienie). Ale ta sama
        // cisza obejmowała po drodze `pending_delete` — a to jest zupełnie
        // inny przypadek i serwis obiecuje w nim coś przeciwnego.
        //
        // CO OBIECUJEMY CZŁOWIEKOWI, SŁOWO W SŁOWO
        // `resources/views/pages/settings/data.blade.php`: „Konto zniknie ze
        // strony OD RAZU — razem z Twoimi wpisami, przepisami i komentarzami.
        // Przez N dni możesz jeszcze zmienić zdanie". Ban jest karą wymierzoną
        // przez nas; karencja jest decyzją tej osoby, podjętą na podstawie
        // tego zdania.
        //
        // CO BYŁO NAPRAWDĘ (znalezisko G01 z audytu zewnętrznego, zmierzone
        // uruchomieniem, potwierdzone tutaj czytaniem)
        // `CookedEvent::scopeWidoczneDla()` wycina te konta, więc z galerii
        // „Komu wyszło" i z list wykonanie znikało — zgodnie z obietnicą.
        // Ale `cooked.show` pod bezpośrednim adresem oddawał je anonimowo:
        // notatkę, zdjęcie, nazwę. Kto miał stary odnośnik, czytał dalej.
        // Lista i polityka odpowiadały na to samo pytanie inaczej — dokładnie
        // ten sam kształt usterki, który punkt 3 wyżej naprawia w drugą
        // stronę.
        //
        // DLACZEGO TO NIE JEST ZMIANA D-018/D-022. Warunek pyta wprost
        // o JEDEN status, nie o `dostepnyJakoAutor()`. Zbanowany kucharz
        // przechodzi tędy tak samo jak przedtem, `suspended` też —
        // rozstrzygnięcie, ile z historii zbanowanego konta zostaje
        // publiczne, dalej czeka na decyzję produktową i dalej pilnuje go
        // `KomusWyszloWidocznoscTest`.
        //
        // CO Z SAMYM KUCHARZEM — SPRAWDZONE URUCHOMIENIEM, BO ZAŁOŻYŁEM ŹLE.
        //
        // Pierwsza wersja tego komentarza twierdziła, że punkt 3 wyżej
        // przepuszcza kucharza, „bo w karencji musi widzieć, co odzyskuje".
        // To nieprawda i test to pokazał: człowiek w `pending_delete` NIE
        // CHODZI po serwisie. `EnsureAccountIsActive` wylogowuje go przy
        // pierwszym żądaniu i odsyła na stronę logowania z instrukcją, jak
        // cofnąć usunięcie — czyli do tej Policy w ogóle nie dociera.
        //
        // Punkt 3 zostaje więc nietknięty nie dla kucharza, tylko dla
        // MODERATORA, który ma zaglądać z urzędu. A po cofnięciu usunięcia
        // status wraca do `active` i wykonanie wraca dla wszystkich samo,
        // bo nic w danych nie zostało zmienione — i to akurat było prawdą
        // od początku.
        if ($event->user->status === User::STATUS_PENDING_DELETE) {
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
