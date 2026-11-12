import json, sys
OUT = sys.argv[1]

def W(lemma, pos, meaning_en, meaning_ar, example, gloss, forms=(), gender=None, level="A1", syn=(), ant=()):
    d = {"lemma": lemma, "pos": pos, "level": level, "meaning": {"en": meaning_en, "ar": meaning_ar}, "example": [example, gloss]}
    if forms: d["forms"] = list(forms)
    if gender: d["gender"] = gender
    if syn: d["syn"] = list(syn)
    if ant: d["ant"] = list(ant)
    return d

def T(slug, title_en, title_ar, exp_en, exp_ar, examples, level="A1"):
    return {"slug": slug, "level": level, "title": {"en": title_en, "ar": title_ar}, "explanation": {"en": exp_en, "ar": exp_ar}, "examples": examples}

def cloze(key, topic, qs, skill="grammar", level="A1"):
    return {"key": key, "type": "cloze", "skill": skill, "level": level, "topic": topic, "questions": [{"p": p, "a": a if isinstance(a, list) else [a]} for p, a in qs]}

def mcq(key, qs, topic=None, skill="grammar", level="A1"):
    e = {"key": key, "type": "mcq", "skill": skill, "level": level, "questions": [{"p": p, "o": o} for p, o in qs]}
    if topic: e["topic"] = topic
    if skill == "vocabulary": e["title"] = {"en": "Everyday words", "ar": "كلمات يومية"}
    return e

def order(key, sentences, level="A1"):
    return {"key": key, "type": "ordering", "skill": "grammar", "level": level, "title": {"en": "Build simple sentences", "ar": "كوّن جملًا بسيطة"}, "questions": [{"o": s} for s in sentences]}

def reading(key, title, text, qs, level="A1", kind="reading"):
    return {"key": key, "type": kind, "skill": kind, "level": level, "title": title, "text": text, "questions": [{"p": p, "o": o} for p, o in qs]}

def dictation(key, sentences):
    return {"key": key, "type": "dictation", "skill": "listening", "level": "A1", "title": {"en": "Short everyday sentences", "ar": "جمل يومية قصيرة"}, "questions": [{"a": [s]} for s in sentences]}

def repeat(key, sentences):
    code = key.split("-")[0]
    return {"key": key, "type": "repeat", "skill": "speaking", "level": "A1", "title": {"en": "Say it aloud", "ar": "قلها بصوت عالٍ"},
            "objectives": [{"code": f"{code}.a1.pronounce", "skill": "speaking", "text": {"en": "Pronounce everyday sentences clearly enough to be understood", "ar": "نطق جمل يومية بوضوح يكفي لفهمها"}}],
            "questions": [{"r": s} for s in sentences]}

def course(code, name_en, name_ar, topics, words, ex, l4=None):
    lname = {"en": name_en, "ar": name_ar}
    L = lambda en, ar: {"en": en, "ar": ar}
    l4 = l4 or (L("Likes and plans", "ما تحبه وخططك"), L(f"Say what you like and understand short everyday conversations.", "عبّر عمّا تحبه وافهم محادثات يومية قصيرة."))
    return {
        "slug": f"{code}-foundations", "level_from": "PRE_A1", "level_to": "A2",
        "title": L(f"{name_en} foundations", f"أساسيات {name_ar}"),
        "description": L(f"Your first steps in {name_en}: introductions, everyday objects, routines and likes.", f"خطواتك الأولى في {name_ar}: التعارف والأشياء اليومية والروتين وما تحبه."),
        "units": [
            {"title": L("First steps", "الخطوات الأولى"), "lessons": [
                {"key": f"{code}-u1-l1", "skill": "grammar", "level": "A1", "topic": topics[0], "minutes": 12,
                 "title": L("Who are you?", "من أنت؟"),
                 "intro": L("Introduce yourself and others, and learn your first greetings.", "عرّف بنفسك وبالآخرين وتعلّم أولى عبارات التحية."),
                 "objectives": [{"code": f"{code}.a1.introduce", "skill": "speaking", "text": L("Introduce yourself and say where you are from", "التعريف بنفسك وذكر بلدك")},
                                {"code": f"{code}.a1.{topics[0]}", "skill": "grammar", "text": L("Use the key verb or structure for describing people", "استخدام التركيب الأساسي لوصف الأشخاص")}],
                 "words": words[0], "exercises": [ex[0], ex[5]]},
                {"key": f"{code}-u1-l2", "skill": "grammar", "level": "A1", "topic": topics[1], "minutes": 12,
                 "title": L("Things around you", "الأشياء من حولك"),
                 "intro": L("Name everyday objects and describe them correctly.", "سمِّ الأشياء اليومية وصِفها بشكل صحيح."),
                 "objectives": [{"code": f"{code}.a1.{topics[1]}", "skill": "grammar", "text": L("Match nouns with the right articles and adjectives", "مطابقة الأسماء مع أدوات التعريف والصفات الصحيحة")},
                                {"code": f"{code}.a1.objects", "skill": "vocabulary", "text": L("Name common objects, food and drinks", "تسمية الأشياء والأطعمة والمشروبات الشائعة")}],
                 "words": words[1], "exercises": [ex[1], ex[4]]},
            ]},
            {"title": L("Everyday life", "الحياة اليومية"), "lessons": [
                {"key": f"{code}-u2-l1", "skill": "grammar", "level": "A1", "topic": topics[2], "minutes": 15,
                 "title": L("What do you do?", "ماذا تفعل؟"),
                 "intro": L("Talk about work, study and daily routines in the present tense.", "تحدّث عن العمل والدراسة والروتين اليومي بصيغة المضارع."),
                 "objectives": [{"code": f"{code}.a1.routine", "skill": "speaking", "text": L("Describe your daily routine", "وصف روتينك اليومي")},
                                {"code": f"{code}.a1.read_family", "skill": "reading", "text": L("Understand a short text about a family", "فهم نص قصير عن عائلة")}],
                 "words": words[2], "exercises": [ex[2], ex[6], ex[9]]},
                {"key": f"{code}-u2-l2", "skill": "grammar", "level": "A1", "topic": topics[3], "minutes": 15,
                 "title": l4[0], "intro": l4[1],
                 "objectives": [{"code": f"{code}.a1.{topics[3]}", "skill": "grammar", "text": L("Use the lesson's grammar to talk about everyday things", "استخدام قاعدة الدرس للحديث عن الأمور اليومية")},
                                {"code": f"{code}.a1.cafe", "skill": "listening", "text": L("Understand a simple order in a café", "فهم طلب بسيط في مقهى")}],
                 "words": words[3], "exercises": [ex[3], ex[8], ex[7]]},
            ]},
        ],
    }

packs = {}

