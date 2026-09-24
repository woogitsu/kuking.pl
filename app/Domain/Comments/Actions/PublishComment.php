<?php

declare(strict_types=1);

namespace App\Domain\Comments\Actions;

use App\Domain\Comments\LockCommentContext;
use App\Domain\Notifications\Actions\NotifyUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Dodanie komentarza pod wpisem, przepisem albo "Ugotowałem".
 *
 * Świadoma decyzja: odpowiedzi są jednopoziomowe. `parent_id` wskazuje na
 * komentarz główny, a odpowiedź na odpowiedź jest "podnoszona" do tego samego
 * wątku. Głębokie drzewa są nieczytelne przy powiększonym tekście i na
 * telefonie — a to nasi główni użytkownicy.
 */
final class PublishComment
{
    /**
     * Przestrzeń blokad doradczych tej akcji.
     *
     * PostgreSQL ma jedną, globalną przestrzeń blokad doradczych na całą
     * bazę. Pierwszy argument `pg_advisory_xact_lock(int, int)` dzieli ją na
     * części — ta liczba jest nasza i oznacza „wysłanie komentarza". Bez niej
     * hasz treści mógłby trafić w blokadę założoną w zupełnie innej sprawie
     * i dwie niepowiązane operacje czekałyby na siebie bez powodu.
     */
    private const PRZESTRZEN_BLOKAD = 8301;

    /** Powtórne wysłanie komentarza, który w oknie powtórzenia przestał być widoczny (issue #1094). */
    public const NIEWIDOCZNY = 'Ten komentarz jest już zapisany, ale nie jest teraz widoczny w rozmowie. '
        .'Nie trzeba wysyłać go ponownie.';

    public function __construct(private readonly NotifyUser $notify) {}

