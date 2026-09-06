<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Plan nazw plików w katalogu `zdjecia/`.
 *
 * Powód istnienia tej klasy: nazwa `9f1c2a34-....webp` nic nie mówi osobie,
 * która rozpakuje paczkę na swoim komputerze. Chcemy `2027-03-14-rosol.webp`,
 * czyli datę i to, co na zdjęciu widać — wtedy paczka jest użyteczna także
 * bez `dane.json` i bez Kuking.
 *
 * Ta sama mapa nazw jest potrzebna w dwóch miejscach (w `dane.json`
 * i w czytelnym HTML-u przepisu), dlatego liczymy ją RAZ i przekazujemy dalej.
 */
final class ExportPhotoPlan
{
    /** @var array<string, string> media_id => nazwa pliku w katalogu zdjecia/ */
    private array $names = [];

    /** @var array<string, Media> media_id => model */
    private array $media = [];

    /** @var array<string, true> zajęte nazwy plików */
    private array $taken = [];

    /** Ile zdjęć nie weszło do paczki, bo w tej chwili jeszcze się przetwarzało. */
    private int $stillProcessing = 0;

    public function __construct(User $user)
    {
        $labels = $this->collectLabels($user);

        // Kolejność po dacie: paczka rozpakowana w Eksploratorze układa się
        // chronologicznie, czyli tak, jak człowiek pamięta swoje gotowanie.
        $photos = $user->media()
            ->where('status', Media::STATUS_READY)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($photos as $photo) {
            $this->media[(string) $photo->getKey()] = $photo;
            $this->names[(string) $photo->getKey()] = $this->buildName(
                $photo,
                $labels[(string) $photo->getKey()] ?? null,
            );
        }

        // Zdjęcia w drodze liczymy TU, a nie w jobie, bo tu stoi warunek
        // `status = ready`, który je odsiewa — dwa miejsca rozjechałyby się
        // przy pierwszej zmianie tego filtra.
        //
        // Tylko `pending` i `processing`: `rejected` też nie ma w paczce, ale
        // to jest inna wiadomość. Zdjęcie odrzucone nie pojawi się w niej NIGDY,
        // więc „poproś o nową paczkę za kilka minut" byłoby po prostu nieprawdą.
        $this->stillProcessing = $user->media()
            ->whereIn('status', [Media::STATUS_PENDING, Media::STATUS_PROCESSING])
            ->count();
    }

    /**
     * Ile zdjęć nie zmieściło się w paczce, bo wciąż się przygotowywały.
     *
     * Paczka, która WYGLĄDA na kompletną, a nie jest, jest gorsza od paczki,
     * która wprost mówi o swoich brakach — RODO art. 15/20 obiecuje dostęp do
     * wszystkich danych, nie do tych, które akurat zdążyły się przetworzyć.
     * `GenerateUserExport` i `ProcessUploadedImage` dzielą tę samą kolejkę,
     * więc eksport dużego konta realnie potrafi wystartować przed nimi
     * (issue #113).
     */
    public function stillProcessingCount(): int
    {
        return $this->stillProcessing;
    }

    /**
     * Wszystkie zdjęcia użytkownika, gotowe do wrzucenia do archiwum.
     *
     * @return Collection<int, Media>
     */
    public function photos(): Collection
    {
        return collect(array_values($this->media));
    }

    /** Nazwa pliku (bez katalogu) albo null, gdy zdjęcia nie ma w paczce. */
    public function nameFor(?string $mediaId): ?string
    {
        if ($mediaId === null) {
            return null;
        }

        return $this->names[$mediaId] ?? null;
    }

    /** Ścieżka względna do zdjęcia, licząc od wskazanego katalogu w paczce. */
    public function pathFor(?string $mediaId, string $prefix = 'zdjecia/'): ?string
    {
        $name = $this->nameFor($mediaId);

        return $name === null ? null : $prefix.$name;
    }

    public function count(): int
    {
        return count($this->names);
    }

    /**
     * Podpisy zdjęć wzięte z miejsc, w których zdjęcie zostało użyte.
     *
     * @return array<string, string>
     */
    private function collectLabels(User $user): array
    {
        $labels = [];

        $avatarId = $user->profile?->avatar_media_id;

        if ($avatarId !== null) {
            $labels[(string) $avatarId] = 'zdjecie-profilowe';
        }

        foreach ($user->recipes()->with('steps')->get() as $recipe) {
            if ($recipe->hero_media_id !== null) {
                $labels[(string) $recipe->hero_media_id] ??= $recipe->title;
            }

            if ($recipe->source_scan_media_id !== null) {
                $labels[(string) $recipe->source_scan_media_id] ??= $recipe->title.' skan';
            }

            foreach ($recipe->steps as $step) {
                if ($step->media_id !== null) {
                    $labels[(string) $step->media_id] ??= $recipe->title.' krok '.($step->position + 1);
                }
            }
        }

        foreach ($user->posts()->with('media')->get() as $post) {
            foreach ($post->media as $photo) {
                $labels[(string) $photo->getKey()] ??= (string) Str::words((string) $post->body, 6, '');
            }
        }

        foreach ($user->cookedEvents()->with(['media', 'recipe'])->get() as $event) {
            foreach ($event->media as $photo) {
                $labels[(string) $photo->getKey()] ??= (string) ($event->recipe?->title ?? 'ugotowane');
            }
        }

        return $labels;
    }

    private function buildName(Media $photo, ?string $label): string
    {
        $slug = Str::limit(Str::slug((string) $label), 60, '');

        if ($slug === '') {
            $slug = 'zdjecie';
        }

        $date = $photo->created_at?->format('Y-m-d') ?? 'bez-daty';
        $extension = $this->extensionFor($photo);

        $candidate = "{$date}-{$slug}.{$extension}";
        $suffix = 2;

        // Dwa zdjęcia tego samego dnia do tego samego przepisu to normalna
        // sytuacja — druga nazwa dostaje „-2”, a nie nadpisuje pierwszej.
        while (isset($this->taken[$candidate])) {
            $candidate = "{$date}-{$slug}-{$suffix}.{$extension}";
            $suffix++;
        }

        $this->taken[$candidate] = true;

        return $candidate;
    }

    private function extensionFor(Media $photo): string
    {
        $fromKey = pathinfo($photo->object_key, PATHINFO_EXTENSION);

        if (is_string($fromKey) && preg_match('/^[a-zA-Z0-9]{2,5}$/', $fromKey) === 1) {
            return Str::lower($fromKey);
        }

        return match ($photo->mime_type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/avif' => 'avif',
            'image/heic', 'image/heif' => 'heic',
            default => 'webp',
        };
    }
}