# ---------------------------------------------------------------- Spanish
es_words = [
    W("hola","interj","hello","مرحبًا","¡Hola! ¿Qué tal?","Hello! How are you?"),
    W("adiós","interj","goodbye","مع السلامة","Adiós, hasta mañana.","Goodbye, see you tomorrow."),
    W("gracias","interj","thank you","شكرًا","Muchas gracias por todo.","Thank you very much for everything."),
    W("amigo","noun","friend","صديق","Mi amigo vive en Lima.","My friend lives in Lima.",["amigos","amiga","amigas"],"m"),
    W("casa","noun","house, home","بيت","Mi casa es pequeña.","My house is small.",["casas"],"f"),
    W("familia","noun","family","عائلة","Mi familia es grande.","My family is big.",["familias"],"f"),
    W("libro","noun","book","كتاب","Leo un libro.","I'm reading a book.",["libros"],"m"),
    W("mesa","noun","table","طاولة","El café está en la mesa.","The coffee is on the table.",["mesas"],"f"),
    W("café","noun","coffee","قهوة","Tomo café por la mañana.","I drink coffee in the morning.",["cafés"],"m"),
    W("agua","noun","water","ماء","Quiero un vaso de agua.","I'd like a glass of water.",["aguas"],"f"),
    W("pan","noun","bread","خبز","Compro pan cada día.","I buy bread every day.",["panes"],"m"),
    W("trabajo","noun","work, job","عمل","Mi trabajo es interesante.","My job is interesting.",["trabajos"],"m"),
    W("ciudad","noun","city","مدينة","Madrid es una ciudad grande.","Madrid is a big city.",["ciudades"],"f"),
    W("día","noun","day","يوم","Hoy es un buen día.","Today is a good day.",["días"],"m"),
    W("noche","noun","night","ليل","Estudio por la noche.","I study at night.",["noches"],"f"),
    W("grande","adj","big","كبير","Es una casa grande.","It's a big house.",["grandes"],ant=["pequeño"]),
    W("pequeño","adj","small","صغير","Tengo un perro pequeño.","I have a small dog.",["pequeña","pequeños","pequeñas"],ant=["grande"]),
    W("bueno","adj","good","جيد","Es un libro muy bueno.","It's a very good book.",["buena","buenos","buenas","buen"],ant=["malo"]),
    W("hablar","verb","to speak","يتكلّم","Hablo español e inglés.","I speak Spanish and English.",["hablo","hablas","habla","hablamos","habláis","hablan"]),
    W("trabajar","verb","to work","يعمل","Trabaja en un hospital.","She works in a hospital.",["trabajas","trabaja","trabajamos","trabajáis","trabajan"]),
    W("estudiar","verb","to study","يدرس","Estudiamos juntos.","We study together.",["estudio","estudias","estudia","estudiamos","estudiáis","estudian"]),
    W("comprar","verb","to buy","يشتري","Compro fruta en el mercado.","I buy fruit at the market.",["compro","compras","compra","compramos","compráis","compran"]),
    W("mercado","noun","market","سوق","El mercado abre a las ocho.","The market opens at eight.",["mercados"],"m"),
    W("tienda","noun","shop","متجر","La tienda está cerrada.","The shop is closed.",["tiendas"],"f"),
]
es_lessons_words = [["hola","adiós","gracias","amigo","familia","ciudad"],["casa","libro","mesa","café","agua","pan","grande","pequeño"],["hablar","trabajar","estudiar","comprar","trabajo","día","noche"],["bueno","mercado","tienda"]]
es_topics = [
    T("ser_present","The verb ser (present)","الفعل ser في المضارع",
      "Use ser to say who someone is, where they are from and to describe lasting characteristics. Forms: yo soy, tú eres, él/ella/usted es, nosotros somos, vosotros sois, ellos/ustedes son.",
      "يُستخدم الفعل ser للتعريف بالشخص وبلده وبالصفات الدائمة. تصريفه: yo soy، tú eres، él/ella/usted es، nosotros somos، vosotros sois، ellos/ustedes son.",
      [["Soy de Madrid.","I am from Madrid."],["Ella es médica.","She is a doctor."],["Somos amigos.","We are friends."]]),
    T("article_gender","Articles and gender","أدوات التعريف والتذكير والتأنيث",
      "Every Spanish noun is masculine or feminine. Use el/un with masculine nouns (often ending in -o) and la/una with feminine nouns (often ending in -a). Plural: los/las, unos/unas.",
      "كل اسم في الإسبانية مذكر أو مؤنث. استخدم el/un مع المذكر (وغالبًا ينتهي بـ -o) وla/una مع المؤنث (وغالبًا ينتهي بـ -a). وفي الجمع: los/las وunos/unas.",
      [["el libro","the book"],["la mesa","the table"],["unas casas","some houses"]]),
    T("present_regular_ar","Present tense of -ar verbs","المضارع للأفعال المنتهية بـ -ar",
      "Remove -ar and add -o, -as, -a, -amos, -áis, -an. Hablar → hablo, hablas, habla, hablamos, habláis, hablan.",
      "احذف -ar وأضف -o، -as، -a، -amos، -áis، -an. مثال: hablar ← hablo، hablas، habla، hablamos، habláis، hablan.",
      [["Hablo español.","I speak Spanish."],["¿Trabajas aquí?","Do you work here?"],["Ellos estudian mucho.","They study a lot."]]),
    T("gustar","Saying what you like with gustar","التعبير عمّا تحبه بالفعل gustar",
      "Gustar agrees with the thing that is liked: me gusta + singular noun or a verb, me gustan + plural noun. The person who likes it is shown with me, te, le, nos, os, les.",
      "يتطابق الفعل gustar مع الشيء المحبوب: me gusta مع المفرد أو الفعل، وme gustan مع الجمع. ويُعبَّر عن الشخص بالضمائر me، te، le، nos، os، les.",
      [["Me gusta el café.","I like coffee."],["Nos gustan los libros.","We like books."],["¿Te gusta bailar?","Do you like dancing?"]]),
]
es_ex = [
    cloze("es-a1-ser-cloze","ser_present",[("Yo ___ de México.","soy"),("¿Tú ___ estudiante?","eres"),("Nosotros ___ amigos.","somos"),("Ellas ___ médicas.","son")]),
    mcq("es-a1-articles-mcq",[("___ casa es grande.",["La","El","Los","Un"]),("Tengo ___ libro.",["un","una","unas","la"]),("___ mesas son pequeñas.",["Las","Los","La","El"]),("Es ___ ciudad bonita.",["una","un","unos","el"])],"article_gender"),
    cloze("es-a1-ar-cloze","present_regular_ar",[("Yo ___ (hablar) español.","hablo"),("Ella ___ (trabajar) en una tienda.","trabaja"),("Nosotros ___ (estudiar) por la noche.","estudiamos"),("¿Vosotros ___ (comprar) pan?","compráis")]),
    mcq("es-a1-gustar-mcq",[("Me ___ el café.",["gusta","gustan","gusto","gustas"]),("Nos ___ los libros.",["gustan","gusta","gustamos","gustar"]),("¿Te ___ bailar?",["gusta","gustan","gustas","gusto"]),("A ella ___ gustan las ciudades grandes.",["le","la","se","lo"])],"gustar"),
    mcq("es-a1-vocab-mcq",[("Bebo un vaso de ___.",["agua","pan","mesa","libro"]),("Lo contrario de «grande» es ___.",["pequeño","bueno","alto","nuevo"]),("Compro fruta en el ___.",["mercado","libro","día","trabajo"]),("Leo un ___ por la noche.",["libro","pan","agua","día"]),("Por la mañana tomo un ___.",["café","mesa","ciudad","noche"])],skill="vocabulary"),
    order("es-a1-order",[["Yo","soy","de","España"],["Nos","gusta","el","café"],["Ella","trabaja","en","una","tienda"]]),
    reading("es-a1-read-1",{"en":"Lucía's family","ar":"عائلة لوسيا"},"Me llamo Lucía y vivo en Sevilla con mi familia. Mi padre trabaja en un hospital y mi madre es profesora. Tengo un hermano pequeño, Pablo. Los sábados compramos pan y fruta en el mercado.",
      [("¿Dónde vive Lucía?",["En Sevilla","En Madrid","En un hospital","En el mercado"]),("¿Qué hace la madre de Lucía?",["Es profesora","Trabaja en un hospital","Trabaja en el mercado","Es estudiante"]),("¿Qué compran los sábados?",["Pan y fruta","Libros","Café y agua","Ropa"])]),
    reading("es-a2-read-2",{"en":"A new job","ar":"عمل جديد"},"Daniel empezó un trabajo nuevo el lunes. Antes trabajaba en una tienda pequeña, pero ahora trabaja en una oficina en el centro de la ciudad. El primer día estaba nervioso, pero sus compañeros fueron muy amables. Ahora va al trabajo en metro y tarda veinte minutos.",
      [("¿Dónde trabajaba Daniel antes?",["En una tienda pequeña","En una oficina","En el metro","En el centro"]),("¿Cómo se sentía el primer día?",["Nervioso","Cansado","Aburrido","Enfadado"]),("¿Cuánto tarda en llegar al trabajo?",["Veinte minutos","Una hora","Diez minutos","Dos horas"])],level="A2"),
    reading("es-a1-listen-1",{"en":"At the café","ar":"في المقهى"},"Buenos días. Quiero un café con leche y un pan con tomate, por favor. ¿Cuánto es? Son cuatro euros con cincuenta.",
      [("¿Qué bebe la persona?",["Un café con leche","Un té","Agua","Un zumo"]),("¿Qué come?",["Pan con tomate","Fruta","Una ensalada","Nada"]),("¿Cuánto cuesta?",["Cuatro euros con cincuenta","Cinco euros","Cuatro euros","Tres euros con cincuenta"])],kind="listening"),
    dictation("es-a1-dictation",["Me llamo Ana y soy de Chile.","Mi casa es pequeña.","Nosotros estudiamos por la noche."]),
]
packs["es"] = {"language":"es","topics":es_topics,"words":es_words,"exercises":es_ex,"course":course("es","Spanish","الإسبانية",[t["slug"] for t in es_topics],es_lessons_words,[e["key"] for e in es_ex])}

