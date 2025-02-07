<?php

namespace MartinPetricko\LaravelApprovals;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelApprovalsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-approvals')
            ->hasConfigFile()
            ->hasMigration('create_drafts_table');
    }

    public function packageRegistered(): void
    {
        App::scoped('approvableRequestId', static fn() => Str::uuid()->toString());

        Blueprint::macro('approvals', function (string $approvedAt = null) {
            /** @var Blueprint $this */
            $approvedAt ??= config('approvals.column_names.approved_at', 'approved_at');

            $this->timestamp($approvedAt)->nullable();
        });

        Blueprint::macro('dropApprovals', function (string $approvedAt = null) {
            /** @var Blueprint $this */
            $approvedAt ??= config('approvals.column_names.approved_at', 'approved_at');

            $this->dropColumn($approvedAt);
        });
    }
}
