<?php
namespace kodeops\OpenSeaWrapper;

use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\LaravelPackageTools\Package;

class OpenSeaWrapperServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('opensea-wrapper')
            ->hasMigration('create_opensea_events_table');
    }
}