# ---------------------------------------------------------------- English
en_words = [
    W("hello","interj","hello","مرحبًا","Hello! How are you?","—"),
    W("goodbye","interj","goodbye","مع السلامة","Goodbye, see you tomorrow.","—"),
    W("thanks","interj","thank you","شكرًا","Thanks for your help.","—",["thank you"]),
    W("friend","noun","friend","صديق","My friend lives in Dubai.","—",["friends"]),
    W("house","noun","house","بيت","My house is small.","—",["houses"]),
    W("family","noun","family","عائلة","My family is big.","—",["families"]),
    W("book","noun","book","كتاب","I'm reading a book.","—",["books"]),
    W("table","noun","table","طاولة","The coffee is on the table.","—",["tables"]),
    W("coffee","noun","coffee","قهوة","I drink coffee in the morning.","—"),
    W("water","noun","water","ماء","Can I have some water?","—"),
    W("bread","noun","bread","خبز","I buy bread every day.","—"),
    W("job","noun","job, work","وظيفة","My job is interesting.","—",["jobs"]),
    W("city","noun","city","مدينة","Cairo is a big city.","—",["cities"]),
    W("day","noun","day","يوم","Today is a good day.","—",["days"]),
    W("night","noun","night","ليل","I study at night.","—",["nights"]),
    W("big","adj","big","كبير","It's a big house.","—",["bigger","biggest"],ant=["small"]),
    W("small","adj","small","صغير","I have a small car.","—",["smaller","smallest"],ant=["big"]),
    W("good","adj","good","جيد","It's a very good book.","—",["better","best"],ant=["bad"]),
    W("speak","verb","to speak","يتكلّم","I speak Arabic and English.","—",["speaks","spoke","speaking"]),
    W("study","verb","to study","يدرس","We study together.","—",["studies","studied","studying"]),
    W("buy","verb","to buy","يشتري","I buy fruit at the market.","—",["buys","bought","buying"]),
    W("learn","verb","to learn","يتعلّم","She learns very fast.","—",["learns","learned","learnt","learning"]),
    W("market","noun","market","سوق","The market opens at eight.","—",["markets"]),
    W("shop","noun","shop","متجر","The shop is closed.","—",["shops"]),
]
for w in en_words: w["example"] = [w["example"][0]]
en_topics = [
    T("verb_to_be","The verb to be (present)","فعل الكينونة to be في المضارع",
      "Use am, is and are to say who or what someone is, where they are from and how they are: I am, you are, he/she/it is, we are, they are. Short forms: I'm, you're, she's.",
      "استخدم am وis وare لتقول من هو الشخص ومن أين هو وكيف حاله: I am وyou are وhe/she/it is وwe are وthey are. الصيغ المختصرة: I'm وyou're وshe's.",
      [["I am from Egypt.","أنا من مصر."],["She is a doctor.","هي طبيبة."],["We are friends.","نحن أصدقاء."]]),
    T("articles","Articles: a, an, the","أدوات التنكير والتعريف a وan وthe",
      "Use a before consonant sounds and an before vowel sounds for one thing mentioned for the first time. Use the for something specific or already known. There is no article before most plural or uncountable nouns in general statements.",
      "استخدم a قبل الأصوات الساكنة وan قبل الأصوات المتحركة عند ذكر شيء واحد لأول مرة. واستخدم the للشيء المحدد أو المعروف. لا نستخدم أداة قبل معظم أسماء الجمع أو الأسماء غير المعدودة في العبارات العامة.",
      [["a book, an apple","كتاب، تفاحة"],["The sun is hot.","الشمس حارة."],["I like coffee.","أحب القهوة."]]),
    T("present_simple","Present simple","المضارع البسيط",
      "Use the present simple for habits and facts. Add -s or -es after he, she and it: I work → she works, I study → he studies. Questions and negatives use do/does: Do you work? She doesn't work.",
      "يُستخدم المضارع البسيط للعادات والحقائق. أضف -s أو -es مع he وshe وit: I work ← she works، وI study ← he studies. وفي السؤال والنفي نستخدم do/does.",
      [["She works in a shop.","هي تعمل في متجر."],["He studies at night.","هو يدرس في الليل."],["Do you speak English?","هل تتكلم الإنجليزية؟"]]),
    T("like_ing","Talking about likes: like + -ing","التعبير عمّا تحبه: like مع -ing",
      "Use like + noun or like + verb-ing to talk about things you enjoy: I like coffee. I like reading. With he/she/it use likes; for negatives use don't/doesn't like.",
      "استخدم like مع اسم أو مع الفعل المنتهي بـ -ing للتعبير عمّا تستمتع به. ومع he/she/it نستخدم likes، وفي النفي don't/doesn't like.",
      [["I like reading.","أحب القراءة."],["She doesn't like tea.","هي لا تحب الشاي."],["Do you like big cities?","هل تحب المدن الكبيرة؟"]]),
]
en_ex = [
    cloze("en-a1-be-cloze","verb_to_be",[("I ___ from Egypt.",["am","'m"]),("You ___ a good student.",["are","'re"]),("She ___ my sister.",["is","'s"]),("They ___ at home today.",["are","'re"])]),
    mcq("en-a1-articles-mcq",[("I eat ___ apple every day.",["an","a","any","much"]),("She is ___ doctor.",["a","an","any","much"]),("___ sun is very hot today.",["The","A","An","Some"]),("There is ___ old book on the table.",["an","a","any","many"])],"articles"),
    cloze("en-a1-present-cloze","present_simple",[("She ___ (work) in a shop.","works"),("He ___ (study) English at night.","studies"),("We ___ (buy) bread every day.","buy"),("My friend ___ (speak) three languages.","speaks")]),
    mcq("en-a1-like-mcq",[("I like ___ books.",["reading","read to","reads","readed"]),("She ___ like tea.",["doesn't","don't","isn't","not"]),("___ you like big cities?",["Do","Does","Are","Is"]),("My brother likes ___ football.",["playing","plays","to playing","played"])],"like_ing"),
    mcq("en-a1-vocab-mcq",[("I drink a glass of ___.",["water","bread","table","book"]),("The opposite of \"big\" is ___.",["small","good","tall","new"]),("I buy fruit at the ___.",["market","book","night","job"]),("I read a ___ before bed.",["book","bread","water","day"]),("In the morning I have a cup of ___.",["coffee","table","city","night"])],skill="vocabulary"),
    order("en-a1-order",[["I","am","from","Egypt"],["She","works","in","a","shop"],["Do","you","like","coffee"]]),
    reading("en-a1-read-1",{"en":"Omar's family","ar":"عائلة عمر"},"My name is Omar and I live in Alexandria with my family. My father works in a hospital and my mother is a teacher. I have a little sister, Nour. On Saturdays we buy bread and fruit at the market.",
      [("Where does Omar live?",["In Alexandria","In Cairo","In a hospital","At the market"]),("What does Omar's mother do?",["She is a teacher","She works in a hospital","She works at the market","She is a student"]),("What do they buy on Saturdays?",["Bread and fruit","Books","Coffee and water","Clothes"])]),
    reading("en-a2-read-2",{"en":"A new job","ar":"وظيفة جديدة"},"Daniel started a new job on Monday. Before, he worked in a small shop, but now he works in an office in the city centre. On his first day he was nervous, but his colleagues were very friendly. Now he goes to work by train and the journey takes twenty minutes.",
      [("Where did Daniel work before?",["In a small shop","In an office","On a train","In the city centre"]),("How did he feel on his first day?",["Nervous","Tired","Bored","Angry"]),("How long does his journey take?",["Twenty minutes","An hour","Ten minutes","Two hours"])],level="A2"),
    reading("en-a1-listen-1",{"en":"At the café","ar":"في المقهى"},"Good morning. Can I have a coffee with milk and a cheese sandwich, please? How much is that? That's four pounds fifty.",
      [("What does the person drink?",["A coffee with milk","A tea","Water","Orange juice"]),("What does the person eat?",["A cheese sandwich","Fruit","A salad","Nothing"]),("How much does it cost?",["Four pounds fifty","Five pounds","Four pounds","Three pounds fifty"])],kind="listening"),
    dictation("en-a1-dictation",["My name is Sara and I am from Jordan.","My house is small.","We study English at night."]),
]
packs["en"] = {"language":"en","topics":en_topics,"words":en_words,"exercises":en_ex,"course":course("en","English","الإنجليزية",[t["slug"] for t in en_topics],[["hello","goodbye","thanks","friend","family","city"],["house","book","table","coffee","water","bread","big","small"],["speak","study","buy","learn","job","day","night"],["good","market","shop"]],[e["key"] for e in en_ex])}

