/**
 * Google Apps Script для вкладок «Анкеты» и «Видеоуроки».
 *
 * 1) Вставь этот код в Расширения → Apps Script
 * 2) Один раз запусти setupSheet и setupLessonSheet (Run)
 * 3) Deploy → Manage deployments → Edit → New version
 *    Execute as: Me · Who has access: Anyone
 *
 * Анкеты (type отсутствует или не lesson):
 * A  Дата заявки · B Статус («🆕 Новая») · C ФИО · D Telegram · E Телефон
 * F–N Q1–Q9 · O Балл (скоринг) · P Комментарий · Q Результат
 * R Источник · S Метка
 *
 * Видеоуроки (type === 'lesson'):
 * A  Дата заявки · B Имя · C Телефон · D Источник
 */

var SHEET_NAME = 'Анкеты';
var LESSON_SHEET_NAME = 'Видеоуроки';

var HEADERS = [
  'Дата заявки',
  'Статус',
  'ФИО',
  'Telegram',
  'Телефон',
  'Q1 — Хочешь развиваться в сфере контента?',
  'Q2 — Из какого ты города?',
  'Q3 — Сколько тебе полных лет?',
  'Q4 — Чем ты сейчас занимаешься?',
  'Q5 — Был ли опыт в контенте?',
  'Q6 — Каков твой доход на данный момент?',
  'Q7 — Желаемый уровень заработка после продукта',
  'Q8 — Самая большая мечта',
  'Q9 — Готовность вложиться в обучение',
  'Балл',
  'Комментарий',
  'Результат',
  'Источник',
  'Метка',
];

var LESSON_HEADERS = [
  'Дата заявки',
  'Имя',
  'Телефон',
  'Источник',
];

function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);

    if (data.type === 'lesson') {
      return appendLesson_(data);
    }

    return appendQuiz_(data);
  } catch (err) {
    return json_({ success: false, error: String(err) });
  }
}

function appendQuiz_(data) {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(SHEET_NAME);
  if (!sheet) {
    return json_({ success: false, error: 'Лист «' + SHEET_NAME + '» не найден' });
  }

  // Редирект Apps Script иногда вызывает doPost второй раз с тем же телом.
  var duplicate = recentLeadRow_(sheet, 3, 3, 5, data.name, data.phone, 4, data.telegram);
  if (duplicate) {
    return json_({ success: true, duplicate: true, sheet: SHEET_NAME, row: duplicate });
  }

  // A..S. Статус = «🆕 Новая». Балл считаем в scoreQuiz_().
  // R Источник — URL входа или «прямой». S Метка — utm-параметры.
  var values = [
    new Date(),
    '🆕 Новая',
    data.name || '',
    data.telegram || '',
    data.phone || '',
    data.q1 || '',
    data.q2 || '',
    data.q3 || '',
    data.q4 || '',
    data.q5 || '',
    data.q6 || '',
    data.q7 || '',
    data.q8 || '',
    data.q9 || '',
    scoreQuiz_(data),
    '',
    '',
    data.source || 'прямой',
    data.label || '',
  ];

  var row = appendRowBypassingValidation_(sheet, values, 3); // C = ФИО

  return json_({ success: true, sheet: SHEET_NAME, row: row });
}

/**
 * Лид-скоринг по ответам Q1–Q9.
 * Город (Q2) и мечта (Q8) — текстовые: +1 если не пусто.
 */
