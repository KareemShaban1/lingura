<?php

namespace Database\Seeders;

use App\Models\Achievement;
use Illuminate\Database\Seeder;

/** Original achievement set (titles in en/ar). Idempotent. */
class GamificationSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['first_lesson', 'lessons_completed', 1, 10, 'book-open', 'First lesson', 'Complete your first lesson.', 'الدرس الأول', 'أكمل درسك الأول.'],
            ['lessons_10', 'lessons_completed', 10, 50, 'library', 'Steady learner', 'Complete 10 lessons.', 'متعلّم مثابر', 'أكمل 10 دروس.'],
            ['exercises_25', 'exercises_completed', 25, 30, 'dumbbell', 'Practice habit', 'Finish 25 exercises.', 'عادة التدريب', 'أنهِ 25 تمريناً.'],
            ['exercises_100', 'exercises_completed', 100, 100, 'trophy', 'Practice champion', 'Finish 100 exercises.', 'بطل التدريب', 'أنهِ 100 تمرين.'],
            ['words_50', 'words_learned', 50, 50, 'layers', 'Word collector', 'Get 50 words into long-term review.', 'جامع الكلمات', 'انقل 50 كلمة إلى المراجعة طويلة المدى.'],
            ['words_250', 'words_learned', 250, 150, 'gem', 'Rich vocabulary', 'Get 250 words into long-term review.', 'مفردات غنية', 'انقل 250 كلمة إلى المراجعة طويلة المدى.'],
            ['streak_7', 'streak_days', 7, 50, 'flame', 'One week strong', 'Keep a 7-day streak.', 'أسبوع كامل', 'حافظ على سلسلة 7 أيام.'],
            ['streak_30', 'streak_days', 30, 200, 'flame', 'Unstoppable', 'Keep a 30-day streak.', 'لا يمكن إيقافك', 'حافظ على سلسلة 30 يوماً.'],
            ['first_conversation', 'conversations', 1, 10, 'message-circle', 'First conversation', 'Finish a conversation with your AI tutor.', 'المحادثة الأولى', 'أنهِ محادثة مع معلمك الذكي.'],
            ['conversations_10', 'conversations', 10, 80, 'messages-square', 'Conversationalist', 'Finish 10 tutor conversations.', 'محاور بارع', 'أنهِ 10 محادثات مع المعلم.'],
            ['first_writing', 'writings', 1, 10, 'pen-line', 'First text', 'Get your first text evaluated.', 'النص الأول', 'احصل على تقييم لنصك الأول.'],
            ['placement_done', 'assessments', 1, 20, 'target', 'Know your level', 'Complete a placement assessment.', 'اعرف مستواك', 'أكمل اختبار تحديد المستوى.'],
            ['xp_1000', 'xp_total', 1000, 0, 'star', '1,000 XP', 'Earn 1,000 XP from learning.', '1000 نقطة', 'اجمع 1000 نقطة خبرة من التعلّم.'],
        ];
        foreach ($items as $i => [$slug, $metric, $threshold, $xp, $icon, $enT, $enD, $arT, $arD]) {
            $a = Achievement::updateOrCreate(['slug' => $slug], ['metric' => $metric, 'threshold' => $threshold, 'xp_reward' => $xp, 'icon' => $icon, 'position' => $i]);
            $a->setTranslations('title', ['en' => $enT, 'ar' => $arT]);
            $a->setTranslations('description', ['en' => $enD, 'ar' => $arD]);
        }
    }
}