# ---------------------------------------------------------------- French
fr_words = [
    W("bonjour","interj","hello, good morning","مرحبًا / صباح الخير","Bonjour, ça va ?","Hello, how are you?"),
    W("au revoir","interj","goodbye","مع السلامة","Au revoir, à demain !","Goodbye, see you tomorrow!"),
    W("merci","interj","thank you","شكرًا","Merci beaucoup !","Thank you very much!"),
    W("ami","noun","friend","صديق","Mon ami habite à Lyon.","My friend lives in Lyon.",["amis","amie","amies"],"m"),
    W("maison","noun","house","بيت","Ma maison est petite.","My house is small.",["maisons"],"f"),
    W("famille","noun","family","عائلة","Ma famille est grande.","My family is big.",["familles"],"f"),
    W("livre","noun","book","كتاب","Je lis un livre.","I'm reading a book.",["livres"],"m"),
    W("table","noun","table","طاولة","Le café est sur la table.","The coffee is on the table.",["tables"],"f"),
    W("café","noun","coffee","قهوة","Je bois un café le matin.","I drink a coffee in the morning.",["cafés"],"m"),
    W("eau","noun","water","ماء","Je voudrais de l'eau.","I'd like some water.",["eaux"],"f"),
    W("pain","noun","bread","خبز","J'achète du pain tous les jours.","I buy bread every day.",["pains"],"m"),
    W("travail","noun","work, job","عمل","Mon travail est intéressant.","My job is interesting.",["travaux"],"m"),
    W("ville","noun","city, town","مدينة","Paris est une grande ville.","Paris is a big city.",["villes"],"f"),
    W("jour","noun","day","يوم","C'est un beau jour.","It's a lovely day.",["jours"],"m"),
    W("nuit","noun","night","ليل","J'étudie la nuit.","I study at night.",["nuits"],"f"),
    W("grand","adj","big, tall","كبير","C'est une grande maison.","It's a big house.",["grande","grands","grandes"],ant=["petit"]),
    W("petit","adj","small","صغير","J'ai un petit chien.","I have a small dog.",["petite","petits","petites"],ant=["grand"]),
    W("bon","adj","good","جيد","C'est un très bon livre.","It's a very good book.",["bonne","bons","bonnes"],ant=["mauvais"]),
    W("parler","verb","to speak","يتكلّم","Je parle français et arabe.","I speak French and Arabic.",["parle","parles","parlons","parlez","parlent"]),
    W("travailler","verb","to work","يعمل","Elle travaille dans un hôpital.","She works in a hospital.",["travaille","travailles","travaillons","travaillez","travaillent"]),
    W("étudier","verb","to study","يدرس","Nous étudions ensemble.","We study together.",["étudie","étudies","étudions","étudiez","étudient"]),
    W("acheter","verb","to buy","يشتري","J'achète des fruits au marché.","I buy fruit at the market.",["achète","achètes","achetons","achetez","achètent"]),
    W("marché","noun","market","سوق","Le marché ouvre à huit heures.","The market opens at eight.",["marchés"],"m"),
    W("magasin","noun","shop","متجر","Le magasin est fermé.","The shop is closed.",["magasins"],"m"),
]
fr_topics = [
    T("etre_present","The verb être (present)","الفعل être في المضارع",
      "Use être to say who someone is, where they are from and to describe them. Forms: je suis, tu es, il/elle est, nous sommes, vous êtes, ils/elles sont.",
      "يُستخدم الفعل être للتعريف بالشخص وبلده ووصفه. تصريفه: je suis، tu es، il/elle est، nous sommes، vous êtes، ils/elles sont.",
      [["Je suis de Tunis.","I am from Tunis."],["Elle est médecin.","She is a doctor."],["Nous sommes amis.","We are friends."]]),
    T("article_gender","Articles and gender","أدوات التعريف والتذكير والتأنيث",
      "French nouns are masculine or feminine. Use le/un with masculine nouns and la/une with feminine nouns; before a vowel, le and la become l'. Plural: les, des.",
      "الأسماء الفرنسية مذكرة أو مؤنثة. استخدم le/un مع المذكر وla/une مع المؤنث، وقبل حرف متحرك تصبح le وla ‏l'. وفي الجمع: les وdes.",
      [["le livre","the book"],["la maison","the house"],["l'eau","the water"]]),
    T("present_er","Present tense of -er verbs","المضارع للأفعال المنتهية بـ -er",
      "Remove -er and add -e, -es, -e, -ons, -ez, -ent. Parler → je parle, tu parles, il parle, nous parlons, vous parlez, ils parlent. The -e, -es and -ent endings are silent.",
      "احذف -er وأضف -e، -es، -e، -ons، -ez، -ent. مثال: parler ← je parle، tu parles، il parle، nous parlons، vous parlez، ils parlent. والنهايات -e و-es و-ent لا تُنطق.",
      [["Je parle français.","I speak French."],["Tu travailles ici ?","Do you work here?"],["Ils étudient beaucoup.","They study a lot."]]),
    T("aimer","Saying what you like with aimer","التعبير عمّا تحبه بالفعل aimer",
      "Use aimer + definite article + noun (j'aime le café) or aimer + infinitive (j'aime lire). In the negative, keep the definite article: je n'aime pas le thé.",
      "استخدم aimer مع أداة التعريف والاسم (j'aime le café) أو مع المصدر (j'aime lire). وفي النفي تبقى أداة التعريف: je n'aime pas le thé.",
      [["J'aime le café.","I like coffee."],["Nous aimons les livres.","We like books."],["Elle n'aime pas le thé.","She doesn't like tea."]]),
]
fr_ex = [
    cloze("fr-a1-etre-cloze","etre_present",[("Je ___ de Tunis.","suis"),("Tu ___ étudiant ?","es"),("Nous ___ amis.","sommes"),("Elles ___ médecins.","sont")]),
    mcq("fr-a1-articles-mcq",[("___ maison est grande.",["La","Le","Les","Un"]),("J'ai ___ livre.",["un","une","des","la"]),("___ tables sont petites.",["Les","Le","La","Une"]),("C'est ___ ville magnifique.",["une","un","des","le"])],"article_gender"),
    cloze("fr-a1-er-cloze","present_er",[("Je ___ (parler) français.","parle"),("Elle ___ (travailler) dans un magasin.","travaille"),("Nous ___ (étudier) le soir.","étudions"),("Vous ___ (acheter) du pain ?","achetez")]),
    mcq("fr-a1-aimer-mcq",[("Nous ___ les livres.",["aimons","aime","aimez","aiment"]),("Elle n'aime pas ___ thé.",["le","du","de","un"]),("Ils aiment ___ au football.",["jouer","jouent","joué","jouant"]),("Tu ___ le chocolat ?",["aimes","aime","aimons","aiment"])],"aimer"),
    mcq("fr-a1-vocab-mcq",[("Je bois un verre d'___.",["eau","pain","table","livre"]),("Le contraire de « grand » est ___.",["petit","bon","haut","nouveau"]),("J'achète des fruits au ___.",["marché","livre","jour","travail"]),("Je lis un ___ le soir.",["livre","pain","café","jour"]),("Le matin, je prends un ___.",["café","table","ville","nuit"])],skill="vocabulary"),
    order("fr-a1-order",[["Je","suis","de","France"],["Nous","aimons","le","café"],["Elle","travaille","dans","un","magasin"]]),
    reading("fr-a1-read-1",{"en":"Lucie's family","ar":"عائلة لوسي"},"Je m'appelle Lucie et j'habite à Lyon avec ma famille. Mon père travaille dans un hôpital et ma mère est professeure. J'ai un petit frère, Paul. Le samedi, nous achetons du pain et des fruits au marché.",
      [("Où habite Lucie ?",["À Lyon","À Paris","Dans un hôpital","Au marché"]),("Que fait la mère de Lucie ?",["Elle est professeure","Elle travaille dans un hôpital","Elle travaille au marché","Elle est étudiante"]),("Qu'achètent-ils le samedi ?",["Du pain et des fruits","Des livres","Du café et de l'eau","Des vêtements"])]),
    reading("fr-a2-read-2",{"en":"A new job","ar":"عمل جديد"},"Daniel a commencé un nouveau travail lundi. Avant, il travaillait dans un petit magasin, mais maintenant il travaille dans un bureau au centre-ville. Le premier jour, il était nerveux, mais ses collègues ont été très gentils. Maintenant, il va au travail en métro et le trajet dure vingt minutes.",
      [("Où travaillait Daniel avant ?",["Dans un petit magasin","Dans un bureau","Dans le métro","Au centre-ville"]),("Comment se sentait-il le premier jour ?",["Nerveux","Fatigué","Ennuyé","En colère"]),("Combien de temps dure son trajet ?",["Vingt minutes","Une heure","Dix minutes","Deux heures"])],level="A2"),
    reading("fr-a1-listen-1",{"en":"At the café","ar":"في المقهى"},"Bonjour. Je voudrais un café au lait et un croissant, s'il vous plaît. C'est combien ? Ça fait quatre euros cinquante.",
      [("Que boit la personne ?",["Un café au lait","Un thé","De l'eau","Un jus d'orange"]),("Que mange-t-elle ?",["Un croissant","Un fruit","Une salade","Rien"]),("Combien ça coûte ?",["Quatre euros cinquante","Cinq euros","Quatre euros","Trois euros cinquante"])],kind="listening"),
    dictation("fr-a1-dictation",["Je m'appelle Anne et je suis de Lyon.","Ma maison est petite.","Nous étudions le soir."]),
]
packs["fr"] = {"language":"fr","topics":fr_topics,"words":fr_words,"exercises":fr_ex,"course":course("fr","French","الفرنسية",[t["slug"] for t in fr_topics],[["bonjour","au revoir","merci","ami","famille","ville"],["maison","livre","table","café","eau","pain","grand","petit"],["parler","travailler","étudier","acheter","travail","jour","nuit"],["bon","marché","magasin"]],[e["key"] for e in fr_ex])}

