<?php

/*
| Second original practice form for each exam framework (see exams.php for the format and rules).
| New attempts rotate to the form a learner has taken least, so a retake meets new material.
*/

return [
    'ielts_academic' => [['key' => 'ielts-academic-practice-2', 'groups' => [
        ['section' => 'listening', 'key' => 'l1', 'text' => "Good morning, City Sports Centre. — Hello, I'd like to join the beginners' swimming course for adults. — Of course. The course runs for eight weeks, on Monday and Wednesday evenings from seven to eight. It costs ninety-six pounds, or sixty-four if you're already a member of the centre. — I'm not a member. Do I need to bring anything? — Just a swimming cap; we provide the floats. The first class is on the fourteenth of March.", 'items' => [
            ['B1', 'How long does the swimming course last?', ['eight weeks', 'six weeks', 'ten weeks', 'four weeks']],
            ['B1', 'How much will the caller pay?', ['£96', '£64', '£86', '£69'], 'The caller is not a member, so the full price applies.'],
            ['B2', 'What must participants bring?', ['a swimming cap', 'floats', 'goggles', 'a membership card']],
        ]],
        ['section' => 'listening', 'key' => 'l2', 'text' => "In today's seminar we're looking at why people find it so hard to stick to new habits. A popular claim is that a habit takes twenty-one days to form. In fact, the study most often quoted found an average closer to two months, with enormous variation between individuals — from a few weeks to most of a year. Missing a single day made little difference to the final outcome. What did matter was consistency of context: people who performed the behaviour at the same time and in the same place formed the habit far faster than those who relied on motivation alone.", 'items' => [
            ['B2', 'What does the speaker say about the claim that habits take 21 days?', ['Research suggests it usually takes longer.', 'It is supported by most studies.', 'It applies only to simple habits.', 'It was never tested.']],
            ['C1', 'What effect did missing one day have?', ['Very little effect on the outcome', 'People had to start again', 'It doubled the time needed', 'It made the habit stronger']],
            ['C1', 'Which factor helped people form habits fastest?', ['Doing the behaviour in a consistent time and place', 'Strong personal motivation', 'Never missing a day', 'Rewarding themselves afterwards'], 'Consistency of context mattered more than motivation.'],
        ]],
        ['section' => 'reading', 'key' => 'r1', 'text' => "Vertical farming\n\nVertical farms grow crops indoors in stacked layers, under artificial light and with carefully controlled water and nutrients. Supporters point out that they use a fraction of the water of conventional agriculture, need no pesticides and can be built close to the cities they supply, cutting transport. Their great weakness is energy: replacing sunlight with electric lighting is expensive, and several well-funded companies have gone bankrupt as electricity prices rose. For now, the approach works commercially mainly for leafy greens and herbs, which grow quickly and sell at a high price per kilogram.", 'items' => [
            ['B1', 'Which advantage of vertical farms is mentioned?', ['They use much less water.', 'They need no electricity.', 'They grow every type of crop.', 'They are cheap to build.']],
            ['B2', 'Why have some vertical-farming companies failed?', ['The cost of electricity rose.', 'Customers did not trust the produce.', 'Pesticides were banned.', 'They were too far from cities.']],
            ['C1', 'Why are leafy greens suitable for vertical farms?', ['They grow fast and sell at a high price.', 'They need very little light.', 'They cannot be grown outdoors.', 'They are easy to transport.']],
        ]],
        ['section' => 'reading', 'key' => 'r2', 'text' => "The value of boredom\n\nBoredom has long been treated as a problem to be eliminated, and smartphones now make it possible to avoid it almost entirely. Some psychologists argue this may carry a hidden cost. In one series of experiments, participants who first completed a deliberately tedious task went on to produce more original ideas in a creativity test than those who had not been bored. The researchers suggest that an under-stimulated mind begins to wander, and that mind-wandering allows distant ideas to connect. They are careful, however, not to claim that chronic boredom is beneficial: prolonged boredom is associated with poorer mood and risky behaviour.", 'items' => [
            ['B2', 'What has made it easier to avoid boredom?', ['Smartphones', 'Shorter working hours', 'Psychological research', 'Creativity tests']],
            ['C1', 'What did the experiments find?', ['Bored participants later produced more original ideas.', 'Bored participants performed worse on all tasks.', 'Tedious tasks improved memory.', 'Creativity fell after using a phone.']],
            ['C2', 'What caution do the researchers express?', ['Long-lasting boredom is not shown to be good.', 'The results apply only to children.', 'Mind-wandering reduces creativity.', 'The experiments could not be repeated.']],
        ]],
    ], 'tasks' => [
        'writing' => ['key' => 'en-ielts-essay-2', 'type' => 'essay', 'level' => 'B2', 'min' => 250, 'max' => 400, 'title' => ['en' => 'Essay: working from home', 'ar' => 'مقال: العمل من المنزل'],
            'prompt' => 'More and more people now work from home for at least part of the week. Do the advantages of this trend outweigh the disadvantages? Give reasons for your answer and include relevant examples from your own knowledge or experience. Write at least 250 words.'],
        'speaking' => ['key' => 'en-ielts-longturn-2', 'type' => 'monologue', 'level' => 'B2', 'min' => 120, 'max' => 300, 'title' => ['en' => 'Long turn: a place you would like to visit', 'ar' => 'حديث مطوّل: مكان تودّ زيارته'],
            'prompt' => 'Describe a place you would like to visit in the future. You should say where it is, how you learned about it and what you would do there, and explain why you would like to go there. Speak for one to two minutes.'],
    ]]],

    'dele_a2' => [['key' => 'dele-a2-practice-2', 'groups' => [
        ['section' => 'reading', 'key' => 'r1', 'text' => 'Gimnasio Vital – Oferta de septiembre. Inscríbete antes del 15 de septiembre y no pagas la matrícula. Abrimos de lunes a viernes de 7:00 a 22:00 y los sábados de 9:00 a 14:00. Los domingos, cerrado. Clases de yoga los martes y jueves por la tarde. Primera clase gratis.', 'items' => [
            ['A1', '¿Qué día está cerrado el gimnasio?', ['El domingo', 'El sábado', 'El lunes', 'El viernes']],
            ['A2', '¿Qué no pagas si te inscribes antes del 15?', ['La matrícula', 'Las clases de yoga', 'El primer mes', 'Los sábados']],
            ['A2', '¿Cuándo hay clases de yoga?', ['Los martes y jueves por la tarde', 'Los sábados por la mañana', 'Todos los días', 'Los lunes y miércoles']],
        ]],
        ['section' => 'reading', 'key' => 'r2', 'text' => "Querido Pablo:\n¿Qué tal todo? Ya llevo un mes en mi nuevo trabajo en Bilbao. Mis compañeros son muy simpáticos y la oficina está cerca del río, así que voy andando todos los días. Lo único malo es el tiempo: ¡llueve muchísimo! El mes que viene tengo unos días de vacaciones y quiero ir a verte a Málaga. ¿Te viene bien la segunda semana de mayo?\nUn abrazo,\nElena", 'items' => [
            ['A2', '¿Cuánto tiempo lleva Elena en su trabajo?', ['Un mes', 'Una semana', 'Un año', 'Dos meses']],
            ['A2', '¿Cómo va Elena al trabajo?', ['Andando', 'En autobús', 'En coche', 'En bicicleta']],
            ['B1', '¿Qué es lo que no le gusta a Elena?', ['El tiempo', 'Sus compañeros', 'La oficina', 'La ciudad']],
        ]],
        ['section' => 'listening', 'key' => 'l1', 'text' => 'Bienvenidos al Museo de la Ciudad. Les recordamos que el museo cierra hoy a las siete de la tarde. La cafetería está en la planta baja, junto a la tienda. Las visitas guiadas en inglés empiezan a las once y a las cuatro, en la entrada principal.', 'items' => [
            ['A1', '¿A qué hora cierra hoy el museo?', ['A las siete', 'A las once', 'A las cuatro', 'A las nueve']],
            ['A2', '¿Dónde está la cafetería?', ['En la planta baja', 'En la primera planta', 'En la entrada principal', 'Fuera del museo']],
            ['A2', '¿Cuándo empiezan las visitas guiadas en inglés?', ['A las once y a las cuatro', 'Solo a las once', 'A las diez y a las cinco', 'Cada hora']],
        ]],
        ['section' => 'listening', 'key' => 'l2', 'text' => '—Hola, Sara, ¿vienes esta noche al concierto? —No puedo, mañana tengo un examen de inglés y tengo que estudiar. —¡Vaya! ¿Y el sábado? Hay una fiesta en casa de Luis. —El sábado sí. ¿A qué hora? —A las nueve. Yo llevo la bebida y tú puedes llevar algo de comer.', 'items' => [
            ['A2', '¿Por qué no va Sara al concierto?', ['Tiene que estudiar.', 'Está enferma.', 'Trabaja esa noche.', 'No le gusta la música.']],
            ['A2', '¿Dónde es la fiesta del sábado?', ['En casa de Luis', 'En casa de Sara', 'En un bar', 'En la universidad']],
            ['B1', '¿Qué tiene que llevar Sara a la fiesta?', ['Algo de comer', 'La bebida', 'Música', 'Nada']],
        ]],
    ], 'tasks' => [
        'writing' => ['key' => 'es-dele-a2-email-2', 'type' => 'email', 'level' => 'A2', 'min' => 70, 'max' => 100, 'title' => ['en' => 'Email: a weekend plan', 'ar' => 'رسالة: خطة لعطلة نهاية الأسبوع'],
            'prompt' => 'Un amigo viene a tu ciudad el fin de semana. Escríbele un correo (entre 70 y 90 palabras): propón un plan para el sábado, explica qué vais a hacer y dile dónde y a qué hora os vais a encontrar.'],
        'speaking' => ['key' => 'es-dele-a2-monologue-2', 'type' => 'monologue', 'level' => 'A2', 'min' => 60, 'max' => 200, 'title' => ['en' => 'Monologue: your free time', 'ar' => 'حديث: وقت فراغك'],
            'prompt' => 'Habla durante uno o dos minutos sobre tu tiempo libre: qué te gusta hacer, con quién, con qué frecuencia y qué hiciste el último fin de semana.'],
    ]]],

    'delf_a2' => [['key' => 'delf-a2-practice-2', 'groups' => [
        ['section' => 'listening', 'key' => 'l1', 'text' => 'Mesdames et messieurs, le magasin fermera ses portes dans quinze minutes. Nous vous invitons à vous diriger vers les caisses. Nous vous rappelons que le magasin est ouvert demain dimanche de neuf heures à midi. Merci de votre visite et à bientôt.', 'items' => [
            ['A2', 'Quand le magasin ferme-t-il ?', ['Dans quinze minutes', 'Dans une heure', 'À midi', 'Dans cinq minutes']],
            ['A2', 'Que doivent faire les clients ?', ['Aller aux caisses', 'Revenir demain', 'Sortir par la porte de droite', 'Attendre à l’accueil']],
            ['A1', 'Le magasin est ouvert dimanche jusqu’à quelle heure ?', ['Midi', 'Neuf heures', 'Dix-huit heures', 'Il est fermé dimanche.']],
        ]],
        ['section' => 'listening', 'key' => 'l2', 'text' => "— Tu pars où en vacances cet été ? — En Bretagne, avec ma sœur. On a loué une petite maison au bord de la mer pour deux semaines. — Super ! Vous y allez en voiture ? — Non, en train, c'est plus reposant. Et sur place, on va louer des vélos.", 'items' => [
            ['A2', 'Avec qui part-elle en vacances ?', ['Avec sa sœur', 'Avec ses parents', 'Avec des amis', 'Seule']],
            ['A2', 'Combien de temps restent-elles ?', ['Deux semaines', 'Une semaine', 'Un mois', 'Trois jours']],
            ['B1', 'Pourquoi prennent-elles le train ?', ["C'est plus reposant.", "C'est moins cher.", "Elles n'ont pas de voiture.", "C'est plus rapide."]],
        ]],
        ['section' => 'reading', 'key' => 'r1', 'text' => "Piscine municipale — Informations\nLa piscine est fermée pour travaux du 2 au 16 juin. Pendant cette période, les abonnés peuvent utiliser gratuitement la piscine du quartier Saint-Paul, sur présentation de leur carte. Les cours de natation pour enfants reprendront le mercredi 18 juin.", 'items' => [
            ['A2', 'Pourquoi la piscine est-elle fermée ?', ['Pour des travaux', 'Pour les vacances', 'Pour une compétition', 'Pour le nettoyage']],
            ['A2', 'Où les abonnés peuvent-ils nager pendant la fermeture ?', ['À la piscine Saint-Paul', 'À la mer', 'Au lac', 'Nulle part']],
            ['A2', 'Quand recommencent les cours pour enfants ?', ['Le 18 juin', 'Le 2 juin', 'Le 16 juin', 'En septembre']],
        ]],
        ['section' => 'reading', 'key' => 'r2', 'text' => "Bonjour Madame Leroy,\nJe suis votre nouvelle voisine du deuxième étage. Je voulais vous prévenir que je fais une petite fête samedi soir pour mon anniversaire. Nous essaierons de ne pas faire trop de bruit et tout sera fini avant minuit. Si vous voulez passer boire un verre, vous êtes la bienvenue !\nCordialement,\nSophie Morel", 'items' => [
            ['A2', 'Qui écrit ce message ?', ['Une nouvelle voisine', 'La propriétaire', 'Une amie de Madame Leroy', 'Le gardien']],
            ['A2', 'Pourquoi Sophie fait-elle une fête ?', ["C'est son anniversaire.", 'Elle part en voyage.', 'Elle a trouvé un travail.', "C'est la fête des voisins."]],
            ['B1', 'Que propose Sophie à Madame Leroy ?', ['De venir boire un verre', 'De garder ses clés', 'De faire moins de bruit', "D'aider à préparer la fête"]],
        ]],
    ], 'tasks' => [
        'writing' => ['key' => 'fr-delf-a2-message-2', 'type' => 'email', 'level' => 'A2', 'min' => 60, 'max' => 100, 'title' => ['en' => 'Message: a new hobby', 'ar' => 'رسالة: هواية جديدة'],
            'prompt' => 'Vous avez commencé une nouvelle activité (sport, musique, cuisine…). Écrivez un message à un ami (60 à 80 mots) : dites ce que vous faites, quand et pourquoi vous aimez cette activité.'],
        'speaking' => ['key' => 'fr-delf-a2-monologue-2', 'type' => 'monologue', 'level' => 'A2', 'min' => 60, 'max' => 200, 'title' => ['en' => 'Monologue: a typical weekend', 'ar' => 'حديث: عطلة نهاية أسبوع معتادة'],
            'prompt' => "Parlez pendant une à deux minutes de vos week-ends : ce que vous faites d'habitude, avec qui, et racontez votre dernier week-end."],
    ]]],

    'goethe_a2' => [['key' => 'goethe-a2-practice-2', 'groups' => [
        ['section' => 'reading', 'key' => 'r1', 'text' => "Stadtbibliothek – Neue Öffnungszeiten\nAb 1. April ist die Bibliothek von Dienstag bis Freitag von 10 bis 19 Uhr und am Samstag von 10 bis 14 Uhr geöffnet. Montags ist geschlossen. Der Bibliotheksausweis kostet für Erwachsene 15 Euro im Jahr, für Kinder und Studierende ist er kostenlos.", 'items' => [
            ['A1', 'Wann ist die Bibliothek geschlossen?', ['Am Montag', 'Am Samstag', 'Am Dienstag', 'Am Freitag']],
            ['A2', 'Wie lange ist sie am Samstag geöffnet?', ['Von 10 bis 14 Uhr', 'Von 10 bis 19 Uhr', 'Von 14 bis 19 Uhr', 'Den ganzen Tag']],
            ['A2', 'Wer bekommt den Ausweis kostenlos?', ['Kinder und Studierende', 'Alle Erwachsenen', 'Nur Kinder', 'Niemand']],
        ]],
        ['section' => 'reading', 'key' => 'r2', 'text' => "Hallo Tim,\nvielen Dank für deine Einladung zum Grillen am Sonntag! Ich komme sehr gern. Leider kann ich erst um fünf Uhr da sein, weil ich vorher noch arbeiten muss. Soll ich einen Salat mitbringen? Und weißt du, ob man bei dir in der Nähe parken kann? Sonst nehme ich die Straßenbahn.\nBis Sonntag!\nLaura", 'items' => [
            ['A2', 'Warum kommt Laura erst um fünf Uhr?', ['Sie muss vorher arbeiten.', 'Sie hat einen Arzttermin.', 'Ihr Zug kommt spät an.', 'Sie macht einen Salat.']],
            ['A2', 'Was möchte Laura mitbringen?', ['Einen Salat', 'Getränke', 'Fleisch', 'Einen Kuchen']],
            ['B1', 'Was möchte Laura wissen?', ['Ob sie in der Nähe parken kann', 'Wann das Grillen beginnt', 'Wer noch kommt', 'Wo Tim wohnt']],
        ]],
        ['section' => 'listening', 'key' => 'l1', 'text' => 'Achtung, eine Durchsage für die Reisenden nach München: Der ICE um 14 Uhr 20 fährt heute nicht von Gleis 5, sondern von Gleis 8. Der Zug hat etwa fünfzehn Minuten Verspätung. Wir bitten um Entschuldigung.', 'items' => [
            ['A2', 'Wohin fährt der Zug?', ['Nach München', 'Nach Berlin', 'Nach Hamburg', 'Nach Köln']],
            ['A2', 'Von welchem Gleis fährt der Zug heute?', ['Von Gleis 8', 'Von Gleis 5', 'Von Gleis 14', 'Von Gleis 2']],
            ['A1', 'Wie viel Verspätung hat der Zug?', ['Etwa fünfzehn Minuten', 'Etwa fünf Minuten', 'Eine Stunde', 'Keine']],
        ]],
        ['section' => 'listening', 'key' => 'l2', 'text' => '— Guten Tag, ich möchte einen Termin beim Friseur. — Gern. Wann passt es Ihnen? — Am liebsten Donnerstagvormittag. — Donnerstag ist leider alles voll. Freitag um zehn wäre noch frei. — Gut, dann nehme ich Freitag. — Und Ihr Name, bitte? — Becker.', 'items' => [
            ['A2', 'Was möchte die Person?', ['Einen Termin beim Friseur', 'Einen Tisch im Restaurant', 'Einen Arzttermin', 'Ein Zimmer im Hotel']],
            ['B1', 'Warum klappt Donnerstag nicht?', ['Es ist kein Termin mehr frei.', 'Der Laden ist geschlossen.', 'Die Person arbeitet.', 'Der Friseur ist krank.']],
            ['A2', 'Wann ist der Termin?', ['Am Freitag um zehn', 'Am Donnerstag um zehn', 'Am Freitagnachmittag', 'Am Donnerstagnachmittag']],
        ]],
    ], 'tasks' => [
        'writing' => ['key' => 'de-goethe-a2-email-2', 'type' => 'email', 'level' => 'A2', 'min' => 30, 'max' => 60, 'title' => ['en' => 'Email: ask a colleague for help', 'ar' => 'رسالة: اطلب المساعدة من زميل'],
            'prompt' => 'Sie ziehen am Samstag um und brauchen Hilfe. Schreiben Sie einer Kollegin eine E-Mail (etwa 40 Wörter): Fragen Sie, ob sie helfen kann, sagen Sie, wann und wo, und bieten Sie etwas als Dank an.'],
        'speaking' => ['key' => 'de-goethe-a2-monologue-2', 'type' => 'monologue', 'level' => 'A2', 'min' => 50, 'max' => 200, 'title' => ['en' => 'Monologue: your last holiday', 'ar' => 'حديث: عطلتك الأخيرة'],
            'prompt' => 'Erzählen Sie ein bis zwei Minuten über Ihren letzten Urlaub: Wohin sind Sie gefahren, mit wem, was haben Sie gemacht und was hat Ihnen besonders gefallen?'],
    ]]],

    'hsk_1' => [['key' => 'hsk-1-practice-2', 'groups' => [
        ['section' => 'listening', 'key' => 'l1', 'text' => '我叫张明，我是学生。我今年十八岁。我有一个姐姐，她是医生。', 'items' => [
            ['PRE_A1', '他叫什么名字？', ['张明', '王明', '张月', '李明']],
            ['A1', '他今年多大？', ['十八岁', '八岁', '二十岁', '十岁']],
            ['A1', '他姐姐做什么工作？', ['医生', '老师', '学生', '司机']],
        ]],
        ['section' => 'listening', 'key' => 'l2', 'text' => '女：你明天去哪儿？男：我明天去朋友家。女：你朋友家在哪儿？男：在学校后面。女：几点去？男：下午三点。', 'items' => [
            ['A1', '男的明天去哪儿？', ['朋友家', '学校', '商店', '医院']],
            ['A1', '朋友家在哪儿？', ['学校后面', '学校前面', '商店后面', '医院前面']],
            ['A2', '他几点去？', ['下午三点', '上午三点', '下午四点', '上午十点']],
        ]],
        ['section' => 'reading', 'key' => 'r1', 'text' => '昨天下雨了，我没去学校。我在家看书、喝茶。晚上妈妈做了米饭和菜。', 'items' => [
            ['A1', '昨天天气怎么样？', ['下雨了', '很热', '很冷', '很好']],
            ['A1', '昨天“我”在哪儿？', ['在家', '在学校', '在商店', '在朋友家']],
            ['A2', '晚上谁做饭？', ['妈妈', '爸爸', '“我”', '朋友']],
        ]],
        ['section' => 'reading', 'key' => 'r2', 'text' => '王先生在商店买东西。他买了五个苹果和一本书。苹果很便宜，书有点儿贵。', 'items' => [
            ['A1', '王先生在哪儿？', ['商店', '学校', '饭店', '家里']],
            ['A1', '他买了几个苹果？', ['五个', '一个', '三个', '十个']],
            ['A2', '什么东西有点儿贵？', ['书', '苹果', '茶', '杯子']],
        ]],
    ], 'tasks' => []]],
];
