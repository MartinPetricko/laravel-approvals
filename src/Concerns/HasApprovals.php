<?php

namespace MartinPetricko\LaravelApprovals\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use MartinPetricko\LaravelApprovals\Enums\DraftStatus;
use MartinPetricko\LaravelApprovals\Enums\DraftType;
use MartinPetricko\LaravelApprovals\Facades\LaravelApprovals;
use MartinPetricko\LaravelApprovals\Models\Draft;

trait HasApprovals
{
    protected static bool $approves = true;

    protected static bool $approvesOnCreate = true;

    protected static bool $approvesOnUpdate = true;

    public static function bootHasApprovals(): void
    {
        static::creating(static function (Model $model) {
            /** @var HasApprovals $model */
            if (static::$approves && static::$approvesOnCreate) {
                $model->{$model::getApprovedAtColumn()} = null;
            } else {
                $model->{$model::getApprovedAtColumn()} = now();
            }
        });

        static::created(static function (Model $model) {
            /** @var HasApprovals $model */
            if (static::$approves && static::$approvesOnCreate) {
                $model->createDraft();
            }
        });

        static::updating(static function (Model $model) {
            /** @var HasApprovals $model */
            return !(static::$approves && static::$approvesOnUpdate && $model->createDraft() !== null);
        });

        static::saving(static function (Model $model) {
            /** @var HasApprovals $model */
            return !($model->exists && static::$approves && static::$approvesOnUpdate && $model->createDraft() !== null);
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
        $query->whereDate(static::getApprovedAtColumn(), '>=', now());
    }

    public function scopeUnapproved(Builder $query): void
    {
        $query->whereNull(static::getApprovedAtColumn())->orWhereDate(static::getApprovedAtColumn(), '<', now());
    }

    public function latestDraft(): MorphOne
    {
        return $this->morphOne(LaravelApprovals::getDraftModel(), 'draftable')->latest();
    }

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
                if ($related instanceof Collection) {
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

        if ($this->wasRecentlyCreated) {
            $type = DraftType::Create;
            $newData = $this->getApprovableData($this->refresh()->getRawOriginal());
        } else {
            $type = DraftType::Update;
            $newData = $this->getApprovableData($this->getDirty());

            if ($force === false && $newData === []) {
                return null;
            }
        }

        $oldData = Arr::only($oldData, array_keys($newData));

        if ($this->hasPendingDraft()) {
            $draft = $this->latestDraft->fill([
                'type' => $type,
                'new_data' => array_merge($this->latestDraft->new_data, $newData),
            ]);
        } else {
            $draft = $this->drafts()->make([
                'request_id' => LaravelApprovals::getRequestId(),
                'status' => DraftStatus::Pending,
                'type' => $type,
                'old_data' => $oldData,
                'new_data' => $newData,
            ]);
        }

        $draft->author()->associate(Auth::user());
        $draft->save();

        foreach ($this->getApprovableParentRelations() as $relationName) {
            foreach ($this->{$relationName}()->with('latestDraft')->get() as $related) {
                if ($related->hasPendingDraft()) {
                    continue;
                }
                $related->createDraft(true);
            }
        }

        return $draft;
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

    public static function withoutApproves(callable $callback): void
    {
        $lastState = static::$approves;

        static::disableApproves();

        App::call($callback);

        static::$approves = $lastState;
    }

    public static function getApprovedAtColumn(): string
    {
        return config('approvals.column_names.approved_at', 'approved_at');
    }
}
