<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Cel zgłoszenia dla człowieka (issue #794).
 *
 * Właściciel podjął decyzję 20.09.2026:
 *  - pokazujemy rodzaj i nazwę celu, np. „komentarz Basi pod przepisem «Rosół babci»”,
 *  - ORAZ krótki, obcięty fragment treści w cudzysłowie:
 *      1. fragment do 200 znaków, cięty po granicy słowa, nigdy w połowie wyrazu,
 *      2. obcięcie sygnalizowane wielokropkiem WEWNĄTRZ cudzysłowu: „...…”,
 *      3. treść pusta albo już usunięta → sama nazwa, BEZ cudzysłowu.
 *  - BEZ miniatury i bez awatara — zgłaszana treść bywa drastyczna, powiększanie
 *    jej to koszt bez pożytku.
 *  - BRAMKA #757: treść ukryta przez `body_removed_at` NIE MA PRAWA się tu
 *    pojawić, nawet jeśli wiersz fizycznie istnieje w bazie.
 */
final readonly class CelZgloszenia
{
    public const LIMIT_ZNAKOW = 200;

    public function __construct(
        public string $nazwa,
        public ?string $fragment,
        public ?string $cytat,
    ) {}

    public static function dla(Model $target): self
    {
        $nazwa = self::budujNazwe($target);
        $fragment = self::wyciagnijFragment($target);
        $cytat = self::formatujCytat($fragment);

        return new self(
            nazwa: $nazwa,
            fragment: $fragment,
            cytat: $cytat,
        );
    }

    public static function budujNazwe(Model $target): string
    {
        return match (true) {
            $target instanceof Comment => self::nazwaKomentarza($target),
            $target instanceof Recipe => self::nazwaPrzepisu($target),
            $target instanceof CookedEvent => self::nazwaUgotowania($target),
            $target instanceof User => self::nazwaProfilu($target),
            $target instanceof Post => self::nazwaWpisu($target),
            default => 'treść',
        };
    }

    public static function wyciagnijFragment(Model $target): ?string
    {
        return match (true) {
            $target instanceof Comment => self::fragmentKomentarza($target),
            $target instanceof Post => self::fragmentWpisu($target),
            $target instanceof Recipe => self::fragmentPrzepisu($target),
            $target instanceof CookedEvent => self::fragmentUgotowania($target),
            $target instanceof User => self::fragmentProfilu($target),
            default => null,
        };
    }

    public static function formatujCytat(?string $rawText): ?string
    {
        if ($rawText === null) {
            return null;
        }

        $tekst = trim(str_replace(["\r\n", "\r"], "\n", $rawText));

        if ($tekst === '') {
            return null;
        }

        if (mb_strlen($tekst) <= self::LIMIT_ZNAKOW) {
            return "„{$tekst}”";
        }

        $skrocony = self::utnijPoSlowach($tekst, self::LIMIT_ZNAKOW);

        // Pierwszy wyraz dłuższy niż limit (np. długi link) — nie da się uciąć
        // po granicy słowa. Sam wielokropek w cudzysłowie „…” wyglądałby jak
        // pusty cytat, więc zostaje sama nazwa, jak przy treści pustej.
        if ($skrocony === '') {
            return null;
        }

        return "„{$skrocony}…”";
    }

    public static function utnijPoSlowach(string $tekst, int $limit): string
    {
        $wyrazy = preg_split('/\s+/u', trim(mb_substr($tekst, 0, $limit)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        for ($ile = max(1, count($wyrazy)); $ile >= 1; $ile--) {
            $kandydat = rtrim(Str::words($tekst, $ile, ''));

            if (mb_strlen($kandydat) <= $limit) {
                return $kandydat;
            }
        }

        return '';
    }

    private static function fragmentKomentarza(Comment $comment): ?string
    {
        // BRAMKA #757: treść ukryta przez `body_removed_at` nie może wyciec.
        if ($comment->body_removed_at !== null || $comment->trashed()) {
            return null;
        }

        return $comment->body;
    }

    private static function fragmentWpisu(Post $post): ?string
    {
        if ($post->trashed() || $post->status === Post::STATUS_REMOVED) {
            return null;
        }

        return $post->body;
    }

    private static function fragmentPrzepisu(Recipe $recipe): ?string
    {
        if ($recipe->trashed() || $recipe->status === Recipe::STATUS_REMOVED) {
            return null;
        }

        return $recipe->summary;
    }

    private static function fragmentUgotowania(CookedEvent $event): ?string
    {
        if (method_exists($event, 'trashed') && $event->trashed()) {
            return null;
        }

        return $event->note;
    }

    private static function fragmentProfilu(User $user): ?string
    {
        if ($user->status === User::STATUS_ERASED || $user->status === User::STATUS_BANNED) {
            return null;
        }

        return $user->profile?->bio;
    }

    private static function nazwaKomentarza(Comment $comment): string
    {
        $autorCzlon = '';
        if ($comment->author !== null) {
            $autorDopelniacz = self::dopełniaczImienia($comment->author->displayName());
            if ($autorDopelniacz !== '') {
                $autorCzlon = ' '.$autorDopelniacz;
            }
        }

        $subject = $comment->subject();
        $podCzym = match (true) {
            $subject instanceof Recipe => self::podPrzepisem($subject),
            $subject instanceof Post => self::podWpisem($subject),
            $subject instanceof CookedEvent => self::podUgotowaniem($subject),
            default => '',
        };

        if ($podCzym !== '') {
            return trim("komentarz{$autorCzlon} {$podCzym}");
        }

        return trim("komentarz{$autorCzlon}");
    }

    private static function podPrzepisem(Recipe $recipe): string
    {
        if ($recipe->title !== null && trim($recipe->title) !== '') {
            return "pod przepisem «{$recipe->title}»";
        }

        return 'pod przepisem';
    }

    private static function podWpisem(Post $post): string
    {
        if ($post->title !== null && trim($post->title) !== '') {
            return "pod wpisem «{$post->title}»";
        }

        if ($post->author !== null) {
            $autor = self::dopełniaczImienia($post->author->displayName());
            if ($autor !== '') {
                return "pod wpisem {$autor}";
            }
        }

        return 'pod wpisem';
    }

    private static function podUgotowaniem(CookedEvent $event): string
    {
        if ($event->recipe?->title !== null && trim($event->recipe->title) !== '') {
            return "pod wykonaniem przepisu «{$event->recipe->title}»";
        }

        return 'pod wykonaniem przepisu';
    }

    private static function nazwaPrzepisu(Recipe $recipe): string
    {
        if ($recipe->title !== null && trim($recipe->title) !== '') {
            return "przepis «{$recipe->title}»";
        }

        return 'przepis';
    }

    private static function nazwaUgotowania(CookedEvent $event): string
    {
        if ($event->recipe?->title !== null && trim($event->recipe->title) !== '') {
            return "wykonanie przepisu «{$event->recipe->title}»";
        }

        return 'wykonanie przepisu';
    }

    private static function nazwaProfilu(User $user): string
    {
        $imie = $user->displayName();

        return "profil osoby {$imie}";
    }

    private static function nazwaWpisu(Post $post): string
    {
        if ($post->title !== null && trim($post->title) !== '') {
            return "wpis «{$post->title}»";
        }

        if ($post->author !== null) {
            $autor = self::dopełniaczImienia($post->author->displayName());
            if ($autor !== '') {
                return "wpis {$autor}";
            }
        }

        return 'wpis';
    }

    /**
     * Odmiana imienia lub nazwy użytkownika w dopełniaczu (kogo? czego?).
     * np. „Basia” → „Basi”, „Marek” → „Marka”, „Krzysztof” → „Krzysztofa”.
     */
    public static function dopełniaczImienia(string $tekst): string
    {
        $tekst = trim($tekst);

        if ($tekst === '') {
            return '';
        }

        $czesci = preg_split('/\s+/u', $tekst);
        if (count($czesci) === 2) {
            return self::odmienPojedynczeImie($czesci[0]).' '.self::odmienNazwisko($czesci[1]);
        }

        if (count($czesci) > 2) {
            return $tekst;
        }

        return self::odmienPojedynczeImie($tekst);
    }

    private static function odmienPojedynczeImie(string $imie): string
    {
        // 1a. Końcówka -lia, -wia, -ria: Julia -> Julii, Natalia -> Natalii, Oliwia -> Oliwii, Maria -> Marii
        if (preg_match('/([lwr])ia$/u', $imie)) {
            return (string) preg_replace('/ia$/u', 'ii', $imie);
        }

        // 1b. Końcówka -ia: Basia -> Basi, Kasia -> Kasi, Ania -> Ani, Zosia -> Zosi
        if (preg_match('/ia$/u', $imie)) {
            return (string) preg_replace('/ia$/u', 'i', $imie);
        }

        // 2. Końcówka -ja po samogłosce: Maja -> Mai, Kaja -> Kai
        if (preg_match('/([aeiouy])ja$/u', $imie)) {
            return (string) preg_replace('/ja$/u', 'i', $imie);
        }

        // 3. Końcówka -ja po spółgłosce: Alicja -> Alicji, Julia -> Julii
        if (preg_match('/ja$/u', $imie)) {
            return (string) preg_replace('/ja$/u', 'ji', $imie);
        }

        // 4. Końcówka -ka, -ga: Monika -> Moniki, Kinga -> Kingi, Olga -> Olgi
        if (preg_match('/([kg])a$/u', $imie)) {
            return (string) preg_replace('/([kg])a$/u', '$1i', $imie);
        }

        // 5. Końcówka -la: Kamila -> Kamili, Urszula -> Urszuli, Mariola -> Marioli
        if (preg_match('/la$/u', $imie)) {
            return (string) preg_replace('/la$/u', 'li', $imie);
        }

        // 6. Inne imiona żeńskie na -a: Halina -> Haliny, Danuta -> Danuty, Ewa -> Ewy, Anna -> Anny
        if (preg_match('/a$/u', $imie)) {
            return (string) preg_replace('/a$/u', 'y', $imie);
        }

        // 7. Męskie na -ek: Marek -> Marka, Jacek -> Jacka, Franek -> Franka, Bartek -> Bartka, Tomek -> Tomka
        if (preg_match('/ek$/u', $imie)) {
            return (string) preg_replace('/ek$/u', 'ka', $imie);
        }

        // 8. Męskie na -eł: Paweł -> Pawła
        if (preg_match('/eł$/u', $imie)) {
            return (string) preg_replace('/eł$/u', 'ła', $imie);
        }

        // 9. Męskie na -er: Kacper -> Kacpra
        if (preg_match('/er$/u', $imie)) {
            return (string) preg_replace('/er$/u', 'ra', $imie);
        }

        // 10. Męskie na spółgłoskę: Krzysztof -> Krzysztofa, Tomasz -> Tomasza, Jan -> Jana, Adam -> Adama, Michał -> Michała, Piotr -> Piotra
        if (preg_match('/[bcdfghjklłmnprstwwzżź]$/u', $imie)) {
            return $imie.'a';
        }

        return $imie;
    }

    private static function odmienNazwisko(string $nazwisko): string
    {
        if (preg_match('/ska$/u', $nazwisko)) {
            return (string) preg_replace('/ska$/u', 'skiej', $nazwisko);
        }

        if (preg_match('/ski$/u', $nazwisko)) {
            return (string) preg_replace('/ski$/u', 'skiego', $nazwisko);
        }

        if (preg_match('/cka$/u', $nazwisko)) {
            return (string) preg_replace('/cka$/u', 'ckiej', $nazwisko);
        }

        if (preg_match('/cki$/u', $nazwisko)) {
            return (string) preg_replace('/cki$/u', 'ckiego', $nazwisko);
        }

        if (preg_match('/dzka$/u', $nazwisko)) {
            return (string) preg_replace('/dzka$/u', 'dzkiej', $nazwisko);
        }

        if (preg_match('/dzki$/u', $nazwisko)) {
            return (string) preg_replace('/dzki$/u', 'dzkiego', $nazwisko);
        }

        return self::odmienPojedynczeImie($nazwisko);
    }
}