function scoreQuiz_(data) {
  var score = 0;

  // Q1 — интерес
  if (data.q1 === 'Да') score += 2;

  // Q2 — город указан
  if (String(data.q2 || '').trim()) score += 1;

  // Q3 — возраст (целевой 18–35)
  var age = {
    'До 18': 0,
    '18–25': 2,
    '25–35': 2,
    '35–45': 1,
    'От 45': 0,
  };
  score += age[data.q3] || 0;

  // Q4 — занятость
  var occupation = {
    'Работаю официально': 2,
    'Развиваю своё дело': 3,
    'Учусь': 1,
    'Нахожусь в декрете': 1,
    'Не работаю': 0,
  };
  score += occupation[data.q4] || 0;

  // Q5 — опыт в контенте
  var exp = {
    'Не было, но хочу начать!': 2,
    'Был, но не дошло до результатов': 3,
    'Активно зарабатываю на контенте': 1,
  };
  score += exp[data.q5] || 0;

  // Q6 — текущий доход (платёжеспособность)
  var income = {
    'Не зарабатываю': 0,
    'От 100$ до 250$': 1,
    'От 250$ до 500$': 2,
    'От 500$ до 1000$': 3,
    'От 1000$ до 3000$': 3,
    '3000$ и выше': 2,
  };
  score += income[data.q6] || 0;

  // Q7 — амбиции по доходу
  var desired = {
    '250$–750$': 1,
    '750$–1.500$': 2,
    '1.500$–3.000$': 3,
    'Любой сумме буду рад(а)!': 1,
  };
  score += desired[data.q7] || 0;

  // Q8 — мечта написана
  if (String(data.q8 || '').trim().length >= 10) score += 1;

  // Q9 — готовность вложиться (самый весомый)
  var invest = {
    'Да, готов(а) рассмотреть': 5,
    'Сначала хочу узнать детали': 3,
    'Пока не рассматриваю вложения': 0,
  };
  score += invest[data.q9] || 0;

  return score;
}

function appendLesson_(data) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName(LESSON_SHEET_NAME);
  if (!sheet) {
    sheet = ss.insertSheet(LESSON_SHEET_NAME);
    sheet.getRange(1, 1, 1, LESSON_HEADERS.length).setValues([LESSON_HEADERS]);
  }

  var duplicate = recentLeadRow_(sheet, 2, 2, 3, data.name, data.phone, 0, '');
  if (duplicate) {
    return json_({ success: true, duplicate: true, sheet: LESSON_SHEET_NAME, row: duplicate });
  }

  var values = [
    new Date(),
    data.name || '',
    data.phone || '',
    data.source || 'landing',
  ];

  var row = appendRowBypassingValidation_(sheet, values, 2); // B = Имя

  return json_({ success: true, sheet: LESSON_SHEET_NAME, row: row });
}

/**
 * Первая свободная строка по ключевой колонке (ФИО / Имя).
 * Не используем голый getLastRow() — из‑за «хвоста» заявка улетает на 1100+.
 * Читаем чанками сверху, чтобы заполнять дыры по порядку.
 */
function findNextDataRow_(sheet, watchCol) {
  var last = sheet.getLastRow();
  if (last < 2) {
    return 2;
  }

  var chunkSize = 500;
  var row = 2;
  while (row <= last) {
    var num = Math.min(chunkSize, last - row + 1);
    var values = sheet.getRange(row, watchCol, num, 1).getValues();
    for (var i = 0; i < values.length; i++) {
      var v = values[i][0];
      if (v === '' || v === null) {
        return row + i;
      }
    }
    row += num;
  }

  return last + 1;
}

/**
 * Та же заявка уже лежит в последних строках перед первой пустой.
 * Окно — 90 секунд, чтобы повторный вызов вебхука не задвоил строку.
 * @return {number} номер строки или 0
 */
function recentLeadRow_(sheet, watchCol, nameCol, phoneCol, name, phone, tgCol, telegram) {
  var next = findNextDataRow_(sheet, watchCol);
  var start = Math.max(2, next - 8);
  var height = next - start;
  if (height < 1) return 0;

  var width = Math.max(nameCol, phoneCol, tgCol || 0, 1);
  var rows = sheet.getRange(start, 1, height, width).getValues();
  var now = Date.now();
  var nameNorm = String(name || '').trim();
  var phoneNorm = String(phone || '').replace(/\D/g, '');
  var tgNorm = String(telegram || '').trim();

  for (var i = rows.length - 1; i >= 0; i--) {
    var row = rows[i];
    if (String(row[nameCol - 1] || '').trim() !== nameNorm) continue;
    if (String(row[phoneCol - 1] || '').replace(/\D/g, '') !== phoneNorm) continue;
    if (tgCol && String(row[tgCol - 1] || '').trim() !== tgNorm) continue;
    var when = row[0];
    if (!(when instanceof Date)) continue;
    var age = now - when.getTime();
    if (age < 90 * 1000 && age > -60000) return start + i;
  }

  return 0;
}

