<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * /health — punkt kontrolny dla Railway i monitoringu zewnętrznego.
 *
 * Sprawdza to, czego brak realnie kładzie serwis. Nie sprawdzamy rzeczy,
 * których awaria nie powinna wywalać deployu (np. poczty) — inaczej
 * healthcheck restartuje aplikację z powodu problemu, który nie dotyczy
 * jej działania.
 *
 * DWA POZIOMY, I TO NIE JEST OZDOBNIK
 * `database` i `migrations` są KRYTYCZNE: bez nich nie da się wyświetlić
 * niczego, więc ich awaria oddaje HTTP 503 i Railway ma prawo restartować.
 *
 * `media` NIE JEST krytyczne — i to jest decyzja podjęta świadomie, po tym,
 * jak healthcheck oddający 503 potrafił już położyć ten serwis. Serwis
 * z działającymi wpisami i połamanymi zdjęciami jest o wiele lepszy niż
 * serwis w pętli restartów. Dlatego awaria dysku daje HTTP 200 z polem
 * `status: degraded` — monitoring ma pilnować TREŚCI odpowiedzi, nie tylko
 * kodu HTTP.
 *
 * PO CO W OGÓLE SPRAWDZAĆ ZDJĘCIA
 * Katalog ze zdjęciami stał kiedyś na dysku kontenera, który Railway kasuje
 * przy każdym wdrożeniu. Wszystkie wgrane zdjęcia przepadły, a `/health`
 * meldował „ok", bo baza była cała. Awaria dotyczyła GŁÓWNEJ akcji produktu
 * („zdjęcie + kilka słów") i nie zauważył jej żaden automat — dopiero
 * człowiek, który zobaczył ikony zepsutych obrazków.
 */
class HealthController extends Controller
{
    /**
     * Sprawdzenia, których niepowodzenie oddaje 503 i pozwala Railway
     * restartować kontener. Wszystko poza tą listą tylko raportujemy.
     */
    private const KRYTYCZNE = ['database', 'migrations'];

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static function (): void {
                DB::select('select 1');
            }),
            'migrations' => $this->check(static function (): void {
                $pending = DB::table('migrations')->count();

                if ($pending === 0) {
                    throw new \RuntimeException('Brak wykonanych migracji.');
                }
            }),
            'media' => $this->check(fn () => $this->sprawdzDyskZeZdjeciami()),
        ];

        $krytyczneOk = ! in_array(
            false,
            array_column(array_intersect_key($checks, array_flip(self::KRYTYCZNE)), 'ok'),
            true,
        );

        $wszystkoOk = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $wszystkoOk ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $krytyczneOk ? 200 : 503);
    }

    /**
     * Czy da się zapisać i odczytać plik na dysku ze zdjęciami — i czy droga
     * publiczna do niego prowadzi tam, gdzie powinna.
     *
     * Sam zapis nie wystarcza: dokładnie tak wyglądała poprzednia awaria.
     * `ProcessUploadedImage` kończył się powodzeniem, plik leżał na dysku,
     * a przeglądarka dostawała 404, bo `public/storage` był martwym linkiem
     * albo katalog zniknął razem z kontenerem.
     */
    private function sprawdzDyskZeZdjeciami(): void
    {
        $nazwaDysku = (string) config('kuking.media.disk');
        $dysk = Storage::disk($nazwaDysku);

        // Nazwa z kropką na początku i losowym sufiksem: nie zderzy się
        // z niczyim plikiem i nie trafi do listingów.
        $probka = '.health/'.Str::uuid()->toString();

        $dysk->put($probka, 'kuking');

        try {
            if ($dysk->get($probka) !== 'kuking') {
                throw new \RuntimeException('Zapis się udał, ale odczyt zwrócił co innego.');
            }
        } finally {
            $dysk->delete($probka);
        }

        $this->sprawdzDrogePubliczna($nazwaDysku);
    }

    /**
     * Dla dysku lokalnego droga publiczna to symlink `public/storage`.
     * Przy R2 pliki idą prosto z CDN-u i ten link nie ma znaczenia —
     * sprawdzanie go zgłaszałoby wtedy awarię, której nie ma.
     */
    private function sprawdzDrogePubliczna(string $nazwaDysku): void
    {
        if (config("filesystems.disks.{$nazwaDysku}.driver") !== 'local') {
            return;
        }

        $link = public_path('storage');
        $cel = (string) config("filesystems.disks.{$nazwaDysku}.root");

        if (! is_dir($link)) {
            throw new \RuntimeException(
                "Brak drogi publicznej do zdjęć: {$link} nie prowadzi do katalogu. "
                .'Uruchom `php artisan storage:link`.',
            );
        }

        // `realpath` rozwija symlink. Porównanie celów łapie przypadek,
        // w którym link istnieje, ale wskazuje na poprzedni katalog —
        // np. sprzed zamontowania woluminu.
        if (realpath($link) !== realpath($cel)) {
            throw new \RuntimeException(
                "Droga publiczna do zdjęć prowadzi gdzie indziej niż dysk `{$nazwaDysku}`.",
            );
        }
    }

    /** @return array{ok: bool, error?: string} */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
