<?php

namespace MartinPetricko\LaravelApprovals\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use MartinPetricko\LaravelApprovals\Concerns\HasApprovals;
use MartinPetricko\LaravelApprovals\Enums\DraftStatus;
use MartinPetricko\LaravelApprovals\Enums\DraftType;
use MartinPetricko\LaravelApprovals\Helpers\Diff;

class Draft extends Model
{
    use HasApprovals;

    protected $fillable = [
        'request_id',
        'status',
        'type',
        'old_data',
        'new_data',
        'message',
    ];

    protected $casts = [
        'status' => DraftStatus::class,
        'type' => DraftType::class,
        'old_data' => 'array',
        'new_data' => 'array',
    ];

    protected $with = [
        'author',
        'reviewer',
        'draftable',
    ];

    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewer(): MorphTo
    {
        return $this->morphTo();
    }

    public function draftable(): MorphTo
    {
        return $this->morphTo();
    }

    public function relatedDrafts(): HasMany
    {
        return $this->hasMany(__CLASS__, 'request_id', 'request_id')->whereNot('id', $this->id);
    }

    public function approve(): void
    {
        /** @var Collection<Draft> $drafts */
        $drafts = self::where('request_id', $this->request_id)->with('draftable')->get();

        DB::transaction(static function () use ($drafts) {
            $user = Auth::user();
            foreach ($drafts as $draft) {
                $draft->reviewer()->associate($user);
                $draft->status = DraftStatus::Approved;
                $draft->save();

                $draft->draftable::withoutApproves(static function () use ($draft) {
                    $draft->draftable->setRawAttributes(array_merge($draft->draftable->getRawOriginal(), $draft->new_data));
                    $draft->draftable->{$draft->draftable::getApprovedAtColumn()} = now();
                    $draft->draftable->save();
                });
            }
        });
    }

    public function reject(string $message = null): void
    {
        /** @var Collection<Draft> $drafts */
        $drafts = self::where('request_id', $this->request_id)->get();

        DB::transaction(static function () use ($drafts, $message) {
            $user = Auth::user();
            foreach ($drafts as $draft) {
                $draft->reviewer()->associate($user);
                $draft->status = DraftStatus::Rejected;
                $draft->message = $message;
                $draft->save();
            }
        });
    }

    public function getDiff(): Diff
    {
        return Diff::make($this->old_data, $this->new_data);
    }
}
