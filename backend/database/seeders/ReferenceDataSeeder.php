<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Assessment;
use App\Models\AssessmentSection;
use App\Models\CefrLevel;
use App\Models\Language;
use App\Models\LanguageCapability;
use App\Models\LanguagePair;
use App\Models\LanguageVariant;
use App\Models\LearningPurpose;
use App\Models\LearningPurposeSkillWeight;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Skill;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent reference data. Safe to re-run in production: `php artisan db:seed --class=ReferenceDataSeeder --force`.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->seedCefrLevels();
            $this->seedSkills();
            $this->seedLanguages();
            $this->seedPurposes();
            $this->seedRbac();
            $this->seedPlacementAssessment();
            $this->seedAiProviders();
        });
    }

    private function seedCefrLevels(): void
    {
        $levels = [
            ['PRE_A1', 'Pre-A1', 0, -3.0, 'Can recognise isolated familiar words and very basic phrases.'],
            ['A1', 'A1', 1, -2.0, 'Can understand and use familiar everyday expressions and very basic phrases.'],
            ['A2', 'A2', 2, -1.0, 'Can communicate in simple and routine tasks on familiar topics.'],
            ['B1', 'B1', 3, 0.0, 'Can deal with most situations likely to arise while travelling and describe experiences.'],
            ['B2', 'B2', 4, 1.0, 'Can interact with a degree of fluency and spontaneity and understand complex texts.'],
            ['C1', 'C1', 5, 2.0, 'Can use language flexibly and effectively for social, academic and professional purposes.'],
            ['C2', 'C2', 6, 3.0, 'Can understand virtually everything heard or read and express themselves precisely.'],
        ];
        foreach ($levels as [$code, $label, $ordinal, $anchor, $description]) {
            CefrLevel::updateOrCreate(['code' => $code], [
                'label' => $label, 'ordinal' => $ordinal, 'anchor_theta' => $anchor, 'description' => $description,
            ]);
        }
    }

    private function seedSkills(): void
    {
        $skills = [
            ['listening', 'Listening', false], ['reading', 'Reading', false], ['speaking', 'Speaking', true],
            ['writing', 'Writing', true], ['grammar', 'Grammar', false], ['vocabulary', 'Vocabulary', false],
        ];
        foreach ($skills as $i => [$slug, $name, $productive]) {
            Skill::updateOrCreate(['slug' => $slug], ['name' => $name, 'is_productive' => $productive, 'sort_order' => $i]);
        }
    }

    private function seedLanguages(): void
    {
        $languages = [
            ['en', 'English', 'English', 'ltr', 'Latn', [['en-US', 'American English', true], ['en-GB', 'British English', false]]],
            ['ar', 'Arabic', 'العربية', 'rtl', 'Arab', [['ar', 'Modern Standard Arabic', true], ['ar-EG', 'Egyptian Arabic', false], ['ar-SA', 'Gulf Arabic (Saudi)', false]]],
            ['es', 'Spanish', 'Español', 'ltr', 'Latn', [['es-ES', 'Spain', true], ['es-MX', 'Mexico', false]]],
            ['fr', 'French', 'Français', 'ltr', 'Latn', [['fr-FR', 'France', true], ['fr-CA', 'Canada', false]]],
            ['de', 'German', 'Deutsch', 'ltr', 'Latn', [['de-DE', 'Germany', true], ['de-AT', 'Austria', false]]],
            ['it', 'Italian', 'Italiano', 'ltr', 'Latn', [['it-IT', 'Italy', true]]],
            // Content is authored in simplified characters with pinyin; a Traditional variant needs its own content.
            ['zh', 'Chinese (Mandarin)', '中文', 'ltr', 'Hans', [['zh-CN', 'Mandarin (Simplified)', true]]],
        ];

        // Conservative initial matrix — admins must revalidate against provider docs and internal evaluations.
        $matrix = [
            'content_generation' => ['en' => ['supported', 1], 'es' => ['supported', 1], 'fr' => ['supported', 1], 'de' => ['supported', 1], 'it' => ['supported', 1], 'ar' => ['supported', 2], 'zh' => ['supported', 1]],
            'tts' => ['en' => ['supported', 1], 'es' => ['supported', 1], 'fr' => ['supported', 1], 'de' => ['supported', 1], 'it' => ['supported', 1], 'ar' => ['beta', 2], 'zh' => ['supported', 1]],
            'stt' => ['en' => ['supported', 1], 'es' => ['supported', 1], 'fr' => ['supported', 1], 'de' => ['supported', 1], 'it' => ['supported', 1], 'ar' => ['supported', 2], 'zh' => ['supported', 1]],
            'realtime_voice' => ['en' => ['supported', 1], 'es' => ['supported', 1], 'fr' => ['supported', 1], 'de' => ['supported', 1], 'it' => ['supported', 1], 'ar' => ['beta', 2], 'zh' => ['beta', 2]],
            'pronunciation' => ['en' => ['beta', 2], 'es' => ['beta', 2], 'fr' => ['beta', 2], 'de' => ['beta', 2], 'it' => ['beta', 2], 'ar' => ['unsupported', 3], 'zh' => ['beta', 2]],
            'writing_evaluation' => ['en' => ['supported', 1], 'es' => ['supported', 1], 'fr' => ['supported', 1], 'de' => ['supported', 1], 'it' => ['supported', 1], 'ar' => ['beta', 2], 'zh' => ['supported', 2]],
        ];

        $ids = [];
        foreach ($languages as $i => [$code, $name, $native, $dir, $script, $variants]) {
            $language = Language::withTrashed()->updateOrCreate(['code' => $code], [
                'name' => $name, 'native_name' => $native, 'direction' => $dir, 'script' => $script,
                'is_active' => true, 'is_target_enabled' => true, 'is_interface_enabled' => in_array($code, ['en', 'ar'], true),
                'sort_order' => $i, 'deleted_at' => null,
            ]);
            $ids[$code] = $language->id;

            foreach ($variants as [$vCode, $vName, $default]) {
                LanguageVariant::updateOrCreate(['code' => $vCode], ['language_id' => $language->id, 'name' => $vName, 'is_default' => $default]);
            }
            foreach ($matrix as $feature => $perLanguage) {
                [$status, $tier] = $perLanguage[$code];
                // firstOrCreate: re-seeding must never undo an admin's capability decision.
                LanguageCapability::firstOrCreate(
                    ['language_id' => $language->id, 'feature' => $feature, 'provider_slug' => 'default'],
                    ['status' => $status, 'quality_tier' => $tier],
                );
            }
        }

        foreach ($ids as $source => $sourceId) {
            foreach ($ids as $target => $targetId) {
                if ($source !== $target) {
                    LanguagePair::updateOrCreate(
                        ['source_language_id' => $sourceId, 'target_language_id' => $targetId],
                        ['is_active' => true, 'explanation_quality' => 'supported'],
                    );
                }
            }
        }
    }

    private function seedPurposes(): void
    {
        //                          listening speaking reading writing grammar vocabulary
        $purposes = [
            'general' => ['General communication', 'Everyday conversations and confidence', [1.2, 1.3, 1.0, 0.8, 1.0, 1.1]],
            'career' => ['Career and business', 'Meetings, emails and professional communication', [1.1, 1.3, 1.1, 1.3, 1.0, 1.1]],
            'travel' => ['Travel', 'Getting around, ordering, asking for help', [1.4, 1.5, 0.8, 0.5, 0.7, 1.2]],
            'academic' => ['Academic studies', 'Lectures, academic reading and essays', [1.2, 1.0, 1.4, 1.4, 1.1, 1.2]],
            'immigration' => ['Immigration', 'Daily life, services and integration', [1.4, 1.3, 1.1, 1.0, 0.9, 1.1]],
            'exams' => ['Language examinations', 'Preparing for a recognised language exam', [1.2, 1.2, 1.2, 1.3, 1.2, 1.2]],
            'personal' => ['Personal development', 'Learning for enjoyment and growth', [1.0, 1.0, 1.0, 1.0, 1.0, 1.0]],
        ];
        $skillIds = Skill::pluck('id', 'slug');
        $order = ['listening', 'speaking', 'reading', 'writing', 'grammar', 'vocabulary'];
        $i = 0;
        foreach ($purposes as $slug => [$name, $description, $weights]) {
            $purpose = LearningPurpose::updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description, 'sort_order' => $i++]);
            foreach ($order as $idx => $skill) {
                LearningPurposeSkillWeight::updateOrCreate(
                    ['learning_purpose_id' => $purpose->id, 'skill_id' => $skillIds[$skill]],
                    ['weight' => $weights[$idx]],
                );
            }
        }
    }

    private function seedRbac(): void
    {
        $permissions = [
            'admin.access' => 'Access the admin area',
            'users.manage' => 'Manage users',
            'content.manage' => 'Create and edit learning content',
            'content.review' => 'Review and publish content',
            'languages.manage' => 'Manage languages and capabilities',
            'ai.manage' => 'Manage AI providers, models and prompts',
            'billing.manage' => 'Manage subscriptions and payments',
            'analytics.view' => 'View platform analytics',
            'audit.view' => 'View audit logs',
        ];
        foreach ($permissions as $slug => $name) {
            Permission::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }

        $roles = [
            'student' => ['Student', []],
            'content_editor' => ['Content editor', ['admin.access', 'content.manage', 'content.review']],
            'support' => ['Support', ['admin.access', 'users.manage', 'audit.view']],
            'admin' => ['Administrator', array_keys($permissions)],
            'super_admin' => ['Super administrator', array_keys($permissions)],
        ];
        foreach ($roles as $slug => [$name, $perms]) {
            $role = Role::updateOrCreate(['slug' => $slug], ['name' => $name]);
            $role->permissions()->sync(Permission::whereIn('slug', $perms)->pluck('id'));
        }
    }

    private function seedPlacementAssessment(): void
    {
        $assessment = Assessment::updateOrCreate(
            ['slug' => Assessment::PLACEMENT_SLUG, 'version' => 1],
            ['type' => 'placement', 'name' => 'Placement assessment', 'is_active' => true],
        );
        $skills = Skill::pluck('id', 'slug');
        //           skill        pos min max  SE    availability
        $sections = [
            ['grammar', 1, 5, 8, 0.55, 'enabled'],
            ['vocabulary', 2, 5, 8, 0.55, 'enabled'],
            ['reading', 3, 4, 6, 0.65, 'enabled'],
            ['listening', 4, 4, 6, 0.65, 'enabled'],
            ['writing', 5, 1, 1, 0.70, 'enabled'],
            ['speaking', 6, 1, 1, 0.70, 'enabled'],
        ];
        foreach ($sections as [$skill, $position, $min, $max, $se, $availability]) {
            AssessmentSection::updateOrCreate(
                ['assessment_id' => $assessment->id, 'skill_id' => $skills[$skill]],
                ['position' => $position, 'min_items' => $min, 'max_items' => $max, 'target_standard_error' => $se, 'availability' => $availability],
            );
        }
    }

    private function seedAiProviders(): void
    {
        // Unit costs are left null on purpose: set them from the provider's current price list in the admin area.
        $openai = AiProvider::updateOrCreate(['slug' => 'openai'], ['name' => 'OpenAI', 'driver' => 'openai', 'is_enabled' => true]);
        foreach (config('ai.providers.openai.models') as $operation => $slug) {
            AiModel::updateOrCreate(
                ['ai_provider_id' => $openai->id, 'slug' => $slug, 'operation' => $operation],
                ['is_default' => true, 'is_enabled' => true],
            );
        }
        $fake = AiProvider::updateOrCreate(['slug' => 'fake'], ['name' => 'Deterministic fake (testing only)', 'driver' => 'fake', 'is_enabled' => true]);
        foreach (config('ai.providers.fake.models') as $operation => $slug) {
            AiModel::updateOrCreate(
                ['ai_provider_id' => $fake->id, 'slug' => $slug, 'operation' => $operation],
                ['is_default' => true, 'is_enabled' => true, 'input_cost_per_mtok' => 0, 'output_cost_per_mtok' => 0],
            );
        }
    }
}
