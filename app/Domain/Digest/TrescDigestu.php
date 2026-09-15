<?php

declare(strict_types=1);

namespace App\Domain\Digest;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;

/**
 * Treść JEDNEGO tygodniowego podsumowania — to, co ma się znaleźć w liście
 * do jednej konkretnej osoby (issue #11, `docs/DECISIONS.md` D-057).
 *
 * DLACZEGO OSOBNY OBIEKT, A NIE TABLICA PRZEKAZANA DO WIDOKU
 * Bo najważniejsza reguła całego mechanizmu — **pustego listu nie wysyłamy**
 * — jest pytaniem o TREŚĆ, nie o odbiorcę i nie o szablon. Gdyby mieszkała
 * w komendzie, drugie miejsce wysyłające list (podgląd, test ręczny,
 * przyszły przycisk „wyślij mi próbkę") musiałoby ją powtórzyć albo, co
 * bardziej prawdopodobne, zapomnieć. Tu jest jedna metoda `jestPusty()`
 * i jedno miejsce, w którym da się ją zepsuć.
 *
 * CO JEST W ŚRODKU I DLACZEGO AKURAT TO
 *
 * 1. **Kto ugotował z Twojego przepisu.** `AGENTS.md` §1 stawia to wyżej niż
 *    jakikolwiek lajk i to jest jedyny powód powrotu z górnej półki
 *    (`docs/product/RETENTION_LOOPS.md` §1: „ktoś zwrócił się do mnie").
 * 2. **Kto zaczął Cię obserwować.** Też osobiste, też o adresacie, a przy
 *    tym jedyna rzecz, którą ma nowa osoba bez ani jednego przepisu.
 * 3. **Co pokazali ludzie, których obserwujesz.** Chronologicznie, bez
 *    żadnego wyboru „najlepszych" — `AGENTS.md` §8 i §12.
 *
 * CZEGO TU CELOWO NIE MA
 * - **Rankingu.** Żadnych „najaktywniejszych", „najpopularniejszych" ani
 *   „top" czegokolwiek (`AGENTS.md` §12). Każda sekcja jest chronologiczna.
 * - **Komentarzy pod Twoimi treściami.** Nie dlatego, że są nieważne — bo
 *   już mają własne, natychmiastowe powiadomienie (`docs/product/
 *   RETENTION_LOOPS.md` §3.1). Powtórzenie ich po tygodniu w liście byłoby
 *   drugą wiadomością o tej samej rzeczy.
 * - **Propozycji nieznajomych („osoby, które warto poznać").** To jest
 *   redakcyjny wybór gospodarza, a nie coś, co wolno złożyć zapytaniem —
 *   każde automatyczne „warto poznać" jest rankingiem pod inną nazwą.
 *
 * @phpstan-type Osoba array{id: string, imie: string}
 * @phpstan-type Wykonanie array{id: string, note: ?string, osoba: ?Osoba, przepis: ?string}
 * @phpstan-type Wpis array{id: string, body: ?string, osoba: ?Osoba, przepis: ?string}
 * @phpstan-type Zapis array{odbiorca: Osoba, wykonania: list<Wykonanie>, nowiObserwujacy: list<Osoba>, ileNowychObserwujacych: int, wpisyObserwowanych: list<Wpis>, pytanieGospodarza: ?string}
 */
final class TrescDigestu
{
    /**
     * @param  list<CookedEvent>  $wykonania  cudze „Ugotowałem" z przepisów adresata
     * @param  list<User>  $nowiObserwujacy  osoby pokazane z imienia (przycięte do limitu)
     * @param  int  $ileNowychObserwujacych  ilu ich było naprawdę — liczba bywa większa niż lista
     * @param  list<Post>  $wpisyObserwowanych  co pokazali obserwowani
     */
    public function __construct(
        public readonly User $odbiorca,
        public readonly array $wykonania,
        public readonly array $nowiObserwujacy,
        public readonly int $ileNowychObserwujacych,
        public readonly array $wpisyObserwowanych,
        public readonly ?string $pytanieGospodarza,
    ) {}

    /**
     * SerializesModels na liście nie zagląda do wnętrza tego DTO (#583).
     * Zapisujemy więc wyłącznie dane używane przez list, nigdy attributes ani
     * relacje modeli. Zostaje snapshot dobranej treści: worker nie odczytuje
     * ponownie kont ani nie dobiera nowych treści (D-057, D-077, D-078).
     *
     * @return Zapis
     */
    public function __serialize(): array
    {
        return [
            'odbiorca' => self::zapisOsoby($this->odbiorca),
            'wykonania' => array_map(static fn (CookedEvent $w): array => [
                'id' => (string) $w->getKey(),
                'note' => $w->note,
                'osoba' => $w->user === null ? null : self::zapisOsoby($w->user),
                'przepis' => $w->recipe?->title,
            ], $this->wykonania),
            'nowiObserwujacy' => array_map(self::zapisOsoby(...), $this->nowiObserwujacy),
            'ileNowychObserwujacych' => $this->ileNowychObserwujacych,
            'wpisyObserwowanych' => array_map(static fn (Post $w): array => [
                'id' => (string) $w->getKey(),
                'body' => $w->body,
                'osoba' => $w->author === null ? null : self::zapisOsoby($w->author),
                'przepis' => $w->recipe?->title,
            ], $this->wpisyObserwowanych),
            'pytanieGospodarza' => $this->pytanieGospodarza,
        ];
    }