# ---------------------------------------------------------------- German
de_words = [
    W("hallo","interj","hello","مرحبًا","Hallo, wie geht's?","Hello, how are you?"),
    W("tschüss","interj","bye","مع السلامة","Tschüss, bis morgen!","Bye, see you tomorrow!"),
    W("danke","interj","thank you","شكرًا","Danke schön!","Thank you very much!"),
    W("Freund","noun","friend","صديق","Mein Freund wohnt in Wien.","My friend lives in Vienna.",["Freunde","Freundin","Freundinnen"],"m"),
    W("Haus","noun","house","بيت","Mein Haus ist klein.","My house is small.",["Häuser","Hause"],"n"),
    W("Familie","noun","family","عائلة","Meine Familie ist groß.","My family is big.",["Familien"],"f"),
    W("Buch","noun","book","كتاب","Ich lese ein Buch.","I'm reading a book.",["Bücher"],"n"),
    W("Tisch","noun","table","طاولة","Der Kaffee steht auf dem Tisch.","The coffee is on the table.",["Tische"],"m"),
    W("Kaffee","noun","coffee","قهوة","Ich trinke morgens Kaffee.","I drink coffee in the morning.",[],"m"),
    W("Wasser","noun","water","ماء","Ein Glas Wasser, bitte.","A glass of water, please.",[],"n"),
    W("Brot","noun","bread","خبز","Ich kaufe jeden Tag Brot.","I buy bread every day.",["Brote"],"n"),
    W("Arbeit","noun","work","عمل","Meine Arbeit ist interessant.","My work is interesting.",[],"f"),
    W("Stadt","noun","city, town","مدينة","Berlin ist eine große Stadt.","Berlin is a big city.",["Städte"],"f"),
    W("Tag","noun","day","يوم","Heute ist ein schöner Tag.","Today is a lovely day.",["Tage"],"m"),
    W("Nacht","noun","night","ليل","Ich lerne in der Nacht.","I study at night.",["Nächte"],"f"),
    W("groß","adj","big, tall","كبير","Das ist ein großes Haus.","That's a big house.",["große","großen","großer","großes"],ant=["klein"]),
    W("klein","adj","small","صغير","Ich habe einen kleinen Hund.","I have a small dog.",["kleine","kleinen","kleiner","kleines"],ant=["groß"]),
    W("gut","adj","good","جيد","Das ist ein sehr gutes Buch.","That's a very good book.",["gute","guten","guter","gutes"],ant=["schlecht"]),
    W("sprechen","verb","to speak","يتكلّم","Ich spreche Deutsch und Arabisch.","I speak German and Arabic.",["spreche","sprichst","spricht","sprecht"]),
    W("arbeiten","verb","to work","يعمل","Sie arbeitet in einem Krankenhaus.","She works in a hospital.",["arbeite","arbeitest","arbeitet"]),
    W("lernen","verb","to learn, to study","يتعلّم / يدرس","Wir lernen zusammen.","We study together.",["lerne","lernst","lernt"]),
    W("kaufen","verb","to buy","يشتري","Ich kaufe Obst auf dem Markt.","I buy fruit at the market.",["kaufe","kaufst","kauft"]),
    W("Markt","noun","market","سوق","Der Markt öffnet um acht.","The market opens at eight.",["Märkte"],"m"),
    W("Laden","noun","shop","متجر","Der Laden ist geschlossen.","The shop is closed.",["Läden"],"m"),
]
de_topics = [
    T("sein_present","The verb sein (present)","الفعل sein في المضارع",
      "Use sein to say who someone is, where they are from and to describe them. Forms: ich bin, du bist, er/sie/es ist, wir sind, ihr seid, sie/Sie sind.",
      "يُستخدم الفعل sein للتعريف بالشخص وبلده ووصفه. تصريفه: ich bin، du bist، er/sie/es ist، wir sind، ihr seid، sie/Sie sind.",
      [["Ich bin aus Kairo.","I am from Cairo."],["Sie ist Ärztin.","She is a doctor."],["Wir sind Freunde.","We are friends."]]),
    T("article_gender","Articles: der, die, das","أدوات التعريف der وdie وdas",
      "German nouns have three genders: der (masculine), die (feminine), das (neuter). Learn every noun together with its article. Indefinite: ein (masculine/neuter), eine (feminine). Plural: die.",
      "للأسماء الألمانية ثلاثة أجناس: der للمذكر وdie للمؤنث وdas للمحايد. احفظ كل اسم مع أداته. أداة التنكير: ein للمذكر والمحايد وeine للمؤنث. وفي الجمع: die.",
      [["der Tisch","the table"],["die Stadt","the city"],["das Buch","the book"]]),
    T("present_regular","Present tense of regular verbs","المضارع للأفعال المنتظمة",
      "Take the stem and add -e, -st, -t, -en, -t, -en: lernen → ich lerne, du lernst, er lernt, wir lernen, ihr lernt, sie lernen. Some verbs change their vowel with du and er/sie/es: sprechen → du sprichst, er spricht.",
      "خذ جذر الفعل وأضف -e، -st، -t، -en، -t، -en. مثال: lernen ← ich lerne، du lernst، er lernt، wir lernen. وبعض الأفعال يتغير حرفها المتحرك مع du وer/sie/es: sprechen ← du sprichst، er spricht.",
      [["Ich lerne Deutsch.","I'm learning German."],["Arbeitest du hier?","Do you work here?"],["Er spricht Englisch.","He speaks English."]]),
    T("gern_moegen","Likes with gern and mögen","التعبير عمّا تحبه بـ gern وmögen",
      "Use a verb + gern to say you like doing something: Ich trinke gern Kaffee. Use mögen + noun to say you like something: Ich mag Pizza (ich mag, du magst, er mag, wir mögen).",
      "استخدم الفعل مع gern لتقول إنك تحب فعل شيء: Ich trinke gern Kaffee. واستخدم mögen مع الاسم لتقول إنك تحب شيئًا: Ich mag Pizza (ich mag، du magst، er mag، wir mögen).",
      [["Ich trinke gern Kaffee.","I like drinking coffee."],["Wir mögen Bücher.","We like books."],["Spielst du gern Fußball?","Do you like playing football?"]]),
]
de_ex = [
    cloze("de-a1-sein-cloze","sein_present",[("Ich ___ aus Ägypten.","bin"),("Du ___ Student, oder?","bist"),("Wir ___ Freunde.","sind"),("Anna und Paul ___ Ärzte.","sind")]),
    mcq("de-a1-articles-mcq",[("___ Haus ist groß.",["Das","Der","Die","Den"]),("___ Tisch ist klein.",["Der","Die","Das","Dem"]),("___ Stadt ist sehr schön.",["Die","Der","Das","Den"]),("Ich habe ___ Buch.",["ein","eine","einen","einer"])],"article_gender"),
    cloze("de-a1-present-cloze","present_regular",[("Ich ___ (lernen) Deutsch.","lerne"),("Anna ___ (arbeiten) in einem Laden.","arbeitet"),("Wir ___ (kaufen) jeden Tag Brot.","kaufen"),("Du ___ (sprechen) sehr gut Englisch.","sprichst")]),
    mcq("de-a1-gern-mcq",[("Ich ___ Pizza.",["mag","mögen","magst","mögt"]),("Wir ___ Bücher.",["mögen","mag","magst","mögt"]),("Spielst du ___ Fußball?",["gern","sehr","groß","kleine"]),("Er ___ Kaffee.",["mag","mögen","magst","mögt"])],"gern_moegen"),
    mcq("de-a1-vocab-mcq",[("Ich trinke ein Glas ___.",["Wasser","Brot","Tisch","Buch"]),("Das Gegenteil von „groß“ ist ___.",["klein","gut","hoch","neu"]),("Ich kaufe Obst auf dem ___.",["Markt","Buch","Tisch","Laden"]),("Ich lese ein ___ vor dem Schlafen.",["Buch","Brot","Wasser","Haus"]),("Morgens trinke ich einen ___.",["Kaffee","Tisch","Markt","Laden"])],skill="vocabulary"),
    order("de-a1-order",[["Ich","bin","aus","Deutschland"],["Wir","trinken","gern","Kaffee"],["Sie","arbeitet","in","einem","Laden"]]),
    reading("de-a1-read-1",{"en":"Lena's family","ar":"عائلة لينا"},"Ich heiße Lena und wohne mit meiner Familie in Köln. Mein Vater arbeitet in einem Krankenhaus und meine Mutter ist Lehrerin. Ich habe einen kleinen Bruder, Paul. Samstags kaufen wir Brot und Obst auf dem Markt.",
      [("Wo wohnt Lena?",["In Köln","In Berlin","In einem Krankenhaus","Auf dem Markt"]),("Was macht Lenas Mutter?",["Sie ist Lehrerin","Sie arbeitet in einem Krankenhaus","Sie arbeitet auf dem Markt","Sie ist Studentin"]),("Was kaufen sie samstags?",["Brot und Obst","Bücher","Kaffee und Wasser","Kleidung"])]),
    reading("de-a2-read-2",{"en":"A new job","ar":"عمل جديد"},"Daniel hat am Montag eine neue Stelle angefangen. Früher hat er in einem kleinen Laden gearbeitet, aber jetzt arbeitet er in einem Büro im Stadtzentrum. Am ersten Tag war er nervös, aber seine Kollegen waren sehr freundlich. Jetzt fährt er mit der U-Bahn zur Arbeit und braucht zwanzig Minuten.",
      [("Wo hat Daniel früher gearbeitet?",["In einem kleinen Laden","In einem Büro","In der U-Bahn","Im Stadtzentrum"]),("Wie hat er sich am ersten Tag gefühlt?",["Nervös","Müde","Gelangweilt","Wütend"]),("Wie lange braucht er zur Arbeit?",["Zwanzig Minuten","Eine Stunde","Zehn Minuten","Zwei Stunden"])],level="A2"),
    reading("de-a1-listen-1",{"en":"At the café","ar":"في المقهى"},"Guten Morgen. Ich hätte gern einen Milchkaffee und ein Käsebrötchen, bitte. Was macht das? Das macht vier Euro fünfzig.",
      [("Was trinkt die Person?",["Einen Milchkaffee","Einen Tee","Wasser","Orangensaft"]),("Was isst die Person?",["Ein Käsebrötchen","Obst","Einen Salat","Nichts"]),("Wie viel kostet es?",["Vier Euro fünfzig","Fünf Euro","Vier Euro","Drei Euro fünfzig"])],kind="listening"),
    dictation("de-a1-dictation",["Ich heiße Anna und komme aus Wien.","Mein Haus ist klein.","Wir lernen am Abend."]),
]
packs["de"] = {"language":"de","topics":de_topics,"words":de_words,"exercises":de_ex,"course":course("de","German","الألمانية",[t["slug"] for t in de_topics],[["hallo","tschüss","danke","Freund","Familie","Stadt"],["Haus","Buch","Tisch","Kaffee","Wasser","Brot","groß","klein"],["sprechen","arbeiten","lernen","kaufen","Arbeit","Tag","Nacht"],["gut","Markt","Laden"]],[e["key"] for e in de_ex])}

