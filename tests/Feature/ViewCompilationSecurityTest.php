<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use SplFileInfo;
use Tests\TestCase;

class ViewCompilationSecurityTest extends TestCase
{
    public function test_compiled_blade_views_stay_outside_the_public_web_root(): void
    {
        $normalize = static fn (string $path): string => str_replace('\\', '/', realpath($path) ?: $path);

        $storagePath = $normalize(storage_path());
        $expectedStoragePath = $normalize(base_path('storage'));
        $compiledPath = $normalize((string) config('view.compiled'));
        $expectedPath = $normalize(base_path('storage/framework/views'));
        $publicPath = $normalize(public_path()).'/';

        $this->assertSame($expectedStoragePath, $storagePath);
        $this->assertSame($expectedPath, $compiledPath);
        $this->assertFalse(str_starts_with($compiledPath, $publicPath));

        $this->artisan('view:clear')->assertSuccessful();
        $this->artisan('view:cache')->assertSuccessful();

        $compiledViews = collect(File::allFiles($expectedPath))
            ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php');

        $this->assertGreaterThan(0, $compiledViews->count());

        $publicPhpFiles = collect(File::allFiles(public_path()))
            ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
            ->map(fn (SplFileInfo $file): string => $normalize($file->getPathname()))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$normalize(public_path('index.php'))], $publicPhpFiles);
    }
}