/**
 * Быстрый append: первая свободная строка по watchCol, при необходимости расширяем лист.
 * Выпадающие списки (Статус и др.) копируем со строки выше —
 * иначе setValues пишет «голый» текст без dropdown.
 * На время записи снимаем validation (Q9 с «отклонять ввод»), потом возвращаем.
 */
function appendRowBypassingValidation_(sheet, values, watchCol) {
  var colCount = values.length;
  var nextRow = findNextDataRow_(sheet, watchCol || 1);

  if (nextRow > sheet.getMaxRows()) {
    sheet.insertRowsAfter(sheet.getMaxRows(), Math.max(100, nextRow - sheet.getMaxRows()));
  }
  if (colCount > sheet.getMaxColumns()) {
    sheet.insertColumnsAfter(sheet.getMaxColumns(), colCount - sheet.getMaxColumns());
  }

  var range = sheet.getRange(nextRow, 1, 1, colCount);

  // Протягиваем dropdown'и со строки выше на новую (B = Статус и т.д.)
  if (nextRow > 2) {
    sheet.getRange(nextRow - 1, 1, 1, colCount)
      .copyTo(range, SpreadsheetApp.CopyPasteType.PASTE_DATA_VALIDATION, false);
  }

  var rules = range.getDataValidations();
  range.clearDataValidations();
  range.setValues([values]);
  // Правило могли не вернуть — строка уже записана, вторую из-за этого не шлём.
  try {
    range.setDataValidations(rules);
  } catch (err) {}

  return nextRow;
}

/**
 * Запусти один раз вручную из редактора Apps Script.
 * Обновляет шапку под текущий квиз и чинит выпадающий список Q9
 * (значение с запятой нельзя задавать через «список элементов»).
 */
function setupSheet() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName(SHEET_NAME);
  if (!sheet) {
    throw new Error('Лист «' + SHEET_NAME + '» не найден');
  }

  sheet.getRange(1, 1, 1, HEADERS.length).setValues([HEADERS]);

  // Старые правила на Q1–Q9 часто ломают запись (особенно пункт с запятой).
  // getRange(row, column, numRows, numColumns) — число строк/колонок, не lastRow/lastCol.
  var maxRows = Math.max(sheet.getMaxRows(), 2);
  var dataRows = maxRows - 1;

  // Q1–Q9 = F:N, то есть ровно 9 колонок
  sheet.getRange(2, 6, dataRows, 9).clearDataValidations();

  var lists = ss.getSheetByName('Списки');
  if (!lists) {
    lists = ss.insertSheet('Списки');
  }

  lists.getRange('A1:A3').setValues([
    ['Да, готов(а) рассмотреть'],
    ['Сначала хочу узнать детали'],
    ['Пока не рассматриваю вложения'],
  ]);

  // Q9 = колонка N
  var rule = SpreadsheetApp.newDataValidation()
    .requireValueInRange(lists.getRange('A1:A3'), true)
    .setAllowInvalid(true)
    .setHelpText('Готовность вложиться в обучение')
    .build();

  sheet.getRange('N2:N' + maxRows).setDataValidation(rule);
}

/**
 * Один раз: создаёт/обновляет вкладку «Видеоуроки» под заявки с лендинга.
 */
function setupLessonSheet() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName(LESSON_SHEET_NAME);
  if (!sheet) {
    sheet = ss.insertSheet(LESSON_SHEET_NAME);
  }

  sheet.getRange(1, 1, 1, LESSON_HEADERS.length).setValues([LESSON_HEADERS]);
}

function json_(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