# ---------------------------------------------------------------- Italian
it_words = [
    W("ciao","interj","hi, bye","مرحبًا / وداعًا","Ciao, come stai?","Hi, how are you?"),
    W("arrivederci","interj","goodbye","مع السلامة","Arrivederci, a domani!","Goodbye, see you tomorrow!"),
    W("grazie","interj","thank you","شكرًا","Grazie mille!","Thanks a lot!"),
    W("amico","noun","friend","صديق","Il mio amico abita a Roma.","My friend lives in Rome.",["amici","amica","amiche"],"m"),
    W("casa","noun","house, home","بيت","La mia casa è piccola.","My house is small.",["case"],"f"),
    W("famiglia","noun","family","عائلة","La mia famiglia è grande.","My family is big.",["famiglie"],"f"),
    W("libro","noun","book","كتاب","Leggo un libro.","I'm reading a book.",["libri"],"m"),
    W("tavolo","noun","table","طاولة","Il caffè è sul tavolo.","The coffee is on the table.",["tavoli"],"m"),
    W("caffè","noun","coffee","قهوة","Prendo un caffè la mattina.","I have a coffee in the morning.",[],"m"),
    W("acqua","noun","water","ماء","Vorrei un bicchiere d'acqua.","I'd like a glass of water.",["acque"],"f"),
    W("pane","noun","bread","خبز","Compro il pane ogni giorno.","I buy bread every day.",["pani"],"m"),
    W("lavoro","noun","work, job","عمل","Il mio lavoro è interessante.","My job is interesting.",["lavori"],"m"),
    W("città","noun","city","مدينة","Milano è una grande città.","Milan is a big city.",[],"f"),
    W("giorno","noun","day","يوم","Oggi è un bel giorno.","Today is a lovely day.",["giorni"],"m"),
    W("notte","noun","night","ليل","Studio di notte.","I study at night.",["notti"],"f"),
    W("grande","adj","big","كبير","È una casa grande.","It's a big house.",["grandi"],ant=["piccolo"]),
    W("piccolo","adj","small","صغير","Ho un cane piccolo.","I have a small dog.",["piccola","piccoli","piccole"],ant=["grande"]),
    W("buono","adj","good","جيد","È un libro molto buono.","It's a very good book.",["buona","buoni","buone","buon"],ant=["cattivo"]),
    W("parlare","verb","to speak","يتكلّم","Parlo italiano e arabo.","I speak Italian and Arabic.",["parlo","parli","parla","parliamo","parlate","parlano"]),
    W("lavorare","verb","to work","يعمل","Lavora in un ospedale.","She works in a hospital.",["lavori","lavora","lavoriamo","lavorate","lavorano"]),
    W("studiare","verb","to study","يدرس","Studiamo insieme.","We study together.",["studi","studia","studiamo","studiate","studiano"]),
    W("comprare","verb","to buy","يشتري","Compro la frutta al mercato.","I buy fruit at the market.",["compro","compri","compra","compriamo","comprate","comprano"]),
    W("mercato","noun","market","سوق","Il mercato apre alle otto.","The market opens at eight.",["mercati"],"m"),
    W("negozio","noun","shop","متجر","Il negozio è chiuso.","The shop is closed.",["negozi"],"m"),
]
it_topics = [
    T("essere_present","The verb essere (present)","الفعل essere في المضارع",
      "Use essere to say who someone is, where they are from and to describe them. Forms: io sono, tu sei, lui/lei è, noi siamo, voi siete, loro sono.",
      "يُستخدم الفعل essere للتعريف بالشخص وبلده ووصفه. تصريفه: io sono، tu sei، lui/lei è، noi siamo، voi siete، loro sono.",
      [["Sono di Roma.","I am from Rome."],["Lei è medico.","She is a doctor."],["Siamo amici.","We are friends."]]),
    T("article_gender","Articles and gender","أدوات التعريف والتذكير والتأنيث",
      "Italian nouns are masculine (often -o) or feminine (often -a). Masculine: il/un, lo/uno before s+consonant or z. Feminine: la/una. Plural: i/gli (masculine), le (feminine).",
      "الأسماء الإيطالية مذكرة (غالبًا تنتهي بـ -o) أو مؤنثة (غالبًا تنتهي بـ -a). للمذكر: il/un، وlo/uno قبل s مع ساكن أو z. للمؤنث: la/una. وفي الجمع: i/gli للمذكر وle للمؤنث.",
      [["il libro","the book"],["la casa","the house"],["i tavoli","the tables"]]),
    T("present_are","Present tense of -are verbs","المضارع للأفعال المنتهية بـ -are",
      "Remove -are and add -o, -i, -a, -iamo, -ate, -ano. Parlare → parlo, parli, parla, parliamo, parlate, parlano.",
      "احذف -are وأضف -o، -i، -a، -iamo، -ate، -ano. مثال: parlare ← parlo، parli، parla، parliamo، parlate، parlano.",
      [["Parlo italiano.","I speak Italian."],["Lavori qui?","Do you work here?"],["Studiano molto.","They study a lot."]]),
    T("piacere","Saying what you like with piacere","التعبير عمّا تحبه بالفعل piacere",
      "Piacere agrees with the thing that is liked: mi piace + singular noun or verb, mi piacciono + plural noun. The person is shown with mi, ti, gli/le, ci, vi, gli.",
      "يتطابق الفعل piacere مع الشيء المحبوب: mi piace مع المفرد أو الفعل، وmi piacciono مع الجمع. ويُعبَّر عن الشخص بـ mi وti وgli/le وci وvi وgli.",
      [["Mi piace il caffè.","I like coffee."],["Ci piacciono i libri.","We like books."],["Ti piace ballare?","Do you like dancing?"]]),
]
it_ex = [
    cloze("it-a1-essere-cloze","essere_present",[("Io ___ di Roma.","sono"),("Tu ___ studente?","sei"),("Noi ___ amici.","siamo"),("Loro ___ medici.","sono")]),
    mcq("it-a1-articles-mcq",[("___ casa è grande.",["La","Il","Lo","I"]),("Ho ___ libro.",["un","una","uno","un'"]),("___ tavoli sono piccoli.",["I","Il","Le","Gli"]),("È ___ città bellissima.",["una","un","uno","le"])],"article_gender"),
    cloze("it-a1-are-cloze","present_are",[("Io ___ (parlare) italiano.","parlo"),("Lei ___ (lavorare) in un negozio.","lavora"),("Noi ___ (studiare) la sera.","studiamo"),("Voi ___ (comprare) il pane?","comprate")]),
    mcq("it-a1-piacere-mcq",[("Mi ___ il caffè.",["piace","piacciono","piaccio","piaci"]),("Ci ___ i libri.",["piacciono","piace","piacciamo","piacere"]),("Ti ___ ballare?",["piace","piacciono","piaci","piaccio"]),("A lei ___ piacciono le città grandi.",["le","la","si","lo"])],"piacere"),
    mcq("it-a1-vocab-mcq",[("Bevo un bicchiere d'___.",["acqua","pane","tavolo","libro"]),("Il contrario di «grande» è ___.",["piccolo","buono","alto","nuovo"]),("Compro la frutta al ___.",["mercato","libro","tavolo","lavoro"]),("Leggo un ___ la sera.",["libro","pane","caffè","giorno"]),("La mattina prendo un ___.",["caffè","tavolo","negozio","libro"])],skill="vocabulary"),
    order("it-a1-order",[["Io","sono","di","Milano"],["Ci","piace","il","caffè"],["Lei","lavora","in","un","negozio"]]),
    reading("it-a1-read-1",{"en":"Giulia's family","ar":"عائلة جوليا"},"Mi chiamo Giulia e abito a Firenze con la mia famiglia. Mio padre lavora in un ospedale e mia madre è insegnante. Ho un fratello piccolo, Marco. Il sabato compriamo il pane e la frutta al mercato.",
      [("Dove abita Giulia?",["A Firenze","A Roma","In un ospedale","Al mercato"]),("Che lavoro fa la madre di Giulia?",["È insegnante","Lavora in un ospedale","Lavora al mercato","È studentessa"]),("Che cosa comprano il sabato?",["Il pane e la frutta","Dei libri","Caffè e acqua","Dei vestiti"])]),
    reading("it-a2-read-2",{"en":"A new job","ar":"عمل جديد"},"Daniele ha cominciato un nuovo lavoro lunedì. Prima lavorava in un piccolo negozio, ma ora lavora in un ufficio in centro. Il primo giorno era nervoso, ma i colleghi sono stati molto gentili. Adesso va al lavoro in metropolitana e ci mette venti minuti.",
      [("Dove lavorava Daniele prima?",["In un piccolo negozio","In un ufficio","In metropolitana","In centro"]),("Come si sentiva il primo giorno?",["Nervoso","Stanco","Annoiato","Arrabbiato"]),("Quanto ci mette per arrivare al lavoro?",["Venti minuti","Un'ora","Dieci minuti","Due ore"])],level="A2"),
    reading("it-a1-listen-1",{"en":"At the café","ar":"في المقهى"},"Buongiorno. Vorrei un cappuccino e un cornetto, per favore. Quant'è? Sono quattro euro e cinquanta.",
      [("Che cosa beve la persona?",["Un cappuccino","Un tè","Dell'acqua","Un succo d'arancia"]),("Che cosa mangia?",["Un cornetto","Della frutta","Un'insalata","Niente"]),("Quanto costa?",["Quattro euro e cinquanta","Cinque euro","Quattro euro","Tre euro e cinquanta"])],kind="listening"),
    dictation("it-a1-dictation",["Mi chiamo Anna e sono di Napoli.","La mia casa è piccola.","Noi studiamo la sera."]),
]
packs["it"] = {"language":"it","topics":it_topics,"words":it_words,"exercises":it_ex,"course":course("it","Italian","الإيطالية",[t["slug"] for t in it_topics],[["ciao","arrivederci","grazie","amico","famiglia","città"],["casa","libro","tavolo","caffè","acqua","pane","grande","piccolo"],["parlare","lavorare","studiare","comprare","lavoro","giorno","notte"],["buono","mercato","negozio"]],[e["key"] for e in it_ex])}

