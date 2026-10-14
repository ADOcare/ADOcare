<?php

namespace App\Providers;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\PortableVisibilityHandler;
use League\Flysystem\Visibility;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Storage::extend('gcs', function ($app, array $config): FilesystemAdapter {
            $clientConfig = array_filter([
                'projectId' => $config['project_id'] ?? null,
                'keyFilePath' => $config['key_file_path'] ?? null,
                'apiEndpoint' => $config['storage_api_uri'] ?? null,
            ]);

            $storageClient = new StorageClient($clientConfig);
            $bucket = $storageClient->bucket($config['bucket']);
            $visibilityHandler = new PortableVisibilityHandler(
                'allUsers',
                PortableVisibilityHandler::NO_PREDEFINED_VISIBILITY,
                PortableVisibilityHandler::NO_PREDEFINED_VISIBILITY
            );

            $adapter = new GoogleCloudStorageAdapter(
                $bucket,
                $config['path_prefix'] ?? '',
                $visibilityHandler,
                ($config['visibility'] ?? 'private') === 'public' ? Visibility::PUBLIC : Visibility::PRIVATE
            );

            $driver = new Filesystem($adapter);

            return new FilesystemAdapter($driver, $adapter, $config);
        });
    }
}