    public function handle(
        User $author,
        Post|Recipe|CookedEvent $subject,
        string $body,
        ?Comment $parent = null,
        bool $parentRequested = false,
    ): Comment {
        $body = trim($body);

        if ($body === '') {
            throw new BladDlaCzlowieka('Napisz coś, zanim wyślesz komentarz.');
        }

        /*
         * ISSUE #761: RODZIC PODANY, ALE NIE DA SIĘ GO UŻYĆ, TO ODMOWA —
         * NIE CICHA ZAMIANA W KOMENTARZ GŁÓWNY.
         *
         * Kontrolery szukają rodzica przez `whereKey($parentId)->widoczneDla($viewer)`
         * i przekazują `null`, gdy nic nie znajdą — DOKŁADNIE to samo `null`,
         * które oznacza "formularz nowego komentarza, bez rodzica w ogóle".
         * Te dwa przypadki są nierozróżnialne bez dodatkowej informacji, więc
         * odpowiedź wysłana pod zniknięty/ukryty/zablokowany/obcy identyfikator
         * publikowała się po cichu jako nowy komentarz główny: "Komentarz
         * dodany" wychodziło, tyle że tekst trafiał w inne miejsce rozmowy,
         * niż zakładał autor.
         *
         * `$parentRequested` niesie tę utraconą informację — kontroler mówi
         * "w żądaniu był `parent_id`", nie tylko "oto rodzic, jakiego znalazłem".
         * Sprawdzenie stoi TUTAJ, a kolejne granice w LockCommentContext, z tego
         * samego powodu: kontrolerów jest kilka, a czwarty by o tym zapomniał.
         * Ten sam neutralny komunikat co przy blokadzie — nie zdradza, czy
         * powodem jest usunięcie, ukrycie moderacyjne, blokada czy zwykła
         * literówka w adresie.
         */
        if ($parent === null && $parentRequested) {
            throw new BladDlaCzlowieka(LockCommentContext::UNAVAILABLE);
        }

        /*
         * Blokada wobec autora treści, WSKAZANEGO komentarza i KORZENIA
         * płaskiego wątku (audyt W7-06, issue #1049) jest sprawdzana
         * w LockCommentContext — pod zamkami, na świeżo odczytanych
         * wierszach, osobno dla wskazanego komentarza i korzenia
         * (`widoczneDla()` obejmuje status i blokadę w obie strony).
         */
        /*
         * KOMENTARZ I POWIADOMIENIA O NIM POWSTAJĄ RAZEM ALBO WCALE.
         *
         * Ta sama klasa błędu co G04 w `RecordCookedEvent`, tyle że tutaj
         * nie było ŻADNEJ transakcji: `Comment::create` szedł sam, a po nim
         * jedno albo dwa powiadomienia. Wyjątek przy zapisie powiadomienia
         * zostawiał opublikowany komentarz, o którym adresat nie wiedział —
         * a pod odpowiedzią potrafił zostawić komentarz z JEDNYM z dwóch
         * powiadomień. Oba przypadki zmierzone w
         * `AwariaPowiadomieniaNieRozdzielaKomentarzaTest`.
         *
         * Lżejsze niż G04, bo komentarze nie mają `klucz_wyslania`, więc
         * ponowienie dowozi powiadomienie (kosztem duplikatu) zamiast
         * odbijać się w nieskończoność. Naprawiamy mimo to: cena to jedna
         * transakcja, a rozmowa, o której nikt nie wie, jest dokładnie tym,
         * czego ten serwis ma nie robić.
         */
        $comment = app(LockCommentContext::class)->handle($author, $subject, $parent, function (User $author, Post|Recipe|CookedEvent $subject, ?Comment $parent) use ($body): Comment {
            $subjectOwner = $this->ownerOf($subject);
            $parentId = $parent?->parent_id ?? $parent?->getKey();
            /*
             * DWA KLIKNIĘCIA „WYŚLIJ" TO JEDEN KOMENTARZ — BLOKADA W BAZIE,
             * NIE `exists()` W PHP (D-079, audyt podwójnego wysłania
             * z 12 września 2026).
             *
             * Zmierzone przed zmianą: dwa identyczne żądania dawały DWA
             * wiersze w `comments` i DWA powiadomienia u autora treści.
             * Podwójne kliknięcie na wolnym łączu jest w grupie 50+ normą,
             * nie pomyłką — to samo zdanie stoi w
             * `IdempotentnyZapisDoZeszytuTest` od issue #43.
             *
             * DLACZEGO NIE `klucz_wyslania`, JAK PRZY WPISIE I „UGOTOWAŁEM".
             * Bo klucz musi przyjechać z formularza, a formularz komentarza
             * jest JEDEN dla trzech ekranów (`components/comment-thread`)
             * i nie ma miejsca na własne pole bez zmiany tego komponentu.
             * Reguła żyje więc w warstwie domenowej — tam, gdzie i tak
             * kończą wszystkie trzy kontrolery.
             *
             * Zamki w LockCommentContext chronią aktualną dostępność treści.
             * Osobna przestrzeń 8301 nadal pilnuje tożsamości wysłania.
             *
             * SAMA BLOKADA NIE PILNUJE NICZEGO — pilnuje dopiero
             * REWALIDACJA POD NIĄ (D-079 §2). Drugie żądanie czeka, aż
             * pierwsze zatwierdzi transakcję, i dopiero wtedy pyta bazę,
             * czy taki komentarz już jest.
             */
            $this->zablokujToWyslanie($author, $subject, $parentId, $body);

            $juzJest = $this->komentarzZTegoSamegoWyslania($author, $subject, $parentId, $body);

            /*
             * ISSUE #1094: POWTÓRKA KOMENTARZA, KTÓREGO JUŻ NIE WIDAĆ, TO NIE
             * „DODANO".
             *
             * Wyszukanie wyżej celowo NIE filtruje po `status`: komentarz
             * ukryty lub usunięty przez moderację w oknie powtórzenia nadal
             * jest „tym samym wysłaniem". Gdyby filtrował, druga kopia
             * powstałaby obok ukrytej i ominęła decyzję moderacji. Ale bez tego
             * sprawdzenia kontroler dostawał ukryty wiersz i pokazywał
             * „Komentarz dodany.", choć lista (`widoczneDla()`) go nie pokaże.
             *
             * Neutralny komunikat, bez słowa o moderacji i jej powodach — ten
             * sam tekst przy `hidden` i `removed`. Po upływie okna obowiązuje
             * zwykła reguła: to samo zdanie jest nową wypowiedzią i przechodzi
             * tę samą analizę (`PrzeanalizujTresc`) co każdy nowy komentarz.
             */
            if ($juzJest !== null && $juzJest->status !== Comment::STATUS_PUBLISHED) {
                throw new BladDlaCzlowieka(self::NIEWIDOCZNY);
            }

            if ($juzJest !== null) {
                // Ten sam komentarz, jedno powiadomienie. Oddajemy wiersz
                // z pierwszego wysłania — dla kontrolera to ta sama droga
                // co zwykle, tyle że `wasRecentlyCreated` jest fałszem.
                return $juzJest;
            }

            $comment = Comment::create([
                'author_id' => $author->getKey(),
                'post_id' => $subject instanceof Post ? $subject->getKey() : null,
                'recipe_id' => $subject instanceof Recipe ? $subject->getKey() : null,
                'cooked_event_id' => $subject instanceof CookedEvent ? $subject->getKey() : null,
                'parent_id' => $parentId,
                'body' => $body,
                'status' => Comment::STATUS_PUBLISHED,
            ]);

            $this->notify->handle(
                recipient: $subjectOwner,
                type: $parentId === null ? Notification::TYPE_COMMENT : Notification::TYPE_REPLY,
                actor: $author,
                data: [
                    'comment_id' => $comment->getKey(),
                    // BEZ `excerpt` — ISSUE #758, D-229. Wycinek treści liczy
                    // się przy WYŚWIETLANIU, z aktualnego komentarza
                    // (`Notification::zyweWycinkiKomentarzy()`). Kopia
                    // zapisana tutaj byłaby drugim źródłem prawdy i po
                    // poprawce autora cytowałaby zdanie, którego już nie ma.
                    'url' => $this->urlFor($subject),
                    'question_answer' => $subject instanceof Post && $subject->kind === Post::KIND_QUESTION && $parentId === null,
                ],
            );

            // Jeśli odpowiadamy komuś innemu niż autor treści, ta osoba też
            // powinna się dowiedzieć — inaczej rozmowa się nie kleji.
            if ($parent !== null && $parent->author_id !== $subjectOwner->getKey()) {
                $this->notify->handle(
                    recipient: $parent->author,
                    type: Notification::TYPE_REPLY,
                    actor: $author,
                    data: [
                        'comment_id' => $comment->getKey(),
                        // Bez `excerpt` — ten sam powód co wyżej (#758, D-229).
                        'url' => $this->urlFor($subject),
                    ],
                );
            }

            return $comment;
        });

        /*
         * ANALIZA POD KĄTEM SYGNAŁÓW SPAMU (D-052) — PO TRANSAKCJI I W KOLEJCE.
         *
         * PO transakcji, bo zadanie z kolejki `database` bywa podjęte przez
         * workera, zanim wołający zdąży zatwierdzić — a wtedy analiza szukałaby
         * komentarza, którego jeszcze nie widać, i cicho nie robiłaby nic.
         *
         * W kolejce, bo komentarz ma się pojawić od razu. Analiza porównuje
         * tekst z tym, co ta sama osoba napisała w ostatniej godzinie; to jest
         * praca dla workera, nie dla żądania, w którym ktoś czeka na swój
         * komentarz pod cudzym zdjęciem.
         */
        if (! $comment->wasRecentlyCreated) {
            // Drugie kliknięcie: komentarz jest jeden i został już raz
            // przeanalizowany. Druga analiza porównywałaby go sama ze sobą.
            return $comment;
        }

        PrzeanalizujTresc::dlaKomentarza($comment)->afterCommit();

        return $comment;
    }

