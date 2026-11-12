<?php

return [
    'skills' => [
        'grammar' => 'Grammar', 'vocabulary' => 'Vocabulary', 'reading' => 'Reading',
        'listening' => 'Listening', 'speaking' => 'Speaking', 'writing' => 'Writing',
    ],
    'reasons' => [
        'weak_relative_to_profile' => 'it is below your overall level',
        'learner_focus' => 'you asked to focus on it',
        'low_confidence_estimate' => 'we need more evidence to place you accurately',
        'not_yet_assessed' => 'it has not been assessed yet',
        'goal_priority' => 'it matters most for your goal',
        'balanced_maintenance' => 'it keeps your skills balanced',
        'assessment_topic_weakness' => 'your placement showed unexpected errors on this topic',
        'practice_topic_weakness' => 'your recent practice shows this topic needs more work',
        'recurring_mistake' => 'you keep making the same kind of mistake here',
        'topic_due_for_revision' => 'you have not practised this topic for a week and it is not mastered yet',
        'scheduled_revision' => 'spaced revision helps you retain what you practised',
        'assessment_required' => 'your plan needs placement results',
    ],
    'rationale' => [
        'based_on_assessment' => 'Built from your placement results (overall :level:provisional).',
        'provisional' => ', provisional',
        'no_assessment' => 'Built from your onboarding answers — take the placement assessment to personalise it.',
        'time' => ':minutes minutes a day, :days days a week.',
        'top_skill' => ':skill gets :minutes min/week because :reason.',
    ],
    'items' => [
        'placement_assessment' => [
            'title' => 'Take your placement assessment',
            'objective' => 'Answer adaptive questions so we can estimate each of your skills independently.',
        ],
        'conversation' => [
            'title' => 'Conversation: :name',
            'objective' => 'Talk with your AI tutor in :language at :level. Aim for full sentences; your report shows corrections and new words.',
        ],
        'writing_task' => [
            'title' => 'Writing: :name',
            'objective' => 'Write :min–:max words. You will get rubric scores, corrections and an improved version of your text.',
        ],
        'lesson' => ['title' => 'Lesson: :title'],
        'vocabulary_review' => [
            'title' => 'Flashcard review',
            'objective' => 'Review the words that are due today. Rate each card honestly — the schedule adapts to how well you remember.',
        ],
        'grammar_practice' => ['title' => 'Grammar practice (:level)'],
        'vocabulary_building' => ['title' => 'Vocabulary building (:level)'],
        'reading_practice' => ['title' => 'Reading practice (:level)'],
        'listening_practice' => ['title' => 'Listening practice (:level)'],
        'speaking_practice' => ['title' => 'Speaking practice (:level)'],
        'writing_practice' => ['title' => 'Writing practice (:level)'],
        'revision' => [
            'title' => 'Revision: :skill',
            'objective' => 'Revisit what you practised in :skill over the last few days. Re-read your notes, say the key words aloud and try to use three of them in new sentences.',
        ],
        'grammar_topic' => [
            'title' => 'Grammar focus: :topic',
            'objective' => 'Study the rule for ":topic" in :language, write five example sentences of your own and check them carefully against the rule.',
        ],
    ],
    'objectives' => [
        'grammar' => [
            'a' => 'Review one basic :language structure at :level (e.g. present tense or articles) and write six short sentences using it.',
            'b' => 'Pick one :level structure in :language, compare it with your native language and write a short paragraph that uses it at least four times.',
            'c' => 'Analyse an advanced :language structure in an authentic text and rewrite three sentences using alternative constructions.',
        ],
        'vocabulary' => [
            'a' => 'Learn ten everyday :language words from one topic (food, family, travel). Say each aloud and use five of them in sentences.',
            'b' => 'Collect twelve :level words or collocations from something you read or heard in :language and write an example sentence for each.',
            'c' => 'Note precise or idiomatic :language expressions from an authentic source and explain the nuance of each in your own words.',
        ],
        'reading' => [
            'a' => 'Read a short, simple :language text (a sign, menu or short message). Underline words you know and guess the meaning of two new ones.',
            'b' => 'Read a :level article in :language. Write three sentences summarising the main idea and two key details.',
            'c' => 'Read an opinion piece in :language. Identify the author\'s argument, the evidence used and one implied assumption.',
        ],
        'listening' => [
            'a' => 'Listen to a slow :language recording for learners twice. Write down five words you recognise.',
            'b' => 'Listen to a :level podcast or video in :language. Note the main idea and three details, then listen again to check.',
            'c' => 'Listen to authentic :language speech (news, interview). Note the speaker\'s stance and any implied meaning.',
        ],
        'speaking' => [
            'a' => 'Introduce yourself aloud in :language for one minute: name, where you are from and what you like. Repeat it until it feels natural.',
            'b' => 'Talk for two minutes in :language about a recent experience. Record yourself and listen back for one thing to improve.',
            'c' => 'Argue for and against a topical issue in :language for three minutes, using linking expressions to structure your ideas.',
        ],
        'writing' => [
            'a' => 'Write five simple sentences in :language about your daily routine.',
            'b' => 'Write a :level email in :language (about 120 words) inviting a friend or colleague to an event.',
            'c' => 'Write a 250-word opinion paragraph in :language with a clear thesis, supporting points and a conclusion.',
        ],
    ],
    'exercise_types' => [
        'mcq' => 'Practice: :name',
        'cloze' => 'Fill in the blanks: :name',
        'ordering' => 'Word order: :name',
        'reading' => 'Reading: :name',
        'listening' => 'Listening: :name',
        'dictation' => 'Dictation: :name',
    ],
    'content' => [
        'mcq' => 'Choose the right answers, then read the explanation for anything you missed.',
        'cloze' => 'Type the missing words. Check the explanation for every mistake.',
        'ordering' => 'Put the words in the right order to build correct :language sentences.',
        'reading' => 'Read the text at your own pace, then answer the questions. We track your reading speed.',
        'listening' => 'Listen as often as you need (slow it down if it helps), answer, then check the transcript.',
        'dictation' => 'Listen and type exactly what you hear. You are scored on word accuracy.',
    ],
];
