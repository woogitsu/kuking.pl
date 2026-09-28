<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Aws\S3\S3Client;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\AwsS3V3Adapter as DyskLaravela;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Arr;
use League\Flysystem\Filesystem as SystemPlikow;

/**
 * Fabryka dysku Laravela dla sterownika `r2` (patrz `R2Adapter`).
 *
 * Odpowiada `FilesystemManager::createS3Driver()` z frameworka, z jedną
 * różnicą: w środku stoi `R2Adapter`, który nie wysyła ACL. Wszystko poza
 * tym zostaje takie samo — ten sam `S3Client`, ta sama klasa dysku
 * (`Illuminate\Filesystem\AwsS3V3Adapter`), a więc te same `url()`,
 * `temporaryUrl()` i to samo zachowanie przy `throw`. Dzięki temu zmiana
 * sterownika w `config/filesystems.php` nie zmienia niczego w kodzie, który
 * z tych dysków korzysta.
 *
 * Kopiowanie tej metody z frameworka jest tu konieczne: `createS3Driver()`
 * i `formatS3Config()` są `protected`, a `Storage::extend()` dostaje samą
 * konfigurację dysku.
 */
final class DyskR2
{
    /**
     * Klucze konfiguracji dysku, których ta fabryka NIE obsługuje.
     *
     * Framework robi z nimi rzeczy, których tu nie powtarzamy (osobne
     * adaptery opakowujące). Żaden dysk R2 w tym repozytorium ich nie używa —
     * a gdyby ktoś je dopisał, ma o tym usłyszeć od razu, nie odkryć po
     * wdrożeniu, że nie działają.
     */
    private const NIEOBSLUGIWANE = ['prefix', 'read-only'];

    /**
     * @param  array<string, mixed>  $konfiguracja  Wpis z `config/filesystems.php`.
     */
    public static function utworz(array $konfiguracja): DyskLaravela
    {
        // Przed `S3Client`: zły adres nie może dostać ani jednego żądania
        // podpisanego naszym kluczem (D-255).
        DozwolonyHostR2::wymus((string) ($konfiguracja['endpoint'] ?? ''));

        foreach (self::NIEOBSLUGIWANE as $klucz) {
            if (! empty($konfiguracja[$klucz])) {
                throw new \InvalidArgumentException(
                    "Sterownik dysku `r2` nie obsługuje klucza `{$klucz}`.",
                );
            }
        }

        $konfiguracjaKlienta = self::konfiguracjaKlienta($konfiguracja);
        $klient = new S3Client($konfiguracjaKlienta);

        $adapter = new R2Adapter(
            $klient,
            (string) $konfiguracjaKlienta['bucket'],
            (string) ($konfiguracjaKlienta['root'] ?? ''),
            (array) ($konfiguracja['options'] ?? []),
            (bool) ($konfiguracjaKlienta['stream_reads'] ?? false),
        );

        return new DyskLaravela(
            new SystemPlikow($adapter, Arr::only($konfiguracja, [
                'directory_visibility',
                'disable_asserts',
                'retain_visibility',
                'temporary_url',
                'url',
                'visibility',
            ])),
            $adapter,
            $konfiguracjaKlienta,
            $klient,
        );
    }

    /**
     * Wbudowany sterownik `s3` (`r2_kopie`, `s3`) za TĄ SAMĄ kontrolą adresu
     * (D-255). Te dyski niosą sekrety R2 — `r2_kopie` nawet bez własnego
     * klucza, bo AWS SDK sięga wtedy po `AWS_ACCESS_KEY_ID` ze środowiska.
     * Poza kontrolą adresu nic się nie zmienia: dysk buduje framework.
     *
     * @param  array<string, mixed>  $konfiguracja
     */
    public static function utworzS3(Application $app, array $konfiguracja): DyskLaravela
    {
        DozwolonyHostR2::wymus((string) ($konfiguracja['endpoint'] ?? ''));

        // `createS3Driver()` jest `protected`; podklasa tylko go odsłania.
        $menedzer = new class($app) extends FilesystemManager
        {
            /** @param  array<string, mixed>  $konfiguracja */
            public function zbudujS3(array $konfiguracja): DyskLaravela
            {
                // Laravel obiecuje w sygnaturze tylko kontrakt `Cloud`,
                // choć buduje `AwsS3V3Adapter` — sprawdzamy to jawnie (#1731).
                $dysk = $this->createS3Driver($konfiguracja);

                if (! $dysk instanceof DyskLaravela) {
                    throw new \LogicException('createS3Driver() nie zbudował AwsS3V3Adapter, tylko '.$dysk::class.'.');
                }

                return $dysk;
            }
        };

        return $menedzer->zbudujS3($konfiguracja);
    }

    /**
     * To samo, co `FilesystemManager::formatS3Config()`.
     *
     * @param  array<string, mixed>  $konfiguracja
     * @return array<string, mixed>
     */
    private static function konfiguracjaKlienta(array $konfiguracja): array
    {
        $konfiguracja += ['version' => 'latest'];

        if (! empty($konfiguracja['key']) && ! empty($konfiguracja['secret'])) {
            $konfiguracja['credentials'] = Arr::only($konfiguracja, ['key', 'secret', 'token']);
        }

        return Arr::except($konfiguracja, ['token']);
    }
}