    /**
     * Blokada na TOŻSAMOŚCI WYSŁANIA — ta sama osoba, ta sama treść, to samo
     * miejsce, ten sam wątek.
     *
     * Trzyma się do końca transakcji (`_xact_`), więc nie da się jej zgubić
     * przez wyjątek ani przez zapomniane zwolnienie. Przestrzeń (pierwszy
     * argument) jest nasza i tylko nasza — dzięki niej hasz treści nie może
     * przypadkiem trafić w blokadę założoną gdzie indziej.
     *
     * Numer przestrzeni stoi w zapytaniu WPROST, a nie jako parametr:
     * PostgreSQL musi rozstrzygnąć, którą wersję `pg_advisory_xact_lock`
     * wołamy, a placeholder bez typu mu tego nie mówi. To stała klasy,
     * nie wartość z żądania, więc nie ma tu czego wstrzyknąć.
     *
     * Na sterowniku innym niż PostgreSQL nie robimy nic: `hashtext`
     * i blokady doradcze są postgresowe, a testy tego repozytorium chodzą
     * na PostgreSQL (AGENTS.md §6). Rewalidacja niżej działa wtedy dalej —
     * słabiej, ale nie fałszywie.
     */
    private function zablokujToWyslanie(User $author, Post|Recipe|CookedEvent $subject, ?string $parentId, string $body): void
    {
        if ($this->oknoSekund() <= 0) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $tozsamosc = implode('|', [
            (string) $author->getKey(),
            $subject::class,
            (string) $subject->getKey(),
            $parentId ?? '-',
            hash('sha256', $body),
        ]);

        DB::selectOne(
            'SELECT pg_advisory_xact_lock('.self::PRZESTRZEN_BLOKAD.', hashtext(?))',
            [$tozsamosc],
        );
    }

    /**
     * Komentarz z TEGO SAMEGO wysłania, jeśli już powstał.
     *
     * Okno czasowe jest tym, co odróżnia podwójne kliknięcie od napisania
     * tego samego zdania ponownie za tydzień — uzasadnienie długości okna
     * stoi przy `kuking.formularze.okno_powtorzenia_komentarza_sekund`.
     */
    private function komentarzZTegoSamegoWyslania(User $author, Post|Recipe|CookedEvent $subject, ?string $parentId, string $body): ?Comment
    {
        $okno = $this->oknoSekund();

        if ($okno <= 0) {
            return null;
        }

        $kolumna = match (true) {
            $subject instanceof Post => 'post_id',
            $subject instanceof Recipe => 'recipe_id',
            $subject instanceof CookedEvent => 'cooked_event_id',
        };

        return Comment::query()
            ->where('author_id', $author->getKey())
            ->where($kolumna, $subject->getKey())
            ->where('body', $body)
            ->where('created_at', '>=', now()->subSeconds($okno))
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'))
            ->when($parentId !== null, fn ($q) => $q->where('parent_id', $parentId))
            ->orderBy('created_at')
            ->first();
    }

    private function oknoSekund(): int
    {
        return (int) config('kuking.formularze.okno_powtorzenia_komentarza_sekund');
    }

    private function ownerOf(Post|Recipe|CookedEvent $subject): User
    {
        return $subject instanceof CookedEvent ? $subject->user : $subject->author;
    }

    private function urlFor(Post|Recipe|CookedEvent $subject): string
    {
        return $subject->url();
    }
}
