<?php

/*
| Exam preparation frameworks and original practice forms.
| Exam names are used descriptively to say which format the practice follows. All passages, recordings,
| questions and tasks are original LinguaForge material; no official papers are reproduced.
| Item: [cefr level, prompt, [correct option, distractors…], explanation?]
*/

return [
    'disclaimer' => [
        'en' => 'Practice in the format of this exam with original LinguaForge material. Not affiliated with or endorsed by the exam provider. Results are estimates from our learning model, not official scores.',
        'ar' => 'تدريب بصيغة هذا الاختبار بمواد أصلية من LinguaForge. لسنا تابعين للجهة المنظِّمة للاختبار ولا معتمَدين منها. النتائج تقديرات من نموذج التعلّم لدينا وليست درجات رسمية.',
    ],

    'frameworks' => [
        'ielts_academic' => [
            'language' => 'en', 'exam_name' => 'IELTS Academic', 'scale' => 'band', 'target' => null, 'prior' => 'B2', 'overall_rule' => 'mean_half_band',
            'title' => ['en' => 'IELTS Academic practice', 'ar' => 'تدريب على IELTS الأكاديمي'],
            'description' => ['en' => 'Listening, reading, writing and speaking in the IELTS Academic format, with an estimated band score for each section.', 'ar' => 'استماع وقراءة وكتابة وتحدّث بصيغة IELTS الأكاديمي، مع درجة تقديرية لكل قسم.'],
            'sections' => [['listening', 900], ['reading', 1200], ['writing', 2400], ['speaking', 300]],
            // Indicative alignment of our ability scale with IELTS bands via the CEFR (B1 ≈ 4–5, B2 ≈ 5.5–6.5, C1 ≈ 7–8, C2 ≈ 8.5+).
            'conversions' => [[-4, 1.0], [-3, 1.5], [-2, 2.5], [-1, 3.5], [-0.5, 4.0], [0, 4.5], [0.5, 5.5], [1, 6.0], [1.5, 7.0], [2, 7.5], [2.5, 8.5], [3, 8.75], [4, 9.0]],
            'form' => ['key' => 'ielts-academic-practice-1', 'groups' => [
                ['section' => 'listening', 'key' => 'l1', 'text' => "Hello, accommodation office, how can I help? — Hi, I'm starting a master's course in September and I'd like a room in a shared flat. — Certainly. Our shared flats have five bedrooms and one kitchen. The rent is one hundred and forty pounds a week, and that includes electricity and internet, but not laundry. — Is there anything near the science park? — Yes, Riverside Court is a ten-minute walk from the science park. The next viewing is on Thursday at four o'clock.", 'items' => [
                    ['B1', 'How many bedrooms does each shared flat have?', ['five', 'four', 'six', 'three']],
                    ['B1', 'What is NOT included in the weekly rent?', ['laundry', 'electricity', 'internet', 'use of the kitchen'], 'Electricity and internet are included; laundry is not.'],
                    ['B2', 'When is the next viewing of Riverside Court?', ['Thursday at 4 p.m.', 'Tuesday at 4 p.m.', 'Thursday at 10 a.m.', 'Friday at 4 p.m.']],
                ]],
                ['section' => 'listening', 'key' => 'l2', 'text' => "Today I want to look at why cities are often several degrees warmer than the surrounding countryside — the so-called urban heat island. The main cause is not, as many people assume, heat from cars and air conditioners. That contributes, but far more important is the way materials like asphalt and concrete absorb sunlight during the day and release it slowly at night. That's why the difference between city and countryside is usually greatest after sunset. Interestingly, the most cost-effective response in many studies has not been planting trees, which take years to mature, but simply making roofs reflective.", 'items' => [
                    ['B2', 'According to the lecturer, what is the main cause of urban heat islands?', ['materials that absorb heat and release it slowly', 'heat from cars and air conditioners', 'a lack of trees in city centres', 'higher population density']],
                    ['C1', 'When is the temperature difference usually greatest?', ['after sunset', 'at midday', 'in the early morning', 'in winter']],
                    ['C1', 'Which measure did many studies find most cost-effective?', ['reflective roofs', 'planting trees', 'reducing traffic', 'building parks'], 'Trees take years to mature; reflective roofs work immediately.'],
                ]],
                ['section' => 'reading', 'key' => 'r1', 'text' => "The return of the night train\n\nFor decades, overnight trains in Europe seemed destined to disappear, unable to compete with low-cost airlines on price or speed. Since 2016, however, several operators have reopened sleeper routes, encouraged by travellers who want to reduce their carbon footprint. The economics remain difficult: a sleeper carriage carries far fewer passengers than a day train, yet it occupies the track for many hours. Operators argue that by travelling overnight, passengers save the cost of a hotel, which makes higher fares acceptable. Whether this demand will last once the novelty fades is still uncertain.", 'items' => [
                    ['B1', 'Why did night trains decline?', ['They could not compete with cheap flights.', 'They were too slow for business travellers.', 'Passengers preferred driving.', 'Track maintenance was too expensive.']],
                    ['B2', 'What makes sleeper services expensive to run?', ['They carry few passengers but use the track for a long time.', 'Staff must work at night.', 'Sleeper carriages are expensive to build.', 'They must stop at many stations.']],
                    ['C1', "What is the writer's view of future demand?", ['It is uncertain whether it will continue.', 'It will certainly grow.', 'It has already started to fall.', 'It depends mainly on ticket prices.'], '"Whether this demand will last … is still uncertain."'],
                ]],
                ['section' => 'reading', 'key' => 'r2', 'text' => "Measuring happiness\n\nEconomists have traditionally used income as a proxy for wellbeing, on the assumption that people with more money can satisfy more of their preferences. Survey research complicates this picture. Within a country, richer people do report higher life satisfaction, but as a nation grows wealthier over time, average satisfaction often rises far less than income would predict. One explanation is that people judge their circumstances relative to others: when everyone's income increases, relative position barely changes. Critics reply that self-reported satisfaction is shaped by cultural norms about how positively one should describe one's life, so comparisons between countries must be made with caution.", 'items' => [
                    ['B2', 'Why have economists used income to measure wellbeing?', ['Money lets people satisfy more of their preferences.', 'Income is easy to report in surveys.', 'Happiness cannot be measured at all.', 'Richer countries always have happier people.']],
                    ['C1', 'What puzzle does the survey research reveal?', ['National satisfaction rises less than national income.', 'Richer people report lower satisfaction.', 'Satisfaction falls when income rises.', 'Poorer countries report the highest satisfaction.']],
                    ['C2', 'What do the critics warn about?', ['Cultural norms affect how people report satisfaction.', 'Income data are unreliable.', 'Relative position explains everything.', 'Surveys ask the wrong people.']],
                ]],
            ], 'tasks' => [
                'writing' => ['key' => 'en-ielts-essay', 'type' => 'essay', 'level' => 'B2', 'min' => 250, 'max' => 400, 'title' => ['en' => 'Essay: the purpose of university', 'ar' => 'مقال: الغاية من الجامعة'],
                    'prompt' => 'Some people believe that universities should focus on preparing students for employment, while others think their main purpose is to develop knowledge for its own sake. Discuss both views and give your own opinion. Write at least 250 words.'],
                'speaking' => ['key' => 'en-ielts-longturn', 'type' => 'monologue', 'level' => 'B2', 'min' => 120, 'max' => 300, 'title' => ['en' => 'Long turn: a skill you learned', 'ar' => 'حديث مطوّل: مهارة تعلّمتها'],
                    'prompt' => 'Describe a skill you learned outside school. You should say what the skill is, how you learned it and how often you use it, and explain why it is important to you. Speak for one to two minutes.'],
            ]],
        ],

        'dele_a2' => [
            'language' => 'es', 'exam_name' => 'DELE A2', 'scale' => 'readiness', 'target' => 'A2', 'prior' => 'A2', 'overall_rule' => 'all_sections',
            'title' => ['en' => 'DELE A2 practice (Spanish)', 'ar' => 'تدريب على DELE A2 (الإسبانية)'],
            'description' => ['en' => 'Reading, listening, writing and speaking in the DELE A2 format, with an estimated readiness for each part.', 'ar' => 'قراءة واستماع وكتابة وتحدّث بصيغة DELE A2، مع تقدير لجاهزيتك في كل جزء.'],
            'sections' => [['reading', 1200], ['listening', 900], ['writing', 1800], ['speaking', 300]],
            'form' => ['key' => 'dele-a2-practice-1', 'groups' => [
                ['section' => 'reading', 'key' => 'r1', 'text' => 'Se alquila piso en el centro de Valencia. Tiene dos dormitorios, un baño y una cocina grande. Está en el tercer piso y no hay ascensor. El precio es de 750 euros al mes, con agua incluida. Se aceptan mascotas pequeñas. Llamar por las tardes al 612 345 678.', 'items' => [
                    ['A1', '¿Cuántos dormitorios tiene el piso?', ['Dos', 'Uno', 'Tres', 'Cuatro']],
                    ['A2', '¿Qué está incluido en el precio?', ['El agua', 'La luz', 'Internet', 'El gas']],
                    ['A2', '¿Cuándo hay que llamar?', ['Por la tarde', 'Por la mañana', 'Por la noche', 'Los fines de semana']],
                ]],
                ['section' => 'reading', 'key' => 'r2', 'text' => "Hola, Carmen:\nTe escribo desde Sevilla. El curso de cocina está siendo genial, aunque el primer día llegué tarde porque me perdí en el barrio de Triana. Por las mañanas aprendemos recetas tradicionales y por las tardes visitamos mercados. El viernes vamos a preparar una cena para todo el grupo y estoy un poco nerviosa porque me toca hacer el postre. ¡Ya te contaré!\nUn beso,\nLucía", 'items' => [
                    ['A2', '¿Por qué llegó tarde Lucía el primer día?', ['Se perdió.', 'Se durmió.', 'Perdió el autobús.', 'Estaba enferma.']],
                    ['A2', '¿Qué hacen por las tardes?', ['Visitan mercados.', 'Aprenden recetas.', 'Preparan cenas.', 'Van a la playa.']],
                    ['B1', '¿Cómo se siente Lucía por la cena del viernes?', ['Un poco nerviosa', 'Muy tranquila', 'Aburrida', 'Enfadada']],
                ]],
                ['section' => 'listening', 'key' => 'l1', 'text' => 'Atención, señores pasajeros: el tren con destino a Madrid, que tenía salida a las diez y cuarto, saldrá con veinte minutos de retraso desde la vía cuatro. Disculpen las molestias.', 'items' => [
                    ['A2', '¿Adónde va el tren?', ['A Madrid', 'A Valencia', 'A Sevilla', 'A Barcelona']],
                    ['A2', '¿A qué hora sale ahora el tren?', ['A las diez y treinta y cinco', 'A las diez y cuarto', 'A las diez y veinte', 'A las once menos cuarto'], 'Salía a las 10:15 y tiene veinte minutos de retraso.'],
                    ['A1', '¿De qué vía sale?', ['De la vía cuatro', 'De la vía dos', 'De la vía catorce', 'De la vía uno']],
                ]],
                ['section' => 'listening', 'key' => 'l2', 'text' => '—¿Qué tal el fin de semana, Marcos? —Muy bien. El sábado fui con mis primos a la montaña. Queríamos subir hasta el lago, pero empezó a llover y volvimos antes. —¡Qué pena! ¿Y el domingo? —El domingo me quedé en casa y vi una película con mi hermana.', 'items' => [
                    ['A2', '¿Con quién fue Marcos a la montaña?', ['Con sus primos', 'Con su hermana', 'Con sus amigos', 'Solo']],
                    ['B1', '¿Por qué volvieron antes?', ['Empezó a llover.', 'Estaban cansados.', 'Se perdieron.', 'Hacía mucho calor.']],
                    ['A2', '¿Qué hizo el domingo?', ['Vio una película en casa.', 'Fue al lago.', 'Visitó a sus primos.', 'Trabajó.']],
                ]],
            ], 'tasks' => [
                'writing' => ['key' => 'es-dele-a2-email', 'type' => 'email', 'level' => 'A2', 'min' => 70, 'max' => 100, 'title' => ['en' => 'Email: your new home', 'ar' => 'رسالة: بيتك الجديد'],
                    'prompt' => 'Has cambiado de casa. Escribe un correo a un amigo (entre 70 y 90 palabras): cuéntale cómo es tu nueva casa, qué te gusta del barrio e invítale a visitarte.'],
                'speaking' => ['key' => 'es-dele-a2-monologue', 'type' => 'monologue', 'level' => 'A2', 'min' => 60, 'max' => 200, 'title' => ['en' => 'Monologue: your last trip', 'ar' => 'حديث: رحلتك الأخيرة'],
                    'prompt' => 'Habla durante uno o dos minutos sobre tu último viaje: adónde fuiste, con quién, qué hiciste y qué te gustó más.'],
            ]],
        ],

        'delf_a2' => [
            'language' => 'fr', 'exam_name' => 'DELF A2', 'scale' => 'readiness', 'target' => 'A2', 'prior' => 'A2', 'overall_rule' => 'all_sections',
            'title' => ['en' => 'DELF A2 practice (French)', 'ar' => 'تدريب على DELF A2 (الفرنسية)'],
            'description' => ['en' => 'Listening, reading, writing and speaking in the DELF A2 format, with an estimated readiness for each part.', 'ar' => 'استماع وقراءة وكتابة وتحدّث بصيغة DELF A2، مع تقدير لجاهزيتك في كل جزء.'],
            'sections' => [['listening', 900], ['reading', 1200], ['writing', 1800], ['speaking', 300]],
            'form' => ['key' => 'delf-a2-practice-1', 'groups' => [
                ['section' => 'listening', 'key' => 'l1', 'text' => "Bonjour, ici le cabinet du docteur Martin. Votre rendez-vous de jeudi est déplacé à vendredi, même heure, c'est-à-dire neuf heures et demie. N'oubliez pas votre carte vitale. Si cet horaire ne vous convient pas, rappelez-nous au 04 72 15 30 11.", 'items' => [
                    ['A2', 'Quand est le nouveau rendez-vous ?', ['Vendredi à 9 h 30', 'Jeudi à 9 h 30', 'Vendredi à 10 h 30', 'Jeudi à 10 h']],
                    ['A2', "Qu'est-ce qu'il ne faut pas oublier ?", ['La carte vitale', 'Une ordonnance', "De l'argent", 'Une photo']],
                    ['A1', 'Qui appelle ?', ["Le cabinet d'un médecin", 'Une pharmacie', 'Une banque', 'Une école']],
                ]],
                ['section' => 'listening', 'key' => 'l2', 'text' => "— Alors, tu as trouvé un appartement ? — Oui, enfin ! Il est un peu plus petit que l'ancien, mais il est juste à côté de mon travail, donc je n'ai plus besoin de prendre la voiture. Et le loyer est moins cher. — Et les voisins ? — Ils ont l'air sympathiques, mais celui du dessus joue du piano tous les soirs !", 'items' => [
                    ['A2', "Comment est le nouvel appartement par rapport à l'ancien ?", ['Plus petit', 'Plus grand', 'Plus cher', 'Plus loin du travail']],
                    ['B1', "Quel est l'avantage principal ?", ['Il est près du travail.', 'Il a un piano.', 'Il a un parking.', 'Il est très calme.']],
                    ['B1', 'Quel est le problème ?', ['Un voisin joue du piano le soir.', 'Le loyer est trop cher.', "Il n'y a pas de transports.", 'Les voisins sont désagréables.']],
                ]],
                ['section' => 'reading', 'key' => 'r1', 'text' => "Médiathèque municipale — Horaires d'été\nDu 1er juillet au 31 août, la médiathèque est ouverte du mardi au samedi, de 10 h à 18 h. Elle est fermée le dimanche et le lundi. Les cartes de lecteur sont gratuites pour les moins de 18 ans. Atelier de bande dessinée pour les enfants le mercredi à 14 h, sur inscription.", 'items' => [
                    ['A1', 'Quel jour la médiathèque est-elle fermée ?', ['Le lundi', 'Le mardi', 'Le samedi', 'Le mercredi']],
                    ['A2', 'Pour qui la carte est-elle gratuite ?', ['Pour les moins de 18 ans', 'Pour tout le monde', 'Pour les étudiants', 'Pour les plus de 65 ans']],
                    ['A2', "Que faut-il faire pour l'atelier de bande dessinée ?", ["S'inscrire", 'Payer 18 euros', 'Venir le samedi', 'Apporter un livre']],
                ]],
                ['section' => 'reading', 'key' => 'r2', 'text' => "Salut Hugo,\nMerci pour ton invitation ! Malheureusement, je ne pourrai pas venir samedi soir : mes parents fêtent leurs trente ans de mariage et toute la famille se retrouve chez ma grand-mère, à Lyon. Je rentre dimanche en fin d'après-midi. On pourrait se voir lundi pour aller au cinéma, si tu veux ?\nBises,\nInès", 'items' => [
                    ['A2', 'Pourquoi Inès ne peut-elle pas venir samedi ?', ['Elle a une fête de famille.', 'Elle travaille.', 'Elle est malade.', 'Elle part en vacances.']],
                    ['A2', 'Où sera la famille ?', ['Chez la grand-mère', 'Chez Hugo', 'Au cinéma', 'Chez ses parents']],
                    ['B1', 'Que propose Inès ?', ["D'aller au cinéma lundi", 'De venir dimanche', 'De fêter samedi', "D'aller à Lyon ensemble"]],
                ]],
            ], 'tasks' => [
                'writing' => ['key' => 'fr-delf-a2-message', 'type' => 'email', 'level' => 'A2', 'min' => 60, 'max' => 100, 'title' => ['en' => 'Message: thank your friends', 'ar' => 'رسالة: اشكر أصدقاءك'],
                    'prompt' => 'Vous avez passé un week-end chez des amis. Écrivez-leur un message (60 à 80 mots) pour les remercier et raconter ce que vous avez aimé.'],
                'speaking' => ['key' => 'fr-delf-a2-monologue', 'type' => 'monologue', 'level' => 'A2', 'min' => 60, 'max' => 200, 'title' => ['en' => 'Monologue: your town', 'ar' => 'حديث: مدينتك'],
                    'prompt' => "Parlez de votre ville ou de votre quartier pendant une à deux minutes : où vous habitez, ce qu'on peut y faire et ce que vous aimez ou n'aimez pas."],
            ]],
        ],

        'goethe_a2' => [
            'language' => 'de', 'exam_name' => 'Goethe-Zertifikat A2', 'scale' => 'readiness', 'target' => 'A2', 'prior' => 'A2', 'overall_rule' => 'all_sections',
            'title' => ['en' => 'Goethe-Zertifikat A2 practice (German)', 'ar' => 'تدريب على شهادة Goethe A2 (الألمانية)'],
            'description' => ['en' => 'Reading, listening, writing and speaking in the Goethe-Zertifikat A2 format, with an estimated readiness for each module.', 'ar' => 'قراءة واستماع وكتابة وتحدّث بصيغة شهادة Goethe A2، مع تقدير لجاهزيتك في كل وحدة.'],
            'sections' => [['reading', 1200], ['listening', 900], ['writing', 1200], ['speaking', 300]],
            'form' => ['key' => 'goethe-a2-practice-1', 'groups' => [
                ['section' => 'reading', 'key' => 'r1', 'text' => "Sprachschule Lindner – Neue Kurse ab 3. März\nDeutsch A2: Montag und Mittwoch, 18 bis 20 Uhr. Der Kurs dauert zehn Wochen und kostet 280 Euro. Bücher sind nicht im Preis enthalten. Anmeldung bis zum 25. Februar im Büro oder online.", 'items' => [
                    ['A1', 'An welchen Tagen ist der Kurs?', ['Montag und Mittwoch', 'Dienstag und Donnerstag', 'Montag und Freitag', 'Am Wochenende']],
                    ['A2', 'Was ist nicht im Preis enthalten?', ['Die Bücher', 'Der Unterricht', 'Die Prüfung', 'Das Büro']],
                    ['A2', 'Bis wann muss man sich anmelden?', ['Bis zum 25. Februar', 'Bis zum 3. März', 'Bis zum 18. Februar', 'Bis zum 10. März']],
                ]],
                ['section' => 'reading', 'key' => 'r2', 'text' => "Liebe Anna,\nseit zwei Wochen wohne ich jetzt in Hamburg. Die Wohnung ist schön, aber mein Arbeitsweg ist lang: Ich fahre jeden Tag eine Stunde mit der S-Bahn. Am Wochenende war ich zum ersten Mal am Hafen – das Wetter war leider kalt und windig, aber die Fischbrötchen waren super! Nächsten Monat besucht mich meine Schwester. Kommst du vielleicht auch?\nViele Grüße\nJonas", 'items' => [
                    ['A2', 'Wie lange wohnt Jonas schon in Hamburg?', ['Seit zwei Wochen', 'Seit einem Monat', 'Seit einem Jahr', 'Seit zwei Tagen']],
                    ['A2', 'Wie fährt Jonas zur Arbeit?', ['Mit der S-Bahn', 'Mit dem Auto', 'Mit dem Fahrrad', 'Zu Fuß']],
                    ['B1', 'Wie war das Wetter am Hafen?', ['Kalt und windig', 'Warm und sonnig', 'Es hat geschneit.', 'Heiß']],
                ]],
                ['section' => 'listening', 'key' => 'l1', 'text' => 'Liebe Fahrgäste, wegen Bauarbeiten fährt die U-Bahn-Linie 3 heute nicht zwischen Hauptbahnhof und Marktplatz. Bitte nehmen Sie den Ersatzbus vor dem Haupteingang. Die Busse fahren alle zehn Minuten.', 'items' => [
                    ['A2', 'Warum fährt die U-Bahn nicht?', ['Wegen Bauarbeiten', 'Wegen eines Unfalls', 'Wegen eines Streiks', 'Wegen des Wetters']],
                    ['A2', 'Wo fährt der Ersatzbus ab?', ['Vor dem Haupteingang', 'Am Marktplatz', 'An der Linie 3', 'Hinter dem Bahnhof']],
                    ['A1', 'Wie oft fahren die Busse?', ['Alle zehn Minuten', 'Alle drei Minuten', 'Jede Stunde', 'Alle zwanzig Minuten']],
                ]],
                ['section' => 'listening', 'key' => 'l2', 'text' => '— Hallo Petra, hast du Lust, am Samstag mit ins Schwimmbad zu kommen? — Samstag geht leider nicht, da muss ich meiner Mutter beim Umzug helfen. Aber am Sonntagnachmittag hätte ich Zeit. — Sonntag ist das Schwimmbad geschlossen. Wollen wir stattdessen ins Café gehen? — Gute Idee, um drei?', 'items' => [
                    ['A2', 'Was macht Petra am Samstag?', ['Sie hilft ihrer Mutter beim Umzug.', 'Sie geht schwimmen.', 'Sie arbeitet.', 'Sie besucht ein Café.']],
                    ['B1', 'Warum gehen sie am Sonntag nicht ins Schwimmbad?', ['Es ist geschlossen.', 'Es ist zu teuer.', 'Petra hat keine Zeit.', 'Das Wetter ist schlecht.']],
                    ['A2', 'Wann treffen sie sich?', ['Am Sonntag um drei', 'Am Samstag um drei', 'Am Sonntag um zwei', 'Am Samstagabend']],
                ]],
            ], 'tasks' => [
                'writing' => ['key' => 'de-goethe-a2-email', 'type' => 'email', 'level' => 'A2', 'min' => 30, 'max' => 60, 'title' => ['en' => 'Email: decline an invitation', 'ar' => 'رسالة: الاعتذار عن دعوة'],
                    'prompt' => 'Ein Freund hat Sie zu seinem Geburtstag eingeladen, aber Sie können nicht kommen. Schreiben Sie eine E-Mail (etwa 40 Wörter): Entschuldigen Sie sich, nennen Sie einen Grund und machen Sie einen Vorschlag für ein anderes Treffen.'],
                'speaking' => ['key' => 'de-goethe-a2-monologue', 'type' => 'monologue', 'level' => 'A2', 'min' => 50, 'max' => 200, 'title' => ['en' => 'Monologue: your daily life', 'ar' => 'حديث: يومك'],
                    'prompt' => 'Erzählen Sie ein bis zwei Minuten über Ihren Alltag: Wann stehen Sie auf, was machen Sie tagsüber und was machen Sie am Abend?'],
            ]],
        ],

        'hsk_1' => [
            'language' => 'zh', 'exam_name' => 'HSK 1', 'scale' => 'readiness', 'target' => 'A1', 'prior' => 'A1', 'overall_rule' => 'all_sections',
            'title' => ['en' => 'HSK 1 practice (Chinese)', 'ar' => 'تدريب على HSK 1 (الصينية)'],
            'description' => ['en' => 'Listening and reading in the HSK level 1 format, with an estimated readiness for each part.', 'ar' => 'استماع وقراءة بصيغة المستوى الأول من HSK، مع تقدير لجاهزيتك في كل جزء.'],
            'sections' => [['listening', 900], ['reading', 1020]],
            'form' => ['key' => 'hsk-1-practice-1', 'groups' => [
                ['section' => 'listening', 'key' => 'l1', 'text' => '你好！我叫李月。我是老师。我在北京工作。', 'items' => [
                    ['PRE_A1', '她叫什么名字？', ['李月', '李明', '王月', '王明']],
                    ['A1', '她做什么工作？', ['老师', '医生', '学生', '司机']],
                    ['A1', '她在哪儿工作？', ['北京', '上海', '学校', '医院']],
                ]],
                ['section' => 'listening', 'key' => 'l2', 'text' => '男：你想喝什么？女：我想喝茶。男：你要几杯？女：两杯，谢谢。', 'items' => [
                    ['A1', '女的想喝什么？', ['茶', '水', '咖啡', '牛奶']],
                    ['A1', '她要几杯？', ['两杯', '一杯', '三杯', '四杯']],
                    ['PRE_A1', '女的最后说了什么？', ['谢谢', '再见', '对不起', '没关系']],
                ]],
                ['section' => 'reading', 'key' => 'r1', 'text' => '今天是星期六。我和朋友去商店买东西。我们买了很多水果。', 'items' => [
                    ['A1', '今天星期几？', ['星期六', '星期一', '星期天', '星期五']],
                    ['A1', '他们去哪儿？', ['商店', '学校', '医院', '饭店']],
                    ['A1', '他们买了什么？', ['水果', '书', '茶', '米饭']],
                ]],
                ['section' => 'reading', 'key' => 'r2', 'text' => '我家有三口人：爸爸、妈妈和我。爸爸在医院工作，他是医生。妈妈是老师。我是学生，我喜欢看书。', 'items' => [
                    ['A1', '他家有几口人？', ['三口', '两口', '四口', '五口']],
                    ['A1', '爸爸做什么工作？', ['医生', '老师', '学生', '司机']],
                    ['A2', '“我”喜欢做什么？', ['看书', '喝茶', '买东西', '工作']],
                ]],
            ], 'tasks' => []],
        ],
    ],
];
