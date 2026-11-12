<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\LearnerProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class LearnerProfile extends Model
{
    /** @use HasFactory<LearnerProfileFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    public const STATUS_ASSESSMENT_PENDING = 'assessment_pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'onboarded_at' => 'datetime',
            'last_studied_at' => 'datetime',
            'daily_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targetLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'target_language_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(LanguageVariant::class, 'language_variant_id');
    }

    public function purpose(): BelongsTo
    {
        return $this->belongsTo(LearningPurpose::class, 'learning_purpose_id');
    }

    public function selfReportedLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'self_reported_level_id');
    }

    public function targetLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'target_level_id');
    }

    public function focusSkills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'learner_focus_skills');
    }

    public function scheduleSlots(): HasMany
    {
        return $this->hasMany(StudyScheduleSlot::class)->orderBy('day_of_week');
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(LearningPreference::class);
    }

    public function skillProfiles(): HasMany
    {
        return $this->hasMany(SkillProfile::class);
    }

    public function assessmentAttempts(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class);
    }

    public function plan(): HasOne
    {
        return $this->hasOne(LearningPlan::class)->where('status', '!=', LearningPlan::STATUS_ARCHIVED);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LearningSession::class);
    }
}