# ---------------------------------------------------------------- Arabic (Modern Standard)
def al(*words):  # definite-article forms so words are recognised in running text
    out = []
    for w in words:
        out += [w, "ال" + w]
    return out
ar_words = [
    W("مرحبا","interj","hello","مرحبًا","مرحبا، كيف حالك؟","Hello, how are you?",["مرحبًا"]),
    W("مع السلامة","interj","goodbye","مع السلامة","مع السلامة، أراك غدًا.","Goodbye, see you tomorrow."),
    W("شكرا","interj","thank you","شكرًا","شكرا جزيلا!","Thank you very much!",["شكرًا"]),
    W("صديق","noun","friend","صديق","صديقي يسكن في دبي.","My friend lives in Dubai.",al("صديق","أصدقاء","صديقة")+["صديقي","صديقتي"],"m"),
    W("بيت","noun","house, home","بيت","بيتي صغير.","My house is small.",al("بيت","بيوت")+["بيتي"],"m"),
    W("عائلة","noun","family","عائلة","عائلتي كبيرة.","My family is big.",al("عائلة","عائلات")+["عائلتي"],"f"),
    W("كتاب","noun","book","كتاب","أقرأ كتابًا.","I'm reading a book.",al("كتاب","كتب")+["كتابا","كتابًا"],"m"),
    W("طاولة","noun","table","طاولة","القهوة على الطاولة.","The coffee is on the table.",al("طاولة"),"f"),
    W("قهوة","noun","coffee","قهوة","أشرب القهوة في الصباح.","I drink coffee in the morning.",al("قهوة"),"f"),
    W("ماء","noun","water","ماء","أريد كأس ماء.","I'd like a glass of water.",al("ماء")+["ماءً"],"m"),
    W("خبز","noun","bread","خبز","أشتري الخبز كل يوم.","I buy bread every day.",al("خبز"),"m"),
    W("عمل","noun","work, job","عمل","عملي ممتع.","My job is interesting.",al("عمل")+["عملي"],"m"),
    W("مدينة","noun","city","مدينة","القاهرة مدينة كبيرة.","Cairo is a big city.",al("مدينة","مدن"),"f"),
    W("يوم","noun","day","يوم","اليوم يوم جميل.","Today is a lovely day.",al("يوم","أيام"),"m"),
    W("ليل","noun","night","ليل","أدرس في الليل.","I study at night.",al("ليل","ليلة"),"m"),
    W("كبير","adj","big","كبير","هذا بيت كبير.","This is a big house.",al("كبير","كبيرة","كبار"),ant=["صغير"]),
    W("صغير","adj","small","صغير","عندي قطة صغيرة.","I have a small cat.",al("صغير","صغيرة","صغار"),ant=["كبير"]),
    W("جيد","adj","good","جيد","هذا كتاب جيد جدًا.","This is a very good book.",al("جيد","جيدة"),ant=["سيئ"]),
    W("تكلّم","verb","to speak","يتكلّم","أتكلّم العربية والإنجليزية.","I speak Arabic and English.",["أتكلم","تتكلم","يتكلم","نتكلم","يتكلمون","تكلم"]),
    W("عمل","verb","to work","يعمل","تعمل في مستشفى.","She works in a hospital.",["أعمل","تعمل","يعمل","نعمل","يعملون"]),
    W("درس","verb","to study","يدرس","ندرس معًا.","We study together.",["أدرس","تدرس","يدرس","ندرس","يدرسون"]),
    W("اشترى","verb","to buy","يشتري","أشتري الفاكهة من السوق.","I buy fruit at the market.",["أشتري","تشتري","يشتري","نشتري","يشترون"]),
    W("سوق","noun","market","سوق","يفتح السوق في الثامنة.","The market opens at eight.",al("سوق","أسواق"),"m"),
    W("متجر","noun","shop","متجر","المتجر مغلق.","The shop is closed.",al("متجر","متاجر"),"m"),
]
ar_topics = [
    T("nominal_sentence","The nominal sentence","الجملة الاسمية",
      "Arabic has no verb 'to be' in the present: a sentence can be just a subject and a description. أنا طالب = I am a student. The subject is usually definite or a pronoun.",
      "لا يوجد في العربية فعل كينونة في الزمن الحاضر، فالجملة قد تتكون من مبتدأ وخبر فقط: أنا طالب. ويكون المبتدأ عادةً معرفة أو ضميرًا.",
      [["أنا من مصر.","I am from Egypt."],["البيت كبير.","The house is big."],["نحن أصدقاء.","We are friends."]]),
    T("gender_agreement","Gender agreement","المطابقة في التذكير والتأنيث",
      "Most feminine nouns end in ة (taa marbuta). Adjectives and demonstratives agree with the noun: هذا بيت كبير but هذه مدينة كبيرة.",
      "تنتهي معظم الأسماء المؤنثة بالتاء المربوطة (ة). وتتطابق الصفات وأسماء الإشارة مع الاسم: هذا بيت كبير، وهذه مدينة كبيرة.",
      [["هذا كتاب جديد.","This is a new book."],["هذه طاولة صغيرة.","This is a small table."],["المدينة جميلة.","The city is beautiful."]]),
    T("present_tense","The present tense (al-mudari')","الفعل المضارع",
      "The present tense adds a prefix to the verb stem: أ for I, ت for you and she, ي for he, ن for we. درس → أدرس، تدرس، يدرس، ندرس.",
      "يُصاغ الفعل المضارع بإضافة حرف في أوله: الهمزة للمتكلم، والتاء للمخاطب والغائبة، والياء للغائب، والنون للمتكلمين. مثال: درس ← أدرس، تدرس، يدرس، ندرس.",
      [["أدرس العربية.","I study Arabic."],["هي تعمل في متجر.","She works in a shop."],["نحن نشتري الخبز.","We buy bread."]]),
    T("idafa","The possessive construction (idafa)","الإضافة",
      "To say 'the X of Y', put the two nouns side by side: the first has no ال and no tanween, the second is in the genitive. كتاب الطالب = the student's book.",
      "للتعبير عن الملكية نضع اسمين متتاليين: الأول (المضاف) بلا «ال» وبلا تنوين، والثاني (المضاف إليه) مجرور. مثال: كتابُ الطالبِ.",
      [["كتاب الطالب","the student's book"],["بيت العائلة","the family home"],["سوق المدينة","the city market"]]),
]
ar_ex = [
    mcq("ar-a1-nominal-mcq",[("أنا ___ القاهرة.",["من","عن","على","إلى"]),("___ طالبٌ.",["أنا","أنتِ","هي","نحن"]),("نحن ___.",["أصدقاء","صديقٌ","صديقةٌ","مدينةٌ"]),("هذه ___ كبيرة.",["مدينة","كتاب","بيت","يوم"])],"nominal_sentence"),
    mcq("ar-a1-gender-mcq",[("البيتُ ___.",["كبيرٌ","كبيرةٌ","كبيرون","كبيرات"]),("المدينةُ ___.",["جميلةٌ","جميلٌ","جميلون","جمال"]),("هذا ___ جديد.",["كتاب","طاولة","مدينة","عائلة"]),("هذه ___ صغيرة.",["طاولة","كتاب","بيت","خبز"])],"gender_agreement"),
    cloze("ar-a1-present-cloze","present_tense",[("أنا ___ (درس) العربية.","أدرس"),("هي ___ (عمل) في متجر.","تعمل"),("نحن ___ (اشترى) الخبز كل يوم.","نشتري"),("هو ___ (تكلّم) الإنجليزية.","يتكلم")]),
    mcq("ar-a1-idafa-mcq",[("بيتُ ___ كبير.",["العائلةِ","العائلةُ","العائلةَ","عائلةٌ"]),("هذا ___ الطالب.",["كتابُ","الكتابُ","كتابٌ","الكتابِ"]),("سوقُ ___ قريب.",["المدينةِ","المدينةُ","المدينةَ","مدينةٌ"]),("أشربُ فنجانَ ___.",["قهوةٍ","قهوةٌ","القهوةُ","قهوةً"])],"idafa"),
    mcq("ar-a1-vocab-mcq",[("أشرب كأسًا من ___.",["الماء","الخبز","الطاولة","الكتاب"]),("عكس «كبير» هو ___.",["صغير","جيد","طويل","جديد"]),("أشتري الفاكهة من ___.",["السوق","الكتاب","الليل","العمل"]),("أقرأ ___ قبل النوم.",["كتابًا","خبزًا","ماءً","يومًا"]),("في الصباح أشرب فنجان ___.",["قهوة","طاولة","مدينة","ليل"])],skill="vocabulary"),
    order("ar-a1-order",[["أنا","من","مصر"],["البيت","كبير","جدا"],["هي","تعمل","في","متجر"]]),
    reading("ar-a1-read-1",{"en":"Layla's family","ar":"عائلة ليلى"},"اسمي ليلى وأسكن في عمّان مع عائلتي. يعمل أبي في مستشفى وأمي معلمة. عندي أخ صغير اسمه سامي. يوم السبت نشتري الخبز والفاكهة من السوق.",
      [("أين تسكن ليلى؟",["في عمّان","في القاهرة","في مستشفى","في السوق"]),("ماذا تعمل أم ليلى؟",["هي معلمة","تعمل في مستشفى","تعمل في السوق","هي طالبة"]),("ماذا يشترون يوم السبت؟",["الخبز والفاكهة","كتبًا","القهوة والماء","ملابس"])]),
    reading("ar-a2-read-2",{"en":"A new job","ar":"عمل جديد"},"بدأ خالد عملًا جديدًا يوم الاثنين. كان يعمل من قبل في متجر صغير، لكنه الآن يعمل في مكتب في وسط المدينة. في اليوم الأول كان متوترًا، لكن زملاءه كانوا لطفاء جدًا. الآن يذهب إلى العمل بالمترو ويستغرق الطريق عشرين دقيقة.",
      [("أين كان خالد يعمل من قبل؟",["في متجر صغير","في مكتب","في المترو","في وسط المدينة"]),("كيف كان يشعر في اليوم الأول؟",["متوترًا","متعبًا","ضجرًا","غاضبًا"]),("كم يستغرق الطريق إلى العمل؟",["عشرين دقيقة","ساعة","عشر دقائق","ساعتين"])],level="A2"),
    reading("ar-a1-listen-1",{"en":"At the café","ar":"في المقهى"},"صباح الخير. أريد فنجان قهوة بالحليب وشطيرة جبن من فضلك. كم الحساب؟ الحساب أربعة دنانير ونصف.",
      [("ماذا يشرب الشخص؟",["قهوة بالحليب","شايًا","ماءً","عصير برتقال"]),("ماذا يأكل؟",["شطيرة جبن","فاكهة","سلطة","لا شيء"]),("كم الحساب؟",["أربعة دنانير ونصف","خمسة دنانير","أربعة دنانير","ثلاثة دنانير ونصف"])],kind="listening"),
    dictation("ar-a1-dictation",["اسمي سارة وأنا من الأردن.","بيتي صغير.","نحن ندرس في الليل."]),
]
# Arabic course: exercise order in lessons expects [l1a, l2a, l3a, l4a, vocab, order, read1, read2, listen, dictation]
packs["ar"] = {"language":"ar","topics":ar_topics,"words":ar_words,"exercises":ar_ex,
  "course":course("ar","Arabic","العربية",[t["slug"] for t in ar_topics],[["مرحبا","مع السلامة","شكرا","صديق","عائلة","مدينة"],["بيت","كتاب","طاولة","قهوة","ماء","خبز","كبير","صغير"],["تكلّم","درس","اشترى","يوم","ليل"],["جيد","سوق","متجر"]],[e["key"] for e in ar_ex],
     l4=({"en":"At the café and the market","ar":"في المقهى والسوق"},{"en":"Say who things belong to and understand a simple order in a café.","ar":"عبّر عن الملكية وافهم طلبًا بسيطًا في مقهى."}))}

for code, pack in packs.items():
    dictation_ex = next(e for e in pack["exercises"] if e["type"] == "dictation")
    sentences = [q["a"][0] for q in dictation_ex["questions"]] + [pack["topics"][0]["examples"][0][0], pack["topics"][2]["examples"][0][0]]
    pack["exercises"].append(repeat(f"{code}-a1-repeat", sentences))
    with open(f"{OUT}/{code}.json", "w", encoding="utf-8") as f:
        json.dump(pack, f, ensure_ascii=False, indent=1)
    print(code, len(pack["topics"]), "topics", len(pack["words"]), "words", len(pack["exercises"]), "exercises")
