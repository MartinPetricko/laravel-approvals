<?php

namespace MartinPetricko\LaravelApprovals\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use MartinPetricko\LaravelApprovals\Enums\DraftStatus;
use MartinPetricko\LaravelApprovals\Enums\DraftType;
use MartinPetricko\LaravelApprovals\Facades\LaravelApprovals;
use MartinPetricko\LaravelApprovals\Models\Draft;

trait HasApprovals
{
    protected static bool $approves = true;

    public static function bootHasApprovals(): void
    {
        static::creating(static function (Model $model) {
            /** @var HasApprovals|Model $model */
            if ($model->getAttribute($model::getApprovedAtColumn()) !== null) {
                return;
            }

            if (static::$approves && $model->getApprovesOnCreate()) {
                $model->{$model::getApprovedAtColumn()} = null;
            } else {
                $model->{$model::getApprovedAtColumn()} = now();
            }
        });

        static::created(static function (Model $model) {
            /** @var HasApprovals|Model $model */
            if ($model->getAttribute($model::getApprovedAtColumn()) !== null) {
                return;
            }

            if (static::$approves && $model->getApprovesOnCreate()) {
                $model->createDraft();
            }
        });

        static::updating(static function (Model $model) {
            /** @var HasApprovals $model */
            return !(static::$approves && $model->getApprovesOnUpdate() && $model->createDraft() !== null);
        });

        static::saving(static function (Model $model) {
            /** @var HasApprovals $model */
            return !($model->exists && static::$approves && $model->getApprovesOnUpdate() && $model->createDraft() !== null);
        });

        static::deleted(static function (Model $model) {
            /* @var HasApprovals $model */
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                $model->forceRemoveAllDrafts();
            }
        });
    }

    public function initializeHasApprovals(): void
    {
        $this->mergeCasts([
            static::getApprovedAtColumn() => 'datetime',
        ]);
    }

    public function getApprovesOnCreate(): bool
    {
        return property_exists($this, 'approvesOnCreate') ? $this->approvesOnCreate : true;
    }

    public function getApprovesOnUpdate(): bool
    {
        return property_exists($this, 'approvesOnUpdate') ? $this->approvesOnUpdate : true;
    }

    public function getApprovable(): array
    {
        return property_exists($this, 'approvable') ? $this->approvable : [];
    }

    public function getDontApprovable(): array
    {
        return property_exists($this, 'dontApprovable') ? $this->dontApprovable : [];
    }

    public function getApprovableRelations(): array
    {
        return property_exists($this, 'approvableRelations') ? $this->approvableRelations : [];
    }

    public function getApprovableParentRelations(): array
    {
        return property_exists($this, 'approvableParentRelations') ? $this->approvableParentRelations : [];
    }


    public function hasPendingDraft(): bool
    {
        return $this->loadLatestDraft()->latestDraft?->status === DraftStatus::Pending;
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where(static::getApprovedAtColumn(), '<=', now());
    }

    public function scopeUnapproved(Builder $query): void
    {
        $query->whereNull(static::getApprovedAtColumn())->orWhere(static::getApprovedAtColumn(), '>', now());
    }

    /**
     * @return MorphOne<Draft, $this>
     */
    public function latestDraft(): MorphOne
    {
        return $this->morphOne(LaravelApprovals::getDraftModel(), 'draftable')->latest();
    }

    /**
     * @return MorphMany<Draft, $this>
     */
    public function drafts(): MorphMany
    {
        return $this->morphMany(LaravelApprovals::getDraftModel(), 'draftable');
    }

    public function loadDraftData(): static
    {
        if ($this->hasPendingDraft()) {
            $this->setRawAttributes(array_merge($this->getRawOriginal(), $this->latestDraft->new_data));
        }

        $approvableRelations = $this->getApprovableRelations();
        if ($approvableRelations !== []) {
            $relationQueries = [];
            foreach ($approvableRelations as $relationName) {
                $relationQueries[$relationName] = static fn($query) => $query->with('latestDraft');
            }
            $this->load($relationQueries);

            foreach ($approvableRelations as $relationName) {
                $related = $this->getRelation($relationName);
                if ($related instanceof EloquentCollection) {
                    foreach ($related as $relatedModel) {
                        $relatedModel->loadDraftData();
                    }
                } else {
                    $related->loadDraftData();
                }
            }
        }

        return $this;
    }

    /**
     * @return ?Draft
     */
    public function createDraft(bool $force = false): ?Model
    {
        $oldData = $this->getApprovableData($this->getRawOriginal());

        $type = $this->wasRecentlyCreated || $this->{self::getApprovedAtColumn()} === null ? DraftType::Create : DraftType::Update;

        if ($this->wasRecentlyCreated) {
            $newData = $this->getApprovableData($this->refresh()->getRawOriginal());
        } else {
            $newData = $this->getApprovableData($this->getDirty());

            $this->refresh();

            if ($force === false && $newData === []) {
                return null;
            }
        }

        $oldData = Arr::only($oldData, array_keys($newData));

        $parentDrafts = $this->createApprovableParentDrafts();

        if ($this->hasPendingDraft()) {
            $draft = $this->latestDraft->fill([
                'type' => $type,
                'old_data' => array_merge($this->latestDraft->old_data, $oldData),
                'new_data' => array_merge($this->latestDraft->new_data, $newData),
            ]);
        } else {
            $draft = $this->drafts()->make([
                'request_id' => $parentDrafts->first()?->request_id ?: LaravelApprovals::getRequestId(),
                'status' => DraftStatus::Pending,
                'type' => $type,
                'old_data' => $oldData,
                'new_data' => $newData,
            ]);
        }

        $draft->author()->associate(Auth::user());
        $draft->save();

        return $draft;
    }

    protected function createApprovableParentDrafts(): Collection
    {
        $drafts = collect();
        foreach ($this->getApprovableParentRelations() as $relationName) {
            foreach ($this->{$relationName}()->with('latestDraft')->get() as $related) {
                if ($related->hasPendingDraft()) {
                    $drafts->push($related->latestDraft);
                    continue;
                }
                $drafts->push($related->createDraft(true));
            }
        }
        return $drafts;
    }

    public function forceRemoveAllDrafts(): void
    {
        $this->drafts()->forceDelete();
    }

    protected function loadLatestDraft(): static
    {
        if (!$this->relationLoaded('latestDraft')) {
            $this->load('latestDraft');
        }

        return $this;
    }

    protected function getApprovableData(array $data): array
    {
        $approvable = $this->getApprovable();
        $dontApprovable = $this->getDontApprovable();

        if (count($approvable) > 0) {
            return Arr::only($data, $approvable);
        }

        return Arr::except($data, $dontApprovable);
    }

    public static function getApproves(): bool
    {
        return static::$approves;
    }

    public static function enableApproves(): void
    {
        static::$approves = true;
    }

    public static function disableApproves(): void
    {
        static::$approves = false;
    }

    public static function withoutApproves(callable $callback): mixed
    {
        $approves = static::$approves;

        static::disableApproves();

        try {
            return App::call($callback);
        } finally {
            static::$approves = $approves;
        }
    }

    public static function getApprovedAtColumn(): string
    {
        return config('approvals.column_names.approved_at', 'approved_at');
    }
}
