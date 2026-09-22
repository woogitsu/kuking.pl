<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Env;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class DyskTymczasowyLivewireTest extends TestCase
{
    /** Sprawdza konfigurację i resolver pakietu, nie dostępność produkcyjnego R2. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_iac_kieruje_upload_do_oryginalow_niezaleznie_od_dysku_domyslnego(): void
    {
        $source = (string) file_get_contents(base_path('.railway/railway.ts'));
        $this->assertSame(1, preg_match('/const appEnv = \{(.*?)^  \};/ms', $source, $section));
        $this->assertSame(1, preg_match_all(
            '/^\s*LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK:\s*"([^"]+)"/m',
            $section[1],
            $values,
        ), 'Wspólne appEnv musi jawnie wybierać jeden dysk uploadów Livewire.');

        // Odziedziczona zmienna nie może przesłonić badanej wartości z IaC.
        unset($_ENV['LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'], $_SERVER['LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK']);
        putenv('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK');
        $this->assertTrue(Env::getRepository()->set('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK', $values[1][0]));
        $this->assertSame($values[1][0], env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'));
        $this->app['config']->set('livewire', require config_path('livewire.php'));
        $this->app['config']->set('filesystems.default', 'local');
        // Bez tego Livewire zawsze zwraca tmp-for-tests, omijając badaną konfigurację.
        $this->app['env'] = 'production';
        $this->assertFalse($this->app->runningUnitTests());
        $this->assertSame('r2', FileUploadConfiguration::disk());
        $disk = FileUploadConfiguration::diskConfig();
        $this->assertSame('r2', $disk['driver']);
        $this->assertArrayNotHasKey('url', $disk);
        $this->assertFalse(FileUploadConfiguration::isUsingS3());

        // Kontrola dodatnia: usunięcie jawnego wyboru rzeczywiście uruchamia fallback.
        $this->app['config']->set('livewire.temporary_file_upload.disk', null);
        $this->assertSame('local', FileUploadConfiguration::disk());
    }
}