    /**
     * Lekkie modele zachowują API szablonów; wszystkie potrzebne relacje są
     * ustawione w pamięci. Nie ma find(), odświeżania ani zapisu do bazy.
     * Stare zadania mogą jeszcze zawierać modele: obsługujemy ich odczyt,
     * ale ponowna serializacja zawsze przechodzi przez listę dozwolonych pól.
     *
     * @param  Zapis|array{odbiorca: User, wykonania: list<CookedEvent>, nowiObserwujacy: list<User>, ileNowychObserwujacych: int, wpisyObserwowanych: list<Post>, pytanieGospodarza: ?string}  $data
     */
    public function __unserialize(array $data): void
    {
        if ($data['odbiorca'] instanceof User) {
            $stary = new self(...$data);
            $data = $stary->__serialize();
        }

        $this->odbiorca = self::odczytajOsobe($data['odbiorca']);
        $this->wykonania = array_map(static function (array $w): CookedEvent {
            $model = new CookedEvent;
            $model->setRawAttributes(['id' => $w['id'], 'note' => $w['note']]);
            $model->setRelation('user', $w['osoba'] === null ? null : self::odczytajOsobe($w['osoba']));
            $model->setRelation('recipe', self::odczytajPrzepis($w['przepis']));

            return $model;
        }, $data['wykonania']);
        $this->nowiObserwujacy = array_map(self::odczytajOsobe(...), $data['nowiObserwujacy']);
        $this->ileNowychObserwujacych = $data['ileNowychObserwujacych'];
        $this->wpisyObserwowanych = array_map(static function (array $w): Post {
            $model = new Post;
            $model->setRawAttributes(['id' => $w['id'], 'body' => $w['body']]);
            $model->setRelation('author', $w['osoba'] === null ? null : self::odczytajOsobe($w['osoba']));
            $model->setRelation('recipe', self::odczytajPrzepis($w['przepis']));

            return $model;
        }, $data['wpisyObserwowanych']);
        $this->pytanieGospodarza = $data['pytanieGospodarza'];
    }

    /** @return Osoba */
    private static function zapisOsoby(User $osoba): array
    {
        return ['id' => (string) $osoba->getKey(), 'imie' => $osoba->displayName()];
    }

    /** @param Osoba $zapis */
    private static function odczytajOsobe(array $zapis): User
    {
        $profil = new Profile;
        $profil->setRawAttributes(['display_name' => $zapis['imie']]);
        $osoba = new User;
        $osoba->setRawAttributes(['id' => $zapis['id']]);
        $osoba->setRelation('profile', $profil);

        return $osoba;
    }

    private static function odczytajPrzepis(?string $tytul): ?Recipe
    {
        if ($tytul === null) {
            return null;
        }

        $przepis = new Recipe;
        $przepis->setRawAttributes(['title' => $tytul]);

        return $przepis;
    }

    public static function pusta(User $odbiorca): self
    {
        return new self($odbiorca, [], [], 0, [], null);
    }

    /**
     * Czy w tym liście NIE MA O CZYM PISAĆ.
     *
     * PYTANIE GOSPODARZA NIE LICZY SIĘ DO TREŚCI i to jest sedno tej metody.
     * Stoi w konfiguracji, więc jest takie samo dla wszystkich i w każdym
     * tygodniu — gdyby wystarczało do wysyłki, serwis co tydzień rozsyłałby
     * pięciuset osobom to samo jedno zdanie i nazywał to podsumowaniem.
     * `docs/product/RETENTION_LOOPS.md` §6 wiersz 5: list, który wygląda jak
     * marketing, kończy się wypisami, a wypisu nie da się cofnąć prośbą.
     */
    public function jestPusty(): bool
    {
        return $this->wykonania === []
            && $this->ileNowychObserwujacych === 0
            && $this->wpisyObserwowanych === [];
    }

    /**
     * Czy wydarzyło się coś, co dotyczy WPROST adresata.
     *
     * Rozstrzyga o temacie listu: „{imię} ugotowała Twój rosół" otwiera się
     * nieporównanie lepiej niż „Co się działo w Kuking" (`docs/brand/
     * COPY_STYLE.md` §6, wiersz „temat digestu").
     */
    public function maCosOsobistego(): bool
    {
        return $this->wykonania !== [] || $this->ileNowychObserwujacych > 0;
    }

    /**
     * Liczby do sygnału produktowego — bez adresu, bez nazw, bez treści.
     *
     * `App\Domain\Analytics\ZapiszSygnal` przyjmuje `properties` z twardym
     * zakazem danych osobowych (AGENTS.md §7), więc kształt tej tablicy jest
     * częścią tamtej umowy, a nie wygodą wywołującego.
     *
     * @return array<string, int>
     */
    public function miary(): array
    {
        return [
            'wykonania' => count($this->wykonania),
            'nowi_obserwujacy' => $this->ileNowychObserwujacych,
            'wpisy' => count($this->wpisyObserwowanych),
        ];
    }
}
