<?php
declare(strict_types=1);

require_once __DIR__ . '/project_tree.php';

// One-time cleanup of duplicate CV projects. Runs once per database; the flag in profile records that it ran.
const DEDUPE_MIGRATION = 'migration_projects_dedupe_v1';
const DEDUPE_DROP_FOLDERS = ['weather_app_clean_architecture_with_bloc', 'classyar_app_7'];
const DEDUPE_MERGE_FROM = 'tobank_sdui_tobank_virtual_branch_of_gardeshgari_bank';
const DEDUPE_MERGE_INTO = 'tobank_sdui';

/** Folder name per project id, computed exactly as the IDE tree names them (duplicates get an id suffix). */
function folder_names(array $projects): array {
    $names = [];
    $used = [];
    foreach ($projects as $project) {
        $slug = project_slug($project);
        if (isset($used[$slug])) {
            $slug .= '_' . (int) $project['id'];
        }
        $used[$slug] = true;
        $names[(int) $project['id']] = $slug;
    }
    return $names;
}

/** Writes a line to data/migration.log. Logging must never block the migration. */
function migration_log(string $line): void {
    if (!is_dir(DATA_DIR)) {
        return;
    }
    @file_put_contents(DATA_DIR . '/migration.log', '[' . date('c') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Full backup of one project and its files and screenshots, as JSON, taken before anything is removed. */
function project_backup(PDO $pdo, int $id): string {
    $project = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $project->execute([$id]);
    $files = $pdo->prepare('SELECT * FROM project_files WHERE project_id = ?');
    $files->execute([$id]);
    $media = $pdo->prepare('SELECT * FROM project_media WHERE project_id = ?');
    $media->execute([$id]);
    return json_encode([
        'project' => $project->fetch(),
        'project_files' => $files->fetchAll(),
        'project_media' => $media->fetchAll(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function delete_project(PDO $pdo, int $id): void {
    $pdo->prepare('DELETE FROM project_files WHERE project_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM project_media WHERE project_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
}

/** Fills the target's empty fields from the duplicate, keeps the fuller text, moves its screenshots and files. */
function merge_project(PDO $pdo, int $fromId, int $intoId): void {
    $from = $pdo->query('SELECT * FROM projects WHERE id = ' . $fromId)->fetch();
    $into = $pdo->query('SELECT * FROM projects WHERE id = ' . $intoId)->fetch();

    $update = [];
    foreach (['summary', 'summary_fa'] as $field) {
        if (strlen((string) $from[$field]) > strlen((string) $into[$field])) {
            $update[$field] = $from[$field];
        }
    }
    foreach (['title_fa', 'url', 'github_url', 'appstore_url', 'playstore_url', 'bazaar_url'] as $field) {
        if (trim((string) $into[$field]) === '' && trim((string) $from[$field]) !== '') {
            $update[$field] = $from[$field];
        }
    }
    $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', $into['tags'] . ',' . $from['tags'])))));
    if ($tags !== array_filter(array_map('trim', explode(',', (string) $into['tags'])))) {
        $update['tags'] = implode(', ', $tags);
    }
    if ($update) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($update)));
        $stmt = $pdo->prepare("UPDATE projects SET $set WHERE id = :id");
        $stmt->execute($update + ['id' => $intoId]);
    }

    $pdo->prepare('UPDATE project_media SET project_id = ? WHERE project_id = ?')->execute([$intoId, $fromId]);
    $pdo->prepare('UPDATE project_files SET project_id = ? WHERE project_id = ? AND path NOT IN (SELECT path FROM project_files WHERE project_id = ?)')
        ->execute([$intoId, $fromId, $intoId]);
    delete_project($pdo, $fromId);
}

function run_migrations(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . DEDUPE_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $projects = $pdo->query('SELECT * FROM projects ORDER BY sort, id DESC')->fetchAll();
    $byName = array_flip(folder_names($projects));
    $report = [];

    $pdo->beginTransaction();
    try {
        foreach (DEDUPE_DROP_FOLDERS as $folder) {
            if (!isset($byName[$folder])) {
                $report[] = "no match for folder $folder";
                continue;
            }
            $id = (int) $byName[$folder];
            $report[] = "deleted $folder (id $id): " . project_backup($pdo, $id);
            delete_project($pdo, $id);
        }
        if (isset($byName[DEDUPE_MERGE_FROM], $byName[DEDUPE_MERGE_INTO])) {
            $fromId = (int) $byName[DEDUPE_MERGE_FROM];
            $intoId = (int) $byName[DEDUPE_MERGE_INTO];
            $report[] = "merged " . DEDUPE_MERGE_FROM . " (id $fromId) into " . DEDUPE_MERGE_INTO . " (id $intoId); removed row: " . project_backup($pdo, $fromId);
            merge_project($pdo, $fromId, $intoId);
        } else {
            $report[] = 'merge skipped: ' . DEDUPE_MERGE_FROM . ' or ' . DEDUPE_MERGE_INTO . ' not found';
        }
        $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . DEDUPE_MIGRATION . "', '1')");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        migration_log('FAILED: ' . $e->getMessage());
        throw $e;
    }
    foreach ($report as $line) {
        migration_log($line);
    }
}

// One-time update of the Weather App description, written from the app's source code.
const WEATHER_README_MIGRATION = 'migration_weather_readme_v1';
const WEATHER_FOLDER = 'weather_app';

const WEATHER_SUMMARY_EN = <<<'EN'
Flutter app for checking the weather in any city. University project (Farvardin 1404).

Features (from the source code):
- Search for a city by name (search page, WeatherAPI search endpoint).
- Current conditions and a multi-day forecast with hourly and daily details (WeatherAPI forecast endpoint; the number of days is a parameter).
- Widgets for UV index, sunrise, sunset and moonset, humidity, pressure and wind.
- Offline fallback: every successful fetch is cached with shared_preferences, and when there is no connection the last saved forecast is shown.
- Compass page (flutter_compass, with a permission request).
- Date conversion page between the Gregorian and Persian (Jalali) calendars (shamsi_date).
- Gemini page that sends a text prompt to Gemini (flutter_gemini).

Architecture: Clean Architecture with three layers. Data: remote and local data sources, the repository and models. Domain: entities, the repository interface and the GetForecast and SearchLocation use cases. Presentation: flutter_bloc with ForecastBloc and SearchBloc, plus pages and widgets. Dependencies are registered with get_it and injectable (lib/feature/service/locator_service.dart). Failures are returned as dartz Either values.

Main packages: flutter_bloc, get_it, injectable, dartz, equatable, http, envied, internet_connection_checker, shared_preferences, go_router, flutter_gemini, shamsi_date, flutter_compass, permission_handler, weather_icons and others listed in pubspec.yaml.

How to run: the weather API key is read from a .env file (variable KEY1) through envied. Run `dart run build_runner build` to generate the env and injection code, then `flutter run`.
EN;

const WEATHER_SUMMARY_FA = <<<'FA'
اپلیکیشن Flutter برای مشاهده‌ی وضعیت آب‌وهوای هر شهر. پروژه‌ی دانشگاهی (فروردین ۱۴۰۴).

قابلیت‌ها (بر اساس کد پروژه):
- جستجوی شهر بر اساس نام (صفحه‌ی جستجو، endpoint جستجوی WeatherAPI).
- وضعیت فعلی و پیش‌بینی چند روزه با جزئیات ساعتی و روزانه (endpoint پیش‌بینی WeatherAPI؛ تعداد روزها پارامتر است).
- ویجت‌هایی برای شاخص UV، طلوع و غروب خورشید و ماه، رطوبت، فشار و باد.
- پشتیبانی آفلاین: هر پاسخ موفق در shared_preferences ذخیره می‌شود و بدون اینترنت آخرین پیش‌بینی ذخیره‌شده نمایش داده می‌شود.
- صفحه‌ی قطب‌نما (با flutter_compass و درخواست مجوز).
- صفحه‌ی تبدیل تاریخ میلادی و شمسی (با shamsi_date).
- صفحه‌ی Gemini که یک پرسش متنی را برای Gemini ارسال می‌کند (با flutter_gemini).

معماری: Clean Architecture در سه لایه. data: منابع داده‌ی remote و local، repository و مدل‌ها. domain: entityها، interface مخزن و use caseهای GetForecast و SearchLocation. presentation: flutter_bloc با ForecastBloc و SearchBloc، همراه با صفحه‌ها و ویجت‌ها. وابستگی‌ها با get_it و injectable ثبت می‌شوند (lib/feature/service/locator_service.dart). خطاها به صورت Either از dartz برگردانده می‌شوند.

کتابخانه‌های اصلی: flutter_bloc، get_it، injectable، dartz، http، envied، shared_preferences، go_router، flutter_gemini، shamsi_date، flutter_compass، permission_handler، weather_icons و موارد دیگر در pubspec.yaml.

اجرا: کلید API آب‌وهوا از فایل .env (متغیر KEY1) با envied خوانده می‌شود. دستور dart run build_runner build را برای ساخت کدهای env و تزریق وابستگی اجرا کنید، سپس flutter run.
FA;

function run_content_migrations(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . WEATHER_README_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $projects = $pdo->query('SELECT * FROM projects ORDER BY sort, id DESC')->fetchAll();
    $byName = array_flip(folder_names($projects));
    if (!isset($byName[WEATHER_FOLDER])) {
        migration_log('no match for folder ' . WEATHER_FOLDER . ' (weather description not updated)');
    } else {
        $id = (int) $byName[WEATHER_FOLDER];
        migration_log('updated description of ' . WEATHER_FOLDER . " (id $id); previous row: " . project_backup($pdo, $id));
        $pdo->prepare('UPDATE projects SET summary = ?, summary_fa = ? WHERE id = ?')
            ->execute([WEATHER_SUMMARY_EN, WEATHER_SUMMARY_FA, $id]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . WEATHER_README_MIGRATION . "', '1')");
}

// One-time description update for Classyar, matched by folder name and logged the same way.
const CLASSYAR_README_MIGRATION = 'migration_classyar_readme_v2';
const CLASSYAR_FOLDER = 'classyar_app';

const CLASSYAR_SUMMARY_EN = <<<'EN'
Flutter app for teachers to manage their classes. Teachers keep a list of students and record daily attendance with four statuses: present, absent, late and excused. Each student can have a note, and attendance can be recorded for any day.

Features:
- Manage students: add, edit and delete.
- Record daily attendance, and change the date to record any day.
- Add a note for each student who is absent, late or excused.
- Persian (Shamsi) calendar and right-to-left interface.

Built with Flutter and Dart. Data is handled through an API, with Firebase for data management. Published on Cafebazaar.

Built for Azad Islamic University (Tehran West). Period: Mehr 1404.
EN;

const CLASSYAR_SUMMARY_FA = <<<'FA'
اپلیکیشن Flutter برای مدیریت کلاس‌ها توسط معلم. معلم فهرست دانش‌آموزان را نگه می‌دارد و حضور و غیاب روزانه را با چهار وضعیت ثبت می‌کند: حاضر، غایب، تأخیر و موجه. برای هر دانش‌آموز می‌توان یادداشت ثبت کرد و حضور و غیاب را برای هر روز ثبت کرد.

امکانات:
- مدیریت دانش‌آموزان: افزودن، ویرایش و حذف.
- ثبت حضور و غیاب روزانه و تغییر تاریخ برای ثبت هر روز.
- افزودن یادداشت برای هر دانش‌آموز غایب، دیرآمده یا موجه.
- تقویم شمسی و رابط کاربری راست‌به‌چپ.

ساخته‌شده با Flutter و Dart. داده‌ها از طریق API و با Firebase مدیریت می‌شوند. منتشر شده در کافه‌بازار.

ساخته‌شده برای دانشگاه آزاد اسلامی تهران غرب. بازه: مهر ۱۴۰۴.
FA;

function run_classyar_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . CLASSYAR_README_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $projects = $pdo->query('SELECT * FROM projects ORDER BY sort, id DESC')->fetchAll();
    $byName = array_flip(folder_names($projects));
    if (!isset($byName[CLASSYAR_FOLDER])) {
        migration_log('no match for folder ' . CLASSYAR_FOLDER . ' (classyar description not updated)');
    } else {
        $id = (int) $byName[CLASSYAR_FOLDER];
        migration_log('updated description of ' . CLASSYAR_FOLDER . " (id $id); previous row: " . project_backup($pdo, $id));
        $pdo->prepare('UPDATE projects SET summary = ?, summary_fa = ? WHERE id = ?')
            ->execute([CLASSYAR_SUMMARY_EN, CLASSYAR_SUMMARY_FA, $id]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . CLASSYAR_README_MIGRATION . "', '1')");
}

// One-time contact update: the phone number is replaced, and email and GitHub are filled only when empty. Logged with the previous values.
const CONTACT_MIGRATION = 'migration_contact_v1';

function run_contact_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . CONTACT_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $current = $pdo->query("SELECT k, v FROM profile WHERE k IN ('phone', 'email', 'github')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $set = ['phone' => '+989120734729'];
    foreach (['email' => 'banitabaparsa@gmail.com', 'github' => 'https://github.com/Sureiamdiamond'] as $k => $v) {
        if (trim((string) ($current[$k] ?? '')) === '') {
            $set[$k] = $v;
        }
    }
    migration_log('contact: previous values ' . json_encode($current, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    foreach ($set as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . CONTACT_MIGRATION . "', '1')");
}

// One-time replacement of the old education and experience seed rows with the entries from the CV, plus skills, languages and LinkedIn.
// The previous rows are logged in full before they are removed, so they can be restored from data/migration.log.
const CV_MIGRATION = 'migration_cv_v2';

function run_cv_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . CV_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $old = $pdo->query('SELECT * FROM timeline ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    migration_log('cv: replacing timeline rows ' . json_encode($old, JSON_UNESCAPED_UNICODE));
    $pdo->exec('DELETE FROM timeline');
    $ins = $pdo->prepare('INSERT INTO timeline (kind, title, title_fa, org, org_fa, city, city_fa, period, period_fa, website, details, details_fa, bullets, bullets_fa, sort)
        VALUES (:kind, :title, :title_fa, :org, :org_fa, :city, :city_fa, :period, :period_fa, :website, :details, :details_fa, :bullets, :bullets_fa, :sort)');
    foreach (cv_timeline_rows() as $row) {
        $ins->execute($row);
    }
    $current = $pdo->query("SELECT k, v FROM profile WHERE k IN ('skills', 'languages', 'linkedin')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('cv: previous profile values ' . json_encode($current, JSON_UNESCAPED_UNICODE));
    $set = [
        'skills' => "Dart | dart\nFlutter | flutter\nSDUI\nBloc\nGetX\nHttp / Dio\nPython | python\nGit | git\nGitHub | github\nGitLab | gitlab\nFigma | figma\nJira | jira\nFirebase | firebase",
        'languages' => "English | C1 · TTC, CAE\nفارسی | مادری",
        'linkedin' => 'https://www.linkedin.com/in/parsabanitaba/',
    ];
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    foreach ($set as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . CV_MIGRATION . "', '1')");
}

/** The five CV entries, newest first within each group. Dates and wording follow the CV; bullets are its duty lists. */
function cv_timeline_rows(): array {
    $bullets = [
        "همکاری در توسعه و نگه‌داری اپلیکیشن تحت نظارت توسعه‌دهندگان ارشد\nمشارکت در طراحی و پیاده‌سازی قابلیت‌های جدید در پروژه‌های واقعی\nحضور در جلسات بازبینی کد و رفع باگ‌های گزارش‌شده\nتحقیق و یادگیری مستمر درباره ابزارها و تکنولوژی‌های مرتبط\nهمکاری تیمی مؤثر و دریافت بازخورد جهت رشد حرفه‌ای\nانجام تست‌های کارایی و عیب‌یابی نرم‌افزار",
        "Helped develop and maintain an app under the supervision of senior developers\nContributed to designing and building new features in real projects\nTook part in code reviews and fixed reported bugs\nResearched and kept learning related tools and technologies\nWorked effectively in a team and took feedback to grow professionally\nRan performance tests and debugged software",
    ];
    $trainee = [
        "یادگیری اصول پایه‌ای توسعه فلاتر شامل Dart و Flutter\nکمک در توسعه و نگه‌داری اپلیکیشن‌های فلاتر در پروژه‌های واقعی\nهمکاری تیمی با توسعه‌دهندگان ارشد برای طراحی و پیاده‌سازی اپلیکیشن‌های مقیاس‌پذیر و کاربرپسند\nنوشتن کد با کیفیت و مستندسازی کامل\nمشارکت در جلسات بررسی کد و برطرف کردن باگ‌ها\nاطمینان از کیفیت نرم‌افزار از طریق تست‌های جامع و رفع اشکال\nپیاده‌سازی ویژگی‌های جدید و افزودن قابلیت‌های نوین به اپلیکیشن‌ها\nدنبال کردن آخرین تکنولوژی‌ها و روندهای توسعه فلاتر\nکمک به افزایش سرعت و بهبود عملکرد اپلیکیشن‌ها\nارائه کمک و راهنمایی به اعضای تیم در مواقع نیاز",
        "Learned the basics of Flutter development, including Dart and Flutter\nHelped develop and maintain Flutter apps in real projects\nWorked with senior developers to design and build scalable, user-friendly apps\nWrote high-quality, well-documented code\nTook part in code reviews and fixed bugs\nEnsured software quality through thorough tests and bug fixes\nBuilt new features and added new capabilities to apps\nKept up with the latest Flutter technologies and trends\nHelped improve app speed and performance\nProvided support and guidance to team members when needed",
    ];
    $flutter = [
        "زبان‌ها و فریم‌ورک‌ها: Dart، Flutter\nمدیریت وضعیت: BLoC، GetX؛ استفاده از Freezed برای مدل‌سازی داده و مدیریت ایونت‌ها و استیت‌ها\nمعماری نرم‌افزار: MVP، Clean Architecture، MVVM\nپروژه SDUI\nارتباط با سرور: کار با Dio و HTTP برای API Integration\nتم و ظاهر اپلیکیشن: پیاده‌سازی تم‌های پویا و سفارشی‌سازی کامل UI\nرابط کاربری: تبدیل طراحی‌های Figma به UI واکنش‌گرا\nچندزبانه‌سازی اپلیکیشن: تجربه در پیاده‌سازی Localization\nابزارهای تیمی و مدیریتی: Jira، Git (GitHub/GitLab)\nتوسعه تیمی: آشنایی با متدولوژی Agile و کار گروهی",
        "Languages and frameworks: Dart, Flutter\nState management: BLoC and GetX, with Freezed for data models and events and states\nSoftware architecture: MVP, Clean Architecture, MVVM\nSDUI project\nServer communication: Dio and HTTP for API integration\nApp theming: dynamic themes and full UI customisation\nUI: turning Figma designs into responsive UI\nLocalisation: experience implementing multi-language apps\nTeam tools: Jira, Git (GitHub and GitLab)\nTeamwork: familiar with Agile and group work",
    ];
    $ipam = ['org' => 'ایپام (ایده‌پرداز دانش نوین ارشم)', 'org_en' => 'IPAAM (Ideh Pardaz Danesh Novin Arsham)', 'website' => 'https://ipaam.ir/', 'city' => 'تهران', 'city_en' => 'Tehran'];
    $azad = ['website_w' => '', 'website_c' => ''];
    return [
        [
            'kind' => 'education', 'title' => 'Master of Science in Computer Engineering (software)', 'title_fa' => 'کارشناسی ارشد مهندسی کامپیوتر (گرایش نرم‌افزار)',
            'org' => 'Islamic Azad University, Central Tehran Branch', 'org_fa' => 'دانشگاه آزاد اسلامی واحد تهران مرکز',
            'city' => 'Tehran', 'city_fa' => 'تهران', 'period' => 'Oct 2025 – now', 'period_fa' => 'مهر ۱۴۰۴ – اکنون',
            'website' => $azad['website_c'], 'details' => 'Major: Software', 'details_fa' => 'گرایش: نرم‌افزار', 'bullets' => '', 'bullets_fa' => '', 'sort' => 3,
        ],
        [
            'kind' => 'education', 'title' => 'Bachelor of Science in Computer Engineering (software)', 'title_fa' => 'کارشناسی مهندسی کامپیوتر (گرایش نرم‌افزار)',
            'org' => 'Islamic Azad University, West Tehran Branch', 'org_fa' => 'دانشگاه آزاد اسلامی واحد تهران غرب',
            'city' => 'Tehran', 'city_fa' => 'تهران', 'period' => 'Oct 2021 – Oct 2025', 'period_fa' => 'مهر ۱۴۰۰ – مهر ۱۴۰۴',
            'website' => $azad['website_w'], 'details' => 'Major: Software', 'details_fa' => 'گرایش: نرم‌افزار', 'bullets' => '', 'bullets_fa' => '', 'sort' => 2,
        ],
        [
            'kind' => 'experience', 'title' => 'Flutter developer', 'title_fa' => 'برنامه‌نویس فلاتر',
            'org' => $ipam['org_en'], 'org_fa' => $ipam['org'], 'city' => $ipam['city_en'], 'city_fa' => $ipam['city'],
            'period' => 'Mar 2024 – now', 'period_fa' => 'فروردین ۱۴۰۳ – اکنون',
            'website' => $ipam['website'], 'details' => '', 'details_fa' => '', 'bullets' => $flutter[1], 'bullets_fa' => $flutter[0], 'sort' => 1,
        ],
        [
            'kind' => 'experience', 'title' => 'Programming trainee', 'title_fa' => 'کارآموز برنامه‌نویسی',
            'org' => $ipam['org_en'], 'org_fa' => $ipam['org'], 'city' => $ipam['city_en'], 'city_fa' => $ipam['city'],
            'period' => 'Nov 2023 – Mar 2024', 'period_fa' => 'آبان ۱۴۰۲ – فروردین ۱۴۰۳',
            'website' => $ipam['website'], 'details' => '', 'details_fa' => '', 'bullets' => $trainee[1], 'bullets_fa' => $trainee[0], 'sort' => 2,
        ],
        [
            'kind' => 'experience', 'title' => 'Intern (Codeyad project-based course)', 'title_fa' => 'کارآموز (دوره پروژه‌محور کدیاد)',
            'org' => 'Codeyad', 'org_fa' => 'کدیاد', 'city' => 'Tehran', 'city_fa' => 'تهران',
            'period' => 'Apr 2023 – Aug 2023', 'period_fa' => 'اردیبهشت ۱۴۰۲ – مرداد ۱۴۰۲',
            'website' => 'https://codeyad.com/', 'details' => '', 'details_fa' => '', 'bullets' => $bullets[1], 'bullets_fa' => $bullets[0], 'sort' => 3,
        ],
    ];
}

// One-time removal of the duty points from workplace entries: only organisation, position and years are shown.
// The removed text is logged in full first, so nothing is lost silently.
const EXPERIENCE_BULLETS_MIGRATION = 'migration_exp_bullets_v1';

function run_experience_bullets_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . EXPERIENCE_BULLETS_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $rows = $pdo->query("SELECT id, title, org, bullets, bullets_fa FROM timeline WHERE kind = 'experience' AND (TRIM(bullets) <> '' OR TRIM(bullets_fa) <> '')")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        migration_log('exp-bullets: removed ' . json_encode($rows, JSON_UNESCAPED_UNICODE));
        $pdo->exec("UPDATE timeline SET bullets = '', bullets_fa = '' WHERE kind = 'experience'");
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . EXPERIENCE_BULLETS_MIGRATION . "', '1')");
}

// Skills grouped under Flutter, with the less important ones behind the More button. Replaces the list left by the CV migration.
const SKILLS_MIGRATION = 'migration_skills_v1';
const SKILLS_LIST = "Dart | dart
Flutter | flutter
GetX | getx | parent:Flutter
Bloc | bloc | parent:Flutter
SDUI | sdui | parent:Flutter
Stac | stac | parent:Flutter
Riverpod | riverpod | parent:Flutter
Git | git
GitHub | github
GitLab | gitlab
Figma | figma
Jira | jira
Firebase | firebase
AI | ai
Claude | claude | parent:AI
Codex | codex | parent:AI
Antigravity | antigravity | parent:AI
Grok | grok | parent:AI";

// Same list for the Persian page: only the AI group name differs.
const SKILLS_FA_LIST = "Dart | dart
Flutter | flutter
GetX | getx | parent:Flutter
Bloc | bloc | parent:Flutter
SDUI | sdui | parent:Flutter
Stac | stac | parent:Flutter
Riverpod | riverpod | parent:Flutter
Git | git
GitHub | github
GitLab | gitlab
Figma | figma
Jira | jira
Firebase | firebase
هوش مصنوعی | ai
Claude | claude | parent:هوش مصنوعی
Codex | codex | parent:هوش مصنوعی
Antigravity | antigravity | parent:هوش مصنوعی
Grok | grok | parent:هوش مصنوعی";

function run_skills_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . SKILLS_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT v FROM profile WHERE k = 'skills'")->fetchColumn();
    migration_log('skills: previous value ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['skills', SKILLS_LIST]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . SKILLS_MIGRATION . "', '1')");
}

// Riverpod added under Flutter. Rewrites the skills list with the current SKILLS_LIST; the previous value is logged.
const SKILLS_V2_MIGRATION = 'migration_skills_v2';

function run_skills_v2_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . SKILLS_V2_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT v FROM profile WHERE k = 'skills'")->fetchColumn();
    migration_log('skills v2: previous value ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['skills', SKILLS_LIST]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . SKILLS_V2_MIGRATION . "', '1')");
}

// AI group added, Python removed, and a Persian skills list. The previous skills value is logged, so Python can be restored from there.
const SKILLS_AI_MIGRATION = 'migration_skills_v3';

function run_skills_v3_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . SKILLS_AI_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('skills', 'skills_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('skills v3: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['skills', SKILLS_LIST]);
    $stmt->execute(['skills_fa', SKILLS_FA_LIST]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . SKILLS_AI_MIGRATION . "', '1')");
}

// Icons for the AI group and Claude. Rewrites both skills lists; the previous values are logged.
const SKILLS_ICONS_MIGRATION = 'migration_skills_v4';

function run_skills_v4_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . SKILLS_ICONS_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('skills', 'skills_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('skills v4: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['skills', SKILLS_LIST]);
    $stmt->execute(['skills_fa', SKILLS_FA_LIST]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . SKILLS_ICONS_MIGRATION . "', '1')");
}

// Project icons supplied by the owner, matched by folder name. Both Tobank projects share one icon. Previous icon values are logged.
const PROJECT_ICONS_MIGRATION = 'migration_project_icons_v1';
const PROJECT_ICONS = [
    'classyar_app' => 'assets/img/projects/classyar.webp',
    'tobank' => 'assets/img/projects/tobank.webp',
    'tobank_sdui' => 'assets/img/projects/tobank.webp',
    'weather_app' => 'assets/img/projects/weather.jpg',
    'techblog' => 'assets/img/projects/techblog.png',
];

function run_project_icons_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . PROJECT_ICONS_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $projects = $pdo->query('SELECT * FROM projects ORDER BY sort, id DESC')->fetchAll();
    $names = folder_names($projects);
    $update = $pdo->prepare('UPDATE projects SET icon = ? WHERE id = ?');
    foreach ($names as $id => $slug) {
        if (!isset(PROJECT_ICONS[$slug])) {
            continue;
        }
        $previous = $pdo->query('SELECT icon FROM projects WHERE id = ' . (int) $id)->fetchColumn();
        migration_log("project icon $slug (id $id): previous icon " . json_encode($previous, JSON_UNESCAPED_UNICODE));
        $update->execute([PROJECT_ICONS[$slug], $id]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . PROJECT_ICONS_MIGRATION . "', '1')");
}

// Http / Dio removed, and the AI group gets its brand icons. Rewrites both skills lists; the previous values are logged, so the removed row can be restored from there.
const SKILLS_HTTP_MIGRATION = 'migration_skills_v5';

function run_skills_v5_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . SKILLS_HTTP_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('skills', 'skills_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('skills v5: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['skills', SKILLS_LIST]);
    $stmt->execute(['skills_fa', SKILLS_FA_LIST]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . SKILLS_HTTP_MIGRATION . "', '1')");
}

// Icons for GetX and SDUI in the Flutter panel. Rewrites both skills lists; the previous values are logged.
const SKILLS_ICONS_FLUTTER_MIGRATION = 'migration_skills_v6';

function run_skills_v6_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . SKILLS_ICONS_FLUTTER_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('skills', 'skills_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('skills v6: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['skills', SKILLS_LIST]);
    $stmt->execute(['skills_fa', SKILLS_FA_LIST]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . SKILLS_ICONS_FLUTTER_MIGRATION . "', '1')");
}

// AI moved to the end of the skills list. Rewrites both skills lists; the previous values are logged.
const SKILLS_ORDER_MIGRATION = 'migration_skills_v7';

function run_skills_v7_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . SKILLS_ORDER_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('skills', 'skills_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('skills v7: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['skills', SKILLS_LIST]);
    $stmt->execute(['skills_fa', SKILLS_FA_LIST]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . SKILLS_ORDER_MIGRATION . "', '1')");
}

// Workplace name spelled IPAAM (was IPAM / ایپام) in the stored timeline and profile text. Changed rows are logged with their previous values.
const IPAAM_MIGRATION = 'migration_ipaam_v1';

function ipaam_fix(string $text): string {
    return str_replace(['IPAM', 'ایپام'], ['IPAAM', 'ایپاام'], $text);
}

function run_ipaam_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . IPAAM_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $update = $pdo->prepare('UPDATE timeline SET org = ?, org_fa = ?, title = ?, title_fa = ?, details = ?, details_fa = ?, bullets = ?, bullets_fa = ? WHERE id = ?');
    foreach ($pdo->query('SELECT * FROM timeline')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fields = ['org', 'org_fa', 'title', 'title_fa', 'details', 'details_fa', 'bullets', 'bullets_fa'];
        $new = array_map(fn($f) => ipaam_fix((string) ($row[$f] ?? '')), $fields);
        if ($new !== array_map(fn($f) => (string) ($row[$f] ?? ''), $fields)) {
            migration_log("ipaam: timeline {$row['id']} previous row " . json_encode($row, JSON_UNESCAPED_UNICODE));
            $update->execute([...$new, $row['id']]);
        }
    }
    $stmt = $pdo->prepare('UPDATE profile SET v = ? WHERE k = ?');
    foreach ($pdo->query("SELECT k, v FROM profile WHERE v LIKE '%IPAM%' OR v LIKE '%ایپام%'")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        migration_log("ipaam: profile $k previous value " . json_encode($v, JSON_UNESCAPED_UNICODE));
        $stmt->execute([ipaam_fix((string) $v), $k]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . IPAAM_MIGRATION . "', '1')");
}

// Workplace name reads "IPAAM (full name)", short name first, in both languages. Changed rows are logged with their previous values.
const IPAAM_ORDER_MIGRATION = 'migration_ipaam_order_v1';

function run_ipaam_order_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . IPAAM_ORDER_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $update = $pdo->prepare('UPDATE timeline SET org = ?, org_fa = ? WHERE id = ?');
    foreach ($pdo->query('SELECT id, org, org_fa FROM timeline')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $org = (string) $row['org'] === 'Ideh Pardaz Danesh Novin Arsham (IPAAM)' ? 'IPAAM (Ideh Pardaz Danesh Novin Arsham)' : (string) $row['org'];
        $orgFa = str_contains((string) $row['org_fa'], 'دانش نوین') ? 'ایپاام (ایده‌پرداز دانش نوین ارشم)' : (string) $row['org_fa'];
        if ($org !== (string) $row['org'] || $orgFa !== (string) $row['org_fa']) {
            migration_log("ipaam order: timeline {$row['id']} previous org " . json_encode([$row['org'], $row['org_fa']], JSON_UNESCAPED_UNICODE));
            $update->execute([$org, $orgFa, $row['id']]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . IPAAM_ORDER_MIGRATION . "', '1')");
}

// The Codeyad internship ends in Mordad 1402 (Aug 2023), not Aban 1402 (Nov 2023). Only the exact old values are changed.
const CODEYAD_PERIOD_MIGRATION = 'migration_codeyad_period_v1';
function run_codeyad_period_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . CODEYAD_PERIOD_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $update = $pdo->prepare('UPDATE timeline SET period = ?, period_fa = ? WHERE id = ?');
    $old = ['Apr 2023 – Nov 2023', 'اردیبهشت ۱۴۰۲ – آبان ۱۴۰۲'];
    foreach ($pdo->query('SELECT id, period, period_fa FROM timeline WHERE org = \'Codeyad\'')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ([(string) $row['period'], (string) $row['period_fa']] === $old) {
            migration_log("codeyad period: timeline {$row['id']} previous period " . json_encode([$row['period'], $row['period_fa']], JSON_UNESCAPED_UNICODE));
            $update->execute(['Apr 2023 – Aug 2023', 'اردیبهشت ۱۴۰۲ – مرداد ۱۴۰۲', $row['id']]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . CODEYAD_PERIOD_MIGRATION . "', '1')");
}

// The Persian spelling of the workplace is ایپام (earlier migrations wrote ایپاام). Only the Persian fields and profile values change.
const IPAM_FA_SPELLING_MIGRATION = 'migration_ipam_fa_spelling_v1';
function run_ipam_fa_spelling_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . IPAM_FA_SPELLING_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $update = $pdo->prepare('UPDATE timeline SET org_fa = ?, title_fa = ?, details_fa = ?, bullets_fa = ? WHERE id = ?');
    $fields = ['org_fa', 'title_fa', 'details_fa', 'bullets_fa'];
    foreach ($pdo->query('SELECT * FROM timeline')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $old = array_map(fn($f) => (string) ($row[$f] ?? ''), $fields);
        $new = array_map(fn($t) => str_replace('ایپاام', 'ایپام', $t), $old);
        if ($new !== $old) {
            migration_log("ipam fa spelling: timeline {$row['id']} previous row " . json_encode($row, JSON_UNESCAPED_UNICODE));
            $update->execute([...$new, $row['id']]);
        }
    }
    $stmt = $pdo->prepare('UPDATE profile SET v = ? WHERE k = ?');
    foreach ($pdo->query("SELECT k, v FROM profile WHERE v LIKE '%ایپاام%'")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        migration_log("ipam fa spelling: profile $k previous value " . json_encode($v, JSON_UNESCAPED_UNICODE));
        $stmt->execute([str_replace('ایپاام', 'ایپام', (string) $v), $k]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . IPAM_FA_SPELLING_MIGRATION . "', '1')");
}

// The TechBlog project's GitHub link moves to the fork. Only the exact old link is changed; the project README in the IDE reads the same field.
const TECHBLOG_GITHUB_MIGRATION = 'migration_techblog_github_v1';
function run_techblog_github_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . TECHBLOG_GITHUB_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $old = 'https://github.com/Sureiamdiamond/TechBlog';
    $new = 'https://github.com/Sureiamdiamond/Techblog-Fork';
    foreach ($pdo->query('SELECT id, github_url FROM projects WHERE github_url = ' . $pdo->quote($old))->fetchAll(PDO::FETCH_ASSOC) as $row) {
        migration_log("techblog github: project {$row['id']} previous github_url " . json_encode($row['github_url'], JSON_UNESCAPED_UNICODE));
        $pdo->prepare('UPDATE projects SET github_url = ? WHERE id = ?')->execute([$new, $row['id']]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . TECHBLOG_GITHUB_MIGRATION . "', '1')");
}

// High-school diploma from Baqer al-Oloum High School, added below the two university entries. Only the name, the diploma title and the logo are known; dates, field and location are left empty for the owner to fill in on /admin.
const BAQER_DIPLOMA_MIGRATION = 'migration_baqer_diploma_v1';
function run_baqer_diploma_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . BAQER_DIPLOMA_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    if (!$pdo->query("SELECT 1 FROM timeline WHERE org = 'Baqer al-Oloum High School'")->fetchColumn()) {
        $pdo->prepare("INSERT INTO timeline (kind, title, title_fa, org, org_fa, city, city_fa, period, period_fa, website, details, details_fa, bullets, bullets_fa, logo, sort)
            VALUES ('education', 'High School Diploma', 'دیپلم', 'Baqer al-Oloum High School', 'دبیرستان باقرالعلوم', '', '', '', '', '', '', '', '', '', ?, 1)")
            ->execute([TIMELINE_LOGOS['Baqer al-Oloum High School']]);
        migration_log('baqer diploma: timeline entry added');
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . BAQER_DIPLOMA_MIGRATION . "', '1')");
}

// The high-school diploma is in mathematics: the entry's title names the diploma. Only the default title is changed.
const BAQER_MAJOR_MIGRATION = 'migration_baqer_major_v1';
function run_baqer_major_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . BAQER_MAJOR_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $update = $pdo->prepare("UPDATE timeline SET title = 'Diploma in Mathematics', title_fa = 'دیپلم ریاضی' WHERE id = ?");
    foreach ($pdo->query("SELECT id, title, title_fa FROM timeline WHERE org = 'Baqer al-Oloum High School' AND title = 'High School Diploma'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        migration_log("baqer major: timeline {$row['id']} previous title " . json_encode([$row['title'], $row['title_fa']], JSON_UNESCAPED_UNICODE));
        $update->execute([$row['id']]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . BAQER_MAJOR_MIGRATION . "', '1')");
}

// Universities have no website link on the CV. Only the university rows are cleared; the logged previous values keep the links.
const AZAD_WEBSITE_MIGRATION = 'migration_azad_website_v1';
function run_azad_website_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . AZAD_WEBSITE_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $update = $pdo->prepare("UPDATE timeline SET website = '' WHERE id = ?");
    foreach ($pdo->query("SELECT id, org, website FROM timeline WHERE kind = 'education' AND org LIKE 'Islamic Azad University%' AND TRIM(website) <> ''")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        migration_log("azad website: timeline {$row['id']} ({$row['org']}): previous website " . json_encode($row['website'], JSON_UNESCAPED_UNICODE));
        $update->execute([$row['id']]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . AZAD_WEBSITE_MIGRATION . "', '1')");
}

// Education reads oldest to newest from left to right: diploma, bachelor, master. The diploma's dates are filled in.
const EDU_ORDER_MIGRATION = 'migration_edu_order_dates_v1';
function run_edu_order_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . EDU_ORDER_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $order = [
        'Baqer al-Oloum High School' => 1,
        'Islamic Azad University, West Tehran Branch' => 2,
        'Islamic Azad University, Central Tehran Branch' => 3,
    ];
    $sort = $pdo->prepare('UPDATE timeline SET sort = ? WHERE id = ?');
    $dates = $pdo->prepare("UPDATE timeline SET period = 'Jul 2018 – Jul 2021', period_fa = 'تیر ۱۳۹۷ – تیر ۱۴۰۰' WHERE id = ?");
    foreach ($pdo->query("SELECT id, org, sort, period, period_fa FROM timeline WHERE kind = 'education'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($order[(string) $row['org']])) {
            continue;
        }
        if ((int) $row['sort'] !== $order[(string) $row['org']]) {
            migration_log("edu order: timeline {$row['id']} previous sort {$row['sort']}");
            $sort->execute([$order[(string) $row['org']], $row['id']]);
        }
        if ($row['org'] === 'Baqer al-Oloum High School' && trim((string) $row['period']) === '') {
            migration_log("edu dates: timeline {$row['id']} previous period " . json_encode([$row['period'], $row['period_fa']], JSON_UNESCAPED_UNICODE));
            $dates->execute([$row['id']]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . EDU_ORDER_MIGRATION . "', '1')");
}

// Tobank SDUI is part of the Tobank app, so it uses the same external links. Only empty fields are filled in.
const SDUI_LINKS_MIGRATION = 'migration_sdui_links_v1';
function run_sdui_links_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . SDUI_LINKS_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $fields = ['url', 'github_url', 'appstore_url', 'playstore_url', 'bazaar_url'];
    $source = $pdo->query("SELECT * FROM projects WHERE title = 'Tobank' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $target = $pdo->query("SELECT * FROM projects WHERE title = 'Tobank SDUI' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($source && $target) {
        $set = [];
        $values = [];
        foreach ($fields as $f) {
            if (trim((string) $source[$f]) !== '' && trim((string) $target[$f]) === '') {
                $set[] = "$f = ?";
                $values[] = $source[$f];
            }
        }
        if ($set) {
            migration_log("sdui links: project {$target['id']} previous links " . json_encode(array_intersect_key($target, array_flip($fields)), JSON_UNESCAPED_UNICODE));
            $values[] = $target['id'];
            $pdo->prepare('UPDATE projects SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($values);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . SDUI_LINKS_MIGRATION . "', '1')");
}

// Logos for the workplace, both universities and Codeyad, matched by organisation name. Previous logo values are logged.
const TIMELINE_LOGOS_MIGRATION = 'migration_timeline_logos_v2';
const TIMELINE_LOGOS = [
    'IPAAM (Ideh Pardaz Danesh Novin Arsham)' => 'assets/img/timeline/ipaam.webp',
    'Islamic Azad University, Central Tehran Branch' => 'assets/img/timeline/iau-ctb.png',
    'Islamic Azad University, West Tehran Branch' => 'assets/img/timeline/iau-west.png',
    'Codeyad' => 'assets/img/timeline/codeyad.png',
    'Baqer al-Oloum High School' => 'assets/img/timeline/baqer.svg',
];

function run_timeline_logos_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . TIMELINE_LOGOS_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $update = $pdo->prepare('UPDATE timeline SET logo = ? WHERE id = ?');
    foreach ($pdo->query('SELECT id, org, logo FROM timeline')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $logo = TIMELINE_LOGOS[(string) $row['org']] ?? null;
        if ($logo !== null && (string) $row['logo'] !== $logo) {
            migration_log("timeline logo {$row['id']} ({$row['org']}): previous logo " . json_encode($row['logo'], JSON_UNESCAPED_UNICODE));
            $update->execute([$logo, $row['id']]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . TIMELINE_LOGOS_MIGRATION . "', '1')");
}

// IPAAM logo re-keyed from the original JPG (white background removed); the rows point at the new file. Previous logo values are logged.
const IPAAM_LOGO_MIGRATION = 'migration_ipaam_logo_v2';
const IPAAM_LOGO = 'assets/img/timeline/ipaam-logo.webp';

function run_ipaam_logo_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . IPAAM_LOGO_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $update = $pdo->prepare('UPDATE timeline SET logo = ? WHERE id = ?');
    foreach ($pdo->query("SELECT id, org, logo FROM timeline WHERE org LIKE '%IPAAM%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((string) $row['logo'] !== IPAAM_LOGO) {
            migration_log("ipaam logo {$row['id']}: previous logo " . json_encode($row['logo'], JSON_UNESCAPED_UNICODE));
            $update->execute([IPAAM_LOGO, $row['id']]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . IPAAM_LOGO_MIGRATION . "', '1')");
}

// Tobank card gets the firebase and bloc tags next to dart and flutter. Only the Tobank row changes; existing tags are kept. Previous tags are logged.
const TOBANK_STACK_MIGRATION = 'migration_tobank_stack_v1';

function run_tobank_stack_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . TOBANK_STACK_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $row = $pdo->query("SELECT id, tags FROM projects WHERE title = 'Tobank' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $row['tags'])), 'strlen'));
        $have = array_map('strtolower', $tags);
        foreach (['firebase', 'bloc'] as $add) {
            if (!in_array($add, $have, true)) {
                $tags[] = $add;
            }
        }
        if ($tags !== array_values(array_filter(array_map('trim', explode(',', (string) $row['tags'])), 'strlen'))) {
            migration_log("tobank stack: project {$row['id']} previous tags " . json_encode($row['tags'], JSON_UNESCAPED_UNICODE));
            $pdo->prepare('UPDATE projects SET tags = ? WHERE id = ?')->execute([implode(', ', $tags), $row['id']]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . TOBANK_STACK_MIGRATION . "', '1')");
}

// TechBlog has one direct link, GitHub: the website and any extra links are cleared so its card does not show a dropdown. Previous values are logged.
const TECHBLOG_SINGLE_LINK_MIGRATION = 'migration_techblog_single_link_v1';

function run_techblog_single_link_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . TECHBLOG_SINGLE_LINK_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $row = $pdo->query("SELECT id, url, extra_links FROM projects WHERE LOWER(TRIM(title)) = 'techblog' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && (trim((string) $row['url']) !== '' || trim((string) $row['extra_links']) !== '')) {
        migration_log("techblog single link: project {$row['id']} previous " . json_encode(['url' => $row['url'], 'extra_links' => $row['extra_links']], JSON_UNESCAPED_UNICODE));
        $pdo->prepare("UPDATE projects SET url = '', extra_links = '' WHERE id = ?")->execute([$row['id']]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . TECHBLOG_SINGLE_LINK_MIGRATION . "', '1')");
}

// One TechBlog card carries both group descriptions, one per line, with the tags flutter and dart. The second card created for Codeyad is removed. Previous values are logged.
const TECHBLOG_TWO_LINES_MIGRATION = 'migration_techblog_two_lines_v1';
const TECHBLOG_EN_SUMMARY = "Group project for Professor Safari (Azad University): a podcast and tech-news application built with Flutter.\nGroup project by Sasan Safari, Flutter instructor (Codeyad): a podcast and tech-news application built with Flutter.";
const TECHBLOG_FA_SUMMARY = "پروژه گروهی برای استاد صفری (دانشگاه آزاد): اپلیکیشن پادکست و اخبار در حوزه تکنولوژی با فلاتر\nپروژه گروهی ساسان صفری مدرس فلاتر (کدیاد): اپلیکیشن پادکست و اخبار در حوزه تکنولوژی با فلاتر";

function run_techblog_two_lines_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . TECHBLOG_TWO_LINES_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $extra = $pdo->query("SELECT id, title_fa, summary, summary_fa FROM projects WHERE title = 'TechBlog (Codeyad)'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($extra as $row) {
        migration_log("techblog two lines: removed second card {$row['id']} previous " . json_encode($row, JSON_UNESCAPED_UNICODE));
        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$row['id']]);
    }
    $row = $pdo->query("SELECT id, summary, summary_fa, tags FROM projects WHERE LOWER(TRIM(title)) = 'techblog' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && ($row['summary'] !== TECHBLOG_EN_SUMMARY || $row['summary_fa'] !== TECHBLOG_FA_SUMMARY || $row['tags'] !== 'flutter, dart')) {
        migration_log("techblog two lines: project {$row['id']} previous " . json_encode(['summary' => $row['summary'], 'summary_fa' => $row['summary_fa'], 'tags' => $row['tags']], JSON_UNESCAPED_UNICODE));
        $pdo->prepare('UPDATE projects SET summary = ?, summary_fa = ?, tags = ? WHERE id = ?')->execute([TECHBLOG_EN_SUMMARY, TECHBLOG_FA_SUMMARY, 'flutter, dart', $row['id']]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . TECHBLOG_TWO_LINES_MIGRATION . "', '1')");
}

// The single TechBlog card keeps the Codeyad group project description only, in its grid position. Any other TechBlog row is removed. Previous values are logged.
const TECHBLOG_CODEYAD_ONLY_MIGRATION = 'migration_techblog_codeyad_only_v1';
const TECHBLOG_CODEYAD_EN = 'Group project by Sasan Safari, Flutter instructor (Codeyad): a podcast and tech-news application built with Flutter.';
const TECHBLOG_CODEYAD_FA = 'پروژه گروهی ساسان صفری مدرس فلاتر (کدیاد): اپلیکیشن پادکست و اخبار در حوزه تکنولوژی با فلاتر';

function run_techblog_codeyad_only_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . TECHBLOG_CODEYAD_ONLY_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $rows = $pdo->query("SELECT id, title, summary, summary_fa, tags FROM projects WHERE LOWER(TRIM(title)) = 'techblog' OR title = 'TechBlog (Codeyad)' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $keep = array_shift($rows);
    foreach ($rows as $row) {
        migration_log("techblog codeyad only: removed duplicate {$row['id']} previous " . json_encode($row, JSON_UNESCAPED_UNICODE));
        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$row['id']]);
    }
    if ($keep && ($keep['summary'] !== TECHBLOG_CODEYAD_EN || $keep['summary_fa'] !== TECHBLOG_CODEYAD_FA || $keep['tags'] !== 'flutter, dart')) {
        migration_log("techblog codeyad only: project {$keep['id']} previous " . json_encode(['summary' => $keep['summary'], 'summary_fa' => $keep['summary_fa'], 'tags' => $keep['tags']], JSON_UNESCAPED_UNICODE));
        $pdo->prepare('UPDATE projects SET summary = ?, summary_fa = ?, tags = ? WHERE id = ?')->execute([TECHBLOG_CODEYAD_EN, TECHBLOG_CODEYAD_FA, 'flutter, dart', $keep['id']]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . TECHBLOG_CODEYAD_ONLY_MIGRATION . "', '1')");
}

// FCE (B2) is added to the English certificates, in order FCE, CAE, TTC. Previous values are logged.
const LANGUAGES_FCE_MIGRATION = 'migration_languages_fce_v1';

function run_languages_fce_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . LANGUAGES_FCE_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('languages', 'languages_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('languages fce: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['languages', "English | Advanced (C1)\nFCE | | parent:English\nCAE | | parent:English\nTTC | | parent:English"]);
    $stmt->execute(['languages_fa', "انگلیسی | پیشرفته (C1)\nFCE | | parent:انگلیسی\nCAE | | parent:انگلیسی\nTTC | | parent:انگلیسی"]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . LANGUAGES_FCE_MIGRATION . "', '1')");
}

// Certificate pictures: CAE carries the C1 Advanced badge; FCE and TTC have none yet (monogram tiles). The C1 badge leaves the English level line. Previous values are logged.
const LANGUAGES_ICONS_MIGRATION = 'migration_languages_icons_v1';

function run_languages_icons_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . LANGUAGES_ICONS_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('languages', 'languages_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('languages icons: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['languages', "English | Advanced (C1)\nFCE | | parent:English\nCAE | | parent:English | icon:assets/img/lang/c1-advanced.webp\nTTC | | parent:English"]);
    $stmt->execute(['languages_fa', "انگلیسی | پیشرفته (C1)\nFCE | | parent:انگلیسی\nCAE | | parent:انگلیسی | icon:assets/img/lang/c1-advanced.webp\nTTC | | parent:انگلیسی"]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . LANGUAGES_ICONS_MIGRATION . "', '1')");
}

// The languages panel is plain text again: FCE, CAE and TTC as rows, with no pictures. Previous values are logged.
const LANGUAGES_PLAIN_MIGRATION = 'migration_languages_plain_v1';

function run_languages_plain_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . LANGUAGES_PLAIN_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('languages', 'languages_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('languages plain: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['languages', "English | Advanced (C1)\nFCE | | parent:English\nCAE | | parent:English\nTTC | | parent:English"]);
    $stmt->execute(['languages_fa', "انگلیسی | پیشرفته (C1)\nFCE | | parent:انگلیسی\nCAE | | parent:انگلیسی\nTTC | | parent:انگلیسی"]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . LANGUAGES_PLAIN_MIGRATION . "', '1')");
}

// About text: the middle-tone version, approved by the owner, in both languages. Previous values are logged.
const ABOUT_MIDDLE_MIGRATION = 'migration_about_middle_v1';
const ABOUT_MIDDLE_EN = <<<'TXT'
Hi, I'm Parsa Banitaba, a software engineer and Flutter developer. I'm on the mobile app team at IPAAM, working on the Tobank app for Tourism Bank, and I'm a Master's student in Software Engineering. I care about clean architecture and solid state management (Bloc, GetX), and I like shipping apps that feel smooth and are easy to maintain. If you have a project in mind, I'd love to talk.
TXT;
const ABOUT_MIDDLE_FA = <<<'TXT'
سلام، من پارسا بنیطبا هستم، مهندس نرمافزار و توسعهدهندهی فلاتر. در تیم اپلیکیشن موبایل شرکت ایپام روی اپلیکیشن توبانک (بانک گردشگری) کار میکنم و دانشجوی ارشد نرمافزار هستم. به معماری تمیز و مدیریت وضعیت (مثل Bloc و GetX) اهمیت میدم و دوست دارم اپهایی بسازم که هم روان باشن و هم نگهداریشون راحت. اگه پروژهای در ذهن داری، خوشحال میشم صحبت کنیم.
TXT;

function run_about_middle_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . ABOUT_MIDDLE_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('about', 'about_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('about middle: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['about', ABOUT_MIDDLE_EN]);
    $stmt->execute(['about_fa', ABOUT_MIDDLE_FA]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . ABOUT_MIDDLE_MIGRATION . "', '1')");
}

// About text, final version: adds the emphasis on polished UI and smooth experience. Replaces the middle-tone text in both languages. Previous values are logged.
const ABOUT_FINAL_MIGRATION = 'migration_about_final_v1';
const ABOUT_FINAL_EN = <<<'TXT'
Hi, I'm Parsa Banitaba, a software engineer and Flutter developer. I'm on the mobile app team at IPAAM, working on the Tobank app for Tourism Bank, and I'm a Master's student in Software Engineering. I put real emphasis on polished UI and smooth, fluid user experience, alongside clean architecture and solid state management (Bloc, GetX), and I like building apps that are a pleasure to use and easy to maintain. If you have a project in mind, I'd love to talk.
TXT;
const ABOUT_FINAL_FA = <<<'TXT'
سلام، من پارسا بنیطبا هستم، مهندس نرمافزار و توسعهدهندهی فلاتر. در تیم اپلیکیشن موبایل شرکت ایپام روی اپلیکیشن توبانک (بانک گردشگری) کار میکنم و دانشجوی ارشد نرمافزار هستم. به رابط کاربری خوب و روانی اپلیکیشن اهمیت ویژه میدم و کنار معماری تمیز و مدیریت وضعیت (مثل Bloc و GetX)، دوست دارم اپهایی بسازم که استفاده ازشون لذتبخش باشه و نگهداریشون هم راحت. اگه پروژهای در ذهن داری، خوشحال میشم صحبت کنیم.
TXT;

function run_about_final_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . ABOUT_FINAL_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('about', 'about_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('about final: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['about', ABOUT_FINAL_EN]);
    $stmt->execute(['about_fa', ABOUT_FINAL_FA]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . ABOUT_FINAL_MIGRATION . "', '1')");
}

// About text, owner's edit: the Persian text as the owner wrote it, and the English text unchanged. Replaces the final text in both languages. Previous values are logged.
const ABOUT_OWNER_MIGRATION = 'migration_about_owner_v1';
const ABOUT_OWNER_EN = <<<'TXT'
Hi, I'm Parsa Banitaba, a software engineer and Flutter developer. I'm on the mobile app team at IPAAM, working on the Tobank app for Tourism Bank, and I'm a Master's student in Software Engineering. I put real emphasis on polished UI and smooth, fluid user experience, alongside clean architecture and solid state management (Bloc, GetX), and I like building apps that are a pleasure to use and easy to maintain. If you have a project in mind, I'd love to talk.
TXT;
const ABOUT_OWNER_FA = <<<'TXT'
سلام، من پارسا بنیطبا هستم، مهندس نرم افزار و توسعه دهنده ی فلاتر. در تیم اپلیکیشن موبایل شرکت ایپام روی اپلیکیشن توبانک (بانک گردشگری) کار میکنم و دانشجوی ارشد نرم افزار هستم. به رابط کاربری خوب و روانی اپلیکیشن اهمیت ویژه میدم و کنار معماری تمیز و مدیریت وضعیت (مثل Bloc و GetX)، دوست دارم اپهایی بسازم که استفاده ازشون لذتبخش باشه و نگهداریشون هم راحت. اگه پروژهای در ذهن داری، خوشحال میشم صحبت کنیم.
TXT;

function run_about_owner_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . ABOUT_OWNER_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('about', 'about_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('about owner: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['about', ABOUT_OWNER_EN]);
    $stmt->execute(['about_fa', ABOUT_OWNER_FA]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . ABOUT_OWNER_MIGRATION . "', '1')");
}

// About text, owner's wording with the zero-width non-joiner kept in the two words he typed with it (بنی‌طبا, لذت‌بخش). Replaces the previous owner-edit text in both languages. Previous values are logged.
const ABOUT_ZWNJ_MIGRATION = 'migration_about_zwnj_v1';
const ABOUT_ZWNJ_EN = <<<'TXT'
Hi, I'm Parsa Banitaba, a software engineer and Flutter developer. I'm on the mobile app team at IPAAM, working on the Tobank app for Tourism Bank, and I'm a Master's student in Software Engineering. I put real emphasis on polished UI and smooth, fluid user experience, alongside clean architecture and solid state management (Bloc, GetX), and I like building apps that are a pleasure to use and easy to maintain. If you have a project in mind, I'd love to talk.
TXT;
const ABOUT_ZWNJ_FA = <<<'TXT'
سلام، من پارسا بنی‌طبا هستم، مهندس نرم افزار و توسعه دهنده ی فلاتر. در تیم اپلیکیشن موبایل شرکت ایپام روی اپلیکیشن توبانک (بانک گردشگری) کار میکنم و دانشجوی ارشد نرم افزار هستم. به رابط کاربری خوب و روانی اپلیکیشن اهمیت ویژه میدم و کنار معماری تمیز و مدیریت وضعیت (مثل Bloc و GetX)، دوست دارم اپهایی بسازم که استفاده ازشون لذت‌بخش باشه و نگهداریشون هم راحت. اگه پروژهای در ذهن داری، خوشحال میشم صحبت کنیم.
TXT;

function run_about_zwnj_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . ABOUT_ZWNJ_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('about', 'about_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('about zwnj: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['about', ABOUT_ZWNJ_EN]);
    $stmt->execute(['about_fa', ABOUT_ZWNJ_FA]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . ABOUT_ZWNJ_MIGRATION . "', '1')");
}

// Persian spelling fix: کاراموز becomes کارآموز in every stored text field. Changed rows are logged with their previous values.
const INTERN_SPELLING_MIGRATION = 'migration_intern_spelling_v1';

function run_intern_spelling_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . INTERN_SPELLING_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $rows = $pdo->query('SELECT * FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $set = [];
            $values = [];
            $previous = [];
            foreach ($row as $col => $value) {
                if (is_string($value) && str_contains($value, 'کاراموز')) {
                    $set[] = '"' . $col . '" = ?';
                    $values[] = str_replace('کاراموز', 'کارآموز', $value);
                    $previous[$col] = $value;
                }
            }
            if (!$set) {
                continue;
            }
            $key = $table === 'profile' ? $row['k'] : $row['id'];
            migration_log("intern spelling: $table $key previous values " . json_encode($previous, JSON_UNESCAPED_UNICODE));
            $where = $table === 'profile' ? 'k = ?' : 'id = ?';
            $values[] = $key;
            $pdo->prepare('UPDATE "' . $table . '" SET ' . implode(', ', $set) . " WHERE $where")->execute($values);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . INTERN_SPELLING_MIGRATION . "', '1')");
}

// English becomes a panel with CAE and TTC as sub-items. Rewrites both language fields; the previous values are logged.
const LANGUAGES_PANEL_MIGRATION = 'migration_languages_v2';

function run_languages_panel_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . LANGUAGES_PANEL_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('languages', 'languages_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('languages v2: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['languages', "English | Advanced (C1)\nCAE | | parent:English\nTTC | | parent:English"]);
    $stmt->execute(['languages_fa', "انگلیسی | پیشرفته (C1)\nCAE | | parent:انگلیسی\nTTC | | parent:انگلیسی"]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . LANGUAGES_PANEL_MIGRATION . "', '1')");
}

// Stac gets its logo mark. Rewrites both skills lists; the previous values are logged.
const SKILLS_STAC_MIGRATION = 'migration_skills_v8';

function run_skills_v8_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . SKILLS_STAC_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('skills', 'skills_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('skills v8: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['skills', SKILLS_LIST]);
    $stmt->execute(['skills_fa', SKILLS_FA_LIST]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . SKILLS_STAC_MIGRATION . "', '1')");
}

// Bloc and Riverpod get their original logos. Rewrites both skills lists; the previous values are logged.
const SKILLS_LOGOS_MIGRATION = 'migration_skills_v9';

function run_skills_v9_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . SKILLS_LOGOS_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('skills', 'skills_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('skills v9: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $stmt->execute(['skills', SKILLS_LIST]);
    $stmt->execute(['skills_fa', SKILLS_FA_LIST]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . SKILLS_LOGOS_MIGRATION . "', '1')");
}

// English level shown as "Advanced (C1) · CAE · TTC", no native language, and the location. Previous values are logged.
const LOCATION_LANGUAGES_MIGRATION = 'migration_location_langs_v1';

function run_location_languages_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . LOCATION_LANGUAGES_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $current = $pdo->query("SELECT k, v FROM profile WHERE k IN ('languages', 'languages_fa', 'location', 'location_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('location/languages: previous values ' . json_encode($current, JSON_UNESCAPED_UNICODE));
    $set = [
        'languages' => "English | Advanced (C1) · CAE · TTC",
        'languages_fa' => "انگلیسی | پیشرفته (C1) · CAE · TTC",
        'location' => 'Iran, Tehran',
        'location_fa' => 'ایران، تهران',
    ];
    $stmt = $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    foreach ($set as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . LOCATION_LANGUAGES_MIGRATION . "', '1')");
}

// One-time removal of the word "junior" (English and Persian) from the profile headline and about text, and from timeline titles. Logged with the previous values.
const JUNIOR_MIGRATION = 'migration_junior_v1';

function strip_junior(string $text): string {
    $clean = preg_replace('/\s*(\bjunior\b|جونیور)\s*/iu', ' ', $text);
    return trim(preg_replace('/\s{2,}/u', ' ', $clean));
}

function run_junior_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . JUNIOR_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $profile = $pdo->query("SELECT k, v FROM profile WHERE k IN ('headline', 'headline_fa', 'about', 'about_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $update = $pdo->prepare('UPDATE profile SET v = ? WHERE k = ?');
    foreach ($profile as $k => $v) {
        $clean = strip_junior((string) $v);
        if ($clean !== (string) $v) {
            migration_log("profile $k: previous value " . json_encode($v, JSON_UNESCAPED_UNICODE));
            $update->execute([$clean, $k]);
        }
    }
    $timeline = $pdo->query('SELECT * FROM timeline')->fetchAll();
    $updateTimeline = $pdo->prepare('UPDATE timeline SET title = ?, title_fa = ? WHERE id = ?');
    foreach ($timeline as $row) {
        $title = strip_junior((string) $row['title']);
        $titleFa = strip_junior((string) $row['title_fa']);
        if ($title !== (string) $row['title'] || $titleFa !== (string) $row['title_fa']) {
            migration_log("timeline {$row['id']}: previous title " . json_encode([$row['title'], $row['title_fa']], JSON_UNESCAPED_UNICODE));
            $updateTimeline->execute([$title, $titleFa, $row['id']]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . JUNIOR_MIGRATION . "', '1')");
}

// One-time Persian name for the Persian pages: پارسا بنی‌طبا (with a half-space). Logged with the previous value.
const NAME_FA_MIGRATION = 'migration_name_fa_v1';
const NAME_FA_VALUE = "پارسا بنی\u{200C}طبا";

function run_name_fa_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . NAME_FA_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT v FROM profile WHERE k = 'name_fa'")->fetchColumn();
    migration_log('name_fa: previous value ' . json_encode((string) $previous, JSON_UNESCAPED_UNICODE));
    $pdo->prepare('INSERT INTO profile (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')
        ->execute(['name_fa', NAME_FA_VALUE]);
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . NAME_FA_MIGRATION . "', '1')");
}

// Persian name of the Classyar project: the UI and IDE show «کلاسیار» instead of «اپلیکیشن کلاسیار». Only that exact old value changes.
const CLASSYAR_NAME_FA_MIGRATION = 'migration_classyar_name_fa_v1';
function run_classyar_name_fa_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . CLASSYAR_NAME_FA_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $stmt = $pdo->prepare("UPDATE projects SET title_fa = 'کلاسیار' WHERE title = 'Classyar App' AND title_fa = 'اپلیکیشن کلاسیار'");
    $select = $pdo->prepare("SELECT * FROM projects WHERE title = 'Classyar App' AND title_fa = 'اپلیکیشن کلاسیار'");
    $select->execute();
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
        migration_log("classyar name fa: project {$row['id']} previous row " . json_encode($row, JSON_UNESCAPED_UNICODE));
    }
    $stmt->execute();
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . CLASSYAR_NAME_FA_MIGRATION . "', '1')");
}

// The hero line under the name: English «Software Engineer / Flutter developer», Persian «مهندس نرمافزار / توسعهدهنده فلاتر». Only empty headlines and the old default wording are replaced; a custom headline set in /admin is kept.
const ROLE_HEADLINE_MIGRATION = 'migration_role_headline_v1';
function run_role_headline_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . ROLE_HEADLINE_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $defaults = ['headline' => ['', 'Software engineer'], 'headline_fa' => ['', 'مهندس نرم‌افزار']];
    $targets = ['headline' => 'Software Engineer / Flutter developer', 'headline_fa' => 'مهندس نرمافزار / توسعهدهنده فلاتر'];
    $current = $pdo->query("SELECT k, v FROM profile WHERE k IN ('headline', 'headline_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $update = $pdo->prepare('UPDATE profile SET v = ? WHERE k = ?');
    foreach ($targets as $k => $new) {
        $old = (string) ($current[$k] ?? '');
        if (in_array(trim($old), $defaults[$k], true)) {
            migration_log("role headline: profile $k previous value " . json_encode($old, JSON_UNESCAPED_UNICODE));
            $update->execute([$new, $k]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . ROLE_HEADLINE_MIGRATION . "', '1')");
}

// Persian project names and descriptions for the cards, IDE tree and README. Rows are matched by English title, ignoring case, because the live titles differ in case from the seed. A Persian name is written only when it is empty or still the old wording; a Persian description is written only when the stored Persian or English text is in the wrong script.
const PROJECT_FA_NAMES_MIGRATION = 'migration_project_fa_names_v2';
const PROJECT_FA_NAMES = [
    'classyar app' => ['کلاسیار', ['', 'اپلیکیشن کلاسیار', 'Classyar App']],
    'techblog' => ['تکبلاگ', ['', 'تک بلاگ', 'تک‌بلاگ']],
];
const TECHBLOG_SUMMARY_EN = 'Group project for Prof. Safari, built with Flutter. Period: Tir 1401.';
const TECHBLOG_SUMMARY_FA = 'پروژه گروهی استاد صفری با فلاتر. بازه: تیر ۱۴۰۱.';
function run_project_fa_names_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . PROJECT_FA_NAMES_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $rows = $pdo->query('SELECT * FROM projects')->fetchAll(PDO::FETCH_ASSOC);
    $update = $pdo->prepare('UPDATE projects SET title_fa = ? WHERE id = ?');
    foreach ($rows as $row) {
        $key = mb_strtolower(trim((string) $row['title']));
        if (!isset(PROJECT_FA_NAMES[$key])) {
            continue;
        }
        [$fa, $old] = PROJECT_FA_NAMES[$key];
        if (in_array(trim((string) $row['title_fa']), $old, true)) {
            migration_log("project fa name: project {$row['id']} previous title_fa " . json_encode($row['title_fa'], JSON_UNESCAPED_UNICODE));
            $update->execute([$fa, $row['id']]);
        }
    }
    $techblog = $pdo->query("SELECT * FROM projects WHERE LOWER(TRIM(title)) = 'techblog'")->fetch(PDO::FETCH_ASSOC);
    if ($techblog) {
        $en = (string) $techblog['summary'];
        $fa = (string) $techblog['summary_fa'];
        $newEn = preg_match('/\p{Arabic}/u', $en) || trim($en) === '' ? TECHBLOG_SUMMARY_EN : $en;
        $newFa = preg_match('/\p{Arabic}/u', $fa) ? $fa : TECHBLOG_SUMMARY_FA;
        if ($newEn !== $en || $newFa !== $fa) {
            migration_log("techblog summary: project {$techblog['id']} previous " . json_encode([$en, $fa], JSON_UNESCAPED_UNICODE));
            $pdo->prepare('UPDATE projects SET summary = ?, summary_fa = ? WHERE id = ?')->execute([$newEn, $newFa, $techblog['id']]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . PROJECT_FA_NAMES_MIGRATION . "', '1')");
}

// TechBlog: the Persian name takes a half-space (تک‌بلاگ), and the instructor disclaimer is added as a new paragraph in both descriptions. Each step runs only when its text is not already there, and the previous values are logged.
const TECHBLOG_V3_MIGRATION = 'migration_techblog_name_disclaimer_v3';
const TECHBLOG_DISCLAIMER_EN = "⚠ This repository is part of my educational activities as an instructor. It was created solely for learning purposes and is not intended to reflect production-level code or professional portfolio projects.";
const TECHBLOG_DISCLAIMER_FA = "⚠ این مخزن بخشی از فعالیت‌های آموزشی من به‌عنوان مدرس است. این پروژه صرفاً برای اهداف آموزشی ساخته شده و قرار نیست نمایانگر کد در سطح محصول واقعی یا پروژه‌های حرفه‌ای نمونه‌کار باشد.";
function run_techblog_name_disclaimer_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . TECHBLOG_V3_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $row = $pdo->query("SELECT * FROM projects WHERE LOWER(TRIM(title)) = 'techblog' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $titleFa = (string) $row['title_fa'];
        $newTitle = in_array(trim($titleFa), ['تکبلاگ', 'تک بلاگ', "تک\u{200C}بلاگ"], true) ? "تک\u{200C}بلاگ" : $titleFa;
        $en = (string) $row['summary'];
        $fa = (string) $row['summary_fa'];
        $newEn = str_contains($en, TECHBLOG_DISCLAIMER_EN) ? $en : rtrim($en) . "\n\n" . TECHBLOG_DISCLAIMER_EN;
        $newFa = str_contains($fa, TECHBLOG_DISCLAIMER_FA) ? $fa : rtrim($fa) . "\n\n" . TECHBLOG_DISCLAIMER_FA;
        if ($newTitle !== $titleFa || $newEn !== $en || $newFa !== $fa) {
            migration_log("techblog name/disclaimer: project {$row['id']} previous " . json_encode([$titleFa, $en, $fa], JSON_UNESCAPED_UNICODE));
            $pdo->prepare('UPDATE projects SET title_fa = ?, summary = ?, summary_fa = ? WHERE id = ?')->execute([$newTitle, $newEn, $newFa, $row['id']]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . TECHBLOG_V3_MIGRATION . "', '1')");
}

// TechBlog now links to the course repository and has one short description line in each language. The previous description, including the disclaimer paragraph, is replaced in full. The previous values are logged.
const TECHBLOG_V4_MIGRATION = 'migration_techblog_podcast_v4';
const TECHBLOG_V4_URL = 'https://github.com/sasansafari/techblog';
const TECHBLOG_V4_EN = 'A podcast and tech-news application.';
const TECHBLOG_V4_FA = 'اپلیکیشن پادکست و اخبار در حوزه تکنولوژی';
function run_techblog_podcast_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . TECHBLOG_V4_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $row = $pdo->query("SELECT * FROM projects WHERE LOWER(TRIM(title)) = 'techblog' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $changed = [
            'github_url' => TECHBLOG_V4_URL,
            'summary' => TECHBLOG_V4_EN,
            'summary_fa' => TECHBLOG_V4_FA,
        ];
        $previous = array_intersect_key($row, $changed);
        if (array_diff_assoc($changed, $previous)) {
            migration_log("techblog podcast: project {$row['id']} previous " . json_encode($previous, JSON_UNESCAPED_UNICODE));
            $pdo->prepare('UPDATE projects SET github_url = ?, summary = ?, summary_fa = ? WHERE id = ?')
                ->execute([TECHBLOG_V4_URL, TECHBLOG_V4_EN, TECHBLOG_V4_FA, $row['id']]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . TECHBLOG_V4_MIGRATION . "', '1')");
}

// Classyar's card opens a list of stores: Cafe Bazaar (bazaar_url) and Myket (extra link). Written only when no extra links are stored yet.
const CLASSYAR_STORES_MIGRATION = 'migration_classyar_stores_v1';
const CLASSYAR_EXTRA_LINKS = [['label' => 'Myket', 'label_fa' => 'مایکت', 'url' => 'https://myket.ir/app/com.classyar.app']];
function run_classyar_stores_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . CLASSYAR_STORES_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $row = $pdo->query("SELECT * FROM projects WHERE LOWER(TRIM(title)) = 'classyar app' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && trim((string) ($row['extra_links'] ?? '')) === '') {
        migration_log("classyar stores: project {$row['id']} previous extra_links " . json_encode($row['extra_links'] ?? null, JSON_UNESCAPED_UNICODE));
        $pdo->prepare('UPDATE projects SET extra_links = ? WHERE id = ?')
            ->execute([json_encode(CLASSYAR_EXTRA_LINKS, JSON_UNESCAPED_UNICODE), $row['id']]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . CLASSYAR_STORES_MIGRATION . "', '1')");
}

// The hero pill shows the headline: English «Software Engineer / Flutter Developer» and Persian «مهندس نرمافزار / توسعهدهنده فلاتر». Stored headlines that still hold an earlier default (any casing or spelling of it) are replaced; anything else set in /admin is kept. Logged with the previous values.
const ROLE_HEADLINE_V2_MIGRATION = 'migration_role_headline_v2';
function run_role_headline_v2_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . ROLE_HEADLINE_V2_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $old = [
        'headline' => ['Flutter Developer', 'Flutter developer', 'Software engineer', 'Software Engineer / Flutter developer', ''],
        'headline_fa' => ['', 'مهندس نرم‌افزار', 'توسعه‌دهنده فلاتر', 'توسعه دهنده فلاتر', 'توسعهدهنده فلاتر', 'برنامه‌نویس فلاتر', 'مهندس نرم‌افزار / توسعه‌دهنده فلاتر', 'مهندس نرمافزار / توسعه‌دهنده فلاتر'],
    ];
    $new = ['headline' => 'Software Engineer / Flutter Developer', 'headline_fa' => 'مهندس نرمافزار / توسعهدهنده فلاتر'];
    $current = $pdo->query("SELECT k, v FROM profile WHERE k IN ('headline', 'headline_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $update = $pdo->prepare('UPDATE profile SET v = ? WHERE k = ?');
    foreach ($new as $k => $value) {
        if (!array_key_exists($k, $current)) {
            continue;
        }
        $stored = (string) $current[$k];
        if (in_array(trim($stored), $old[$k], true) && $stored !== $value) {
            migration_log("role headline v2: profile $k previous value " . json_encode($stored, JSON_UNESCAPED_UNICODE));
            $update->execute([$value, $k]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . ROLE_HEADLINE_V2_MIGRATION . "', '1')");
}

// TechBlog's description is one line that keeps the group-project prefix: the English and Persian lines are replaced in full, with the previous values logged.
const TECHBLOG_V5_MIGRATION = 'migration_techblog_group_line_v5';
const TECHBLOG_V5_EN = 'Group project for Professor Safari (Azad University): a podcast and tech-news application built with Flutter.';
const TECHBLOG_V5_FA = 'پروژه گروهی برای استاد صفری (دانشگاه آزاد): اپلیکیشن پادکست و اخبار در حوزه تکنولوژی با فلاتر';
function run_techblog_group_line_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . TECHBLOG_V5_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $row = $pdo->query("SELECT * FROM projects WHERE LOWER(TRIM(title)) = 'techblog' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && ($row['summary'] !== TECHBLOG_V5_EN || $row['summary_fa'] !== TECHBLOG_V5_FA)) {
        migration_log("techblog group line: project {$row['id']} previous " . json_encode([$row['summary'], $row['summary_fa']], JSON_UNESCAPED_UNICODE));
        $pdo->prepare('UPDATE projects SET summary = ?, summary_fa = ? WHERE id = ?')->execute([TECHBLOG_V5_EN, TECHBLOG_V5_FA, $row['id']]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . TECHBLOG_V5_MIGRATION . "', '1')");
}

// Classyar's store list: Bazaar (the bazaar link, labelled «بازار») first, then Myket, each with its icon. Only the stored Myket list is replaced, and the previous value is logged.
const CLASSYAR_STORES_V2_MIGRATION = 'migration_classyar_stores_v2';
function run_classyar_stores_v2_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . CLASSYAR_STORES_V2_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $row = $pdo->query("SELECT * FROM projects WHERE LOWER(TRIM(title)) = 'classyar app' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $list = [['label' => 'Myket', 'label_fa' => 'مایکت', 'url' => 'https://myket.ir/app/com.classyar.app', 'icon' => 'assets/img/stores/myket.svg']];
    if ($row && (string) ($row['extra_links'] ?? '') !== json_encode($list, JSON_UNESCAPED_UNICODE)) {
        migration_log("classyar stores v2: project {$row['id']} previous extra_links " . json_encode($row['extra_links'] ?? null, JSON_UNESCAPED_UNICODE));
        $pdo->prepare('UPDATE projects SET extra_links = ? WHERE id = ?')->execute([json_encode($list, JSON_UNESCAPED_UNICODE), $row['id']]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . CLASSYAR_STORES_V2_MIGRATION . "', '1')");
}

// The Persian hero pill: a missing or non-Persian headline_fa falls back to the English headline, so it is set to «مهندس نرمافزار / توسعهدهنده فلاتر». Anything already in Persian is kept. Logged with the previous value.
const ROLE_HEADLINE_FA_V3_MIGRATION = 'migration_role_headline_fa_v3';
function run_role_headline_fa_v3_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . ROLE_HEADLINE_FA_V3_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $target = 'مهندس نرمافزار / توسعهدهنده فلاتر';
    $stored = $pdo->query("SELECT v FROM profile WHERE k = 'headline_fa'")->fetchColumn();
    $stored = $stored === false ? '' : (string) $stored;
    if (!preg_match('/\p{Arabic}/u', $stored)) {
        migration_log('role headline fa v3: profile headline_fa previous value ' . json_encode($stored, JSON_UNESCAPED_UNICODE));
        $pdo->prepare("INSERT INTO profile (k, v) VALUES ('headline_fa', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v")->execute([$target]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . ROLE_HEADLINE_FA_V3_MIGRATION . "', '1')");
}

// The Persian role title is written with plain spaces: «مهندس نرم افزار / توسعه دهنده فلاتر». It overrides any other stored Persian value, and the previous value is logged.
const ROLE_HEADLINE_FA_V4_MIGRATION = 'migration_role_headline_fa_v4';
function run_role_headline_fa_v4_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . ROLE_HEADLINE_FA_V4_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $target = 'مهندس نرم افزار / توسعه دهنده فلاتر';
    $stored = $pdo->query("SELECT v FROM profile WHERE k = 'headline_fa'")->fetchColumn();
    $stored = $stored === false ? null : (string) $stored;
    if ($stored !== $target) {
        migration_log('role headline fa v4: profile headline_fa previous value ' . json_encode($stored, JSON_UNESCAPED_UNICODE));
        $pdo->prepare("INSERT INTO profile (k, v) VALUES ('headline_fa', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v")->execute([$target]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . ROLE_HEADLINE_FA_V4_MIGRATION . "', '1')");
}

// Classyar's store links: a store address filed as the website is cleared, the Bazaar link is filled in when it is missing, and the Myket item uses its PNG icon. Each change logs the previous row.
const CLASSYAR_STORES_V3_MIGRATION = 'migration_classyar_stores_v3';
const CLASSYAR_BAZAAR_URL = 'https://cafebazaar.ir/app/com.classyar.app';
function run_classyar_stores_v3_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . CLASSYAR_STORES_V3_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $row = $pdo->query("SELECT * FROM projects WHERE LOWER(TRIM(title)) = 'classyar app' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $url = (string) ($row['url'] ?? '');
        $bazaar = trim((string) ($row['bazaar_url'] ?? '')) !== '' ? (string) $row['bazaar_url'] : CLASSYAR_BAZAAR_URL;
        $newUrl = preg_match('#(cafebazaar\.ir|myket\.ir)#i', $url) ? '' : $url;
        $list = [['label' => 'Myket', 'label_fa' => 'مایکت', 'url' => 'https://myket.ir/app/com.classyar.app', 'icon' => 'assets/img/stores/myket.png']];
        $newList = json_encode($list, JSON_UNESCAPED_UNICODE);
        if ($newUrl !== $url || $bazaar !== (string) ($row['bazaar_url'] ?? '') || $newList !== (string) ($row['extra_links'] ?? '')) {
            migration_log("classyar stores v3: project {$row['id']} previous " . json_encode(['url' => $url, 'bazaar_url' => $row['bazaar_url'], 'extra_links' => $row['extra_links']], JSON_UNESCAPED_UNICODE));
            $pdo->prepare('UPDATE projects SET url = ?, bazaar_url = ?, extra_links = ? WHERE id = ?')->execute([$newUrl, $bazaar, $newList, $row['id']]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . CLASSYAR_STORES_V3_MIGRATION . "', '1')");
}

const TOBANK_LEAD_MIGRATION = 'migration_tobank_lead_v1';

/** The Persian intro names the app as "Tobank" in English only, without the Persian bank name in brackets. Previous text is logged. */
function run_tobank_lead_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . TOBANK_LEAD_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $current = (string) $pdo->query("SELECT v FROM profile WHERE k = 'about_fa'")->fetchColumn();
    if (str_contains($current, 'توبانک (بانک گردشگری)')) {
        migration_log('tobank lead: previous about_fa ' . json_encode($current, JSON_UNESCAPED_UNICODE));
        $stmt = $pdo->prepare('UPDATE profile SET v = ? WHERE k = ?');
        $stmt->execute([str_replace('توبانک (بانک گردشگری)', 'Tobank', $current), 'about_fa']);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . TOBANK_LEAD_MIGRATION . "', '1')");
}

const NOTEBOOKLM_SKILL_MIGRATION = 'migration_notebooklm_skill_v1';

/** Adds NotebookLM to the Skills list (English and Persian) with its brand icon. Previous values are logged. */
function run_notebooklm_skill_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . NOTEBOOKLM_SKILL_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT k, v FROM profile WHERE k IN ('skills', 'skills_fa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    migration_log('notebooklm skill: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare('UPDATE profile SET v = ? WHERE k = ?');
    foreach ($previous as $key => $value) {
        if (!preg_match('/^NotebookLM\b/mi', (string) $value)) {
            $stmt->execute([rtrim((string) $value, "\n") . "\nNotebookLM | notebooklm", $key]);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . NOTEBOOKLM_SKILL_MIGRATION . "', '1')");
}

const EXPERIENCE_TITLE_MIGRATION = 'migration_experience_title_v1';

/** The IPAAM role gets the software engineer title beside the Flutter one (English and Persian). Previous values are logged. */
function run_experience_title_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . EXPERIENCE_TITLE_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query("SELECT id, title, title_fa FROM timeline WHERE title = 'Flutter developer' AND org LIKE 'IPAAM%'")->fetchAll(PDO::FETCH_ASSOC);
    if ($previous) {
        migration_log('experience title: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
        $stmt = $pdo->prepare("UPDATE timeline SET title = ?, title_fa = ? WHERE title = 'Flutter developer' AND org LIKE 'IPAAM%'");
        $stmt->execute(['Software Engineer / Flutter Developer', "مهندس نرم‌افزار / برنامه\u{200C}نویس فلاتر"]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . EXPERIENCE_TITLE_MIGRATION . "', '1')");
}

const MEDIA_MIGRATION = 'migration_slides_research_v1';

/** Adds covers and file lists, and the first slides and research works. Files live under assets/slides and assets/research. */
function run_media_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . MEDIA_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    foreach (['presentations' => 'cover', 'papers' => 'cover', 'papers_files' => null] as $table => $col) {
        if ($col === null) continue;
        $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $col TEXT");
        }
    }
    $papersCols = array_column($pdo->query("PRAGMA table_info(papers)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('files', $papersCols, true)) {
        $pdo->exec("ALTER TABLE papers ADD COLUMN files TEXT");
    }
    migration_log('slides and research: schema ready');

    $slides = [
        ['Claude Presentation', 'ارائه Claude', 'claude-presentation', 'pptx'],
        ['DHCP', 'DHCP', 'dhcp', 'pptx'],
        ['Final Presentation: FDE-LLM', 'ارائه نهایی FDE-LLM', 'final-fde-llm', 'pptx'],
        ['Modiriat Hafeze', 'مدیریت حافظه', 'modiriat-hafeze', 'pptx'],
        ['Suggestion System', 'سیستم پیشنهاددهنده', 'suggestion-system', 'pptx'],
        ['Kernel Hardening', 'مقاوم‌سازی هسته', 'kernel-hardening', 'pptx'],
        ['Software Engineering', 'مهندسی نرم‌افزار', 'software-engineering', 'pptx'],
        ['Shojaieekian', 'شجاعی‌ئیکیان', 'shojaieekian', 'pdf'],
    ];
    $insert = $pdo->prepare('INSERT INTO presentations (title, title_fa, event, year, pptx, pdf, sort, cover) VALUES (?, ?, ?, NULL, ?, ?, ?, ?)');
    foreach ($slides as $i => [$en, $fa, $slug, $kind]) {
        $file = "assets/slides/$slug.$kind";
        $insert->execute([$en, $fa, '', $kind === 'pptx' ? $file : null, $kind === 'pdf' ? $file : null, $i + 1, "assets/slides/covers/$slug.jpg"]);
    }

    $works = [
        ['Linux Kernel Security and Isolation', 'امنیت و جداسازی کرنل لینوکس', 'research-1', 'assets/research/1', ['2024-acsac-rfl.pdf', '3576915.3623220.pdf', 'islab.asiaccs24.pdf', 'safeslab.ccs24.pdf', 'uswitch_sp23.pdf']],
        ['Social Opinion Prediction with LLM Agents', 'پیش‌بینی نظرات اجتماعی با عامل‌های LLM', 'research-2', 'assets/research/2', ['Yao2025_FDE-LLM_ScientificReports.pdf']],
        ['Distributed Petri Nets for ROS Robotics', 'شبکه‌های پتری توزیع‌شده برای رباتیک ROS', 'research-3', 'assets/research/3', ['s11334-024-00570-5.pdf']],
        ['IoT Security with Blockchain', 'امنیت اینترنت اشیا با بلاک‌چین', 'research-4', 'assets/research/4', ['1-s2.0-S1110016825000298-main.pdf', '1-s2.0-S1319157824000284-main.pdf', '1-s2.0-S2542660524002981-main.pdf', 'A_Promising_Integration_of_SDN_and_Blockchain_for_IoT_Networks_A.pdf', 'A_Survey_on_Addressing_IoT_Security_Issues_by_Embedding_Blockchain.pdf', 'agriculture-13-00274-v2.pdf', 'Gill Blockchain Empowered Secure 2024 Accepted.pdf', 'hari-mohan-rai-enhancing-data-security-and-privacy-in.pdf', 'Optimizing_Security_in_IoT_Ecosystems_Using_Hybrid_Artificial_Intelligence.pdf', 'vinay-gugueoth-a-review-of-iot-security-and-privacy.pdf']],
    ];
    $insertPaper = $pdo->prepare('INSERT INTO papers (title, title_fa, venue, year, doi, pdf, sort, cover, files) VALUES (?, ?, ?, NULL, NULL, NULL, ?, ?, ?)');
    foreach ($works as $i => [$en, $fa, $slug, $dir, $names]) {
        $files = [];
        foreach ($names as $j => $name) {
            $files[] = ['path' => "$dir/" . ($j + 1) . '.pdf', 'label' => count($names) > 1 ? 'PDF ' . ($j + 1) : 'PDF'];
        }
        $insertPaper->execute([$en, $fa, '', $i + 1, "assets/research/" . ($i + 1) . "/cover.jpg", json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }
    migration_log('slides and research: added ' . count($slides) . ' slides and ' . count($works) . ' research works');
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . MEDIA_MIGRATION . "', '1')");
}

const RESEARCH_BULLETS_MIGRATION = 'migration_research_bullets_v2';

/** Research works: each list item is a paper title, and the cards no longer show a date. Previous values are logged. */
function run_research_bullets_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . RESEARCH_BULLETS_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $works = [
        ['Linux Kernel Security and Isolation', 'assets/research/1', [
            'Rust for Linux: Understanding the Security Impact of Rust in the Linux Kernel',
            'RetSpill: Igniting User-Controlled Data to Burn Away Linux Kernel Protections',
            'ISLAB: Immutable Memory Management Metadata for Commodity Operating System Kernels',
            'Safeslab: Mitigating Use-After-Free Vulnerabilities via Memory Protection Keys',
            'μSwitch: Fast Kernel Context Isolation with Implicit Context Switches',
        ]],
        ['Social Opinion Prediction with LLM Agents', 'assets/research/2', [
            'Social opinions prediction utilizes fusing dynamics equation with LLM-based agents',
        ]],
        ['Distributed Petri Nets for ROS Robotics', 'assets/research/3', [
            'Distributed Petri nets for model-driven verifiable robotic applications in ROS',
        ]],
        ['IoT Security with Blockchain', 'assets/research/4', [
            'Application of IoT and blockchain technology in the integration of innovation and industrial chains in high-tech manufacturing',
            'Collaborative threat intelligence: Enhancing IoT security through blockchain and machine learning integration',
            'Enhancing security of Internet of Robotic Things: A review of recent…',
            'A Promising Integration of SDN and Blockchain for IoT Networks: A Survey',
            'A Survey on Addressing IoT Security Issues by Embedding Blockchain Technology Solutions',
            'Agricultural IoT Data Storage Optimization and Information Security Method Based on Blockchain',
            'Blockchain Empowered Secure Federated Learning for Consumer IoT Applications in Cloud-Edge Collaborative Environment',
            'Enhancing data security and privacy in energy applications…',
            'Optimizing Security in IoT Ecosystems Using Hybrid Artificial Intelligence and Blockchain Models',
            'A review of IoT security and privacy using decentralized blockchain',
        ]],
    ];
    $update = $pdo->prepare("UPDATE papers SET files = ?, venue = '', year = NULL WHERE title = ?");
    $previous = $pdo->query('SELECT title, venue, year, files FROM papers')->fetchAll(PDO::FETCH_ASSOC);
    migration_log('research bullets: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    foreach ($works as [$title, $dir, $items]) {
        $files = [];
        foreach ($items as $j => $label) {
            $files[] = ['path' => $dir . '/' . ($j + 1) . '.pdf', 'label' => $label];
        }
        $update->execute([json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $title]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . RESEARCH_BULLETS_MIGRATION . "', '1')");
}

const SLIDE_RENAME_MIGRATION = 'migration_slide_titles_v3';

/** Renames the slides and their files: the Claude deck has no "presentation" word, and three others get new titles. Previous values are logged. */
function run_slide_rename_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . SLIDE_RENAME_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $renames = [
        // [old file, new file, English title, Persian title]
        ['assets/slides/claude-presentation.pptx', 'assets/slides/claude.pptx', 'Claude', 'Claude'],
        ['assets/slides/final-fde-llm.pptx', 'assets/slides/social-opinions-prediction-llm-based-agents.pptx', 'Social opinions prediction - LLM base agents', 'Social opinions prediction - LLM base agents'],
        ['assets/slides/software-engineering.pptx', 'assets/slides/use-case-based-project.pptx', 'use case based project', 'use case based project'],
        ['assets/slides/shojaieekian.pdf', 'assets/slides/decision-petri-nets.pdf', 'Decision Petri Nets', 'شبکه‌های پتری تصمیمی'],
    ];
    $update = $pdo->prepare('UPDATE presentations SET title = ?, title_fa = ?, cover = ?, pptx = CASE WHEN pptx = ? THEN ? ELSE pptx END, pdf = CASE WHEN pdf = ? THEN ? ELSE pdf END WHERE pptx = ? OR pdf = ?');
    $previous = $pdo->query('SELECT id, title, title_fa, pptx, pdf, cover FROM presentations')->fetchAll(PDO::FETCH_ASSOC);
    migration_log('slide titles: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    foreach ($renames as [$old, $new, $en, $fa]) {
        $cover = 'assets/slides/covers/' . basename($new, '.' . pathinfo($new, PATHINFO_EXTENSION)) . '.jpg';
        $update->execute([$en, $fa, $cover, $old, $new, $old, $new, $old, $old]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . SLIDE_RENAME_MIGRATION . "', '1')");
}

const RESEARCH_REVERT_MIGRATION = 'migration_research_revert_v4';

/** Reverts the site's research cards to the date line and inline PDF links; only the rows layout stays (a CSS change). Previous values are logged. */
function run_research_revert_migration(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . RESEARCH_REVERT_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $previous = $pdo->query('SELECT title, venue, year, files FROM papers')->fetchAll(PDO::FETCH_ASSOC);
    migration_log('research revert: previous values ' . json_encode($previous, JSON_UNESCAPED_UNICODE));
    $update = $pdo->prepare('UPDATE papers SET files = ?, year = ? WHERE title = ?');
    $works = [
        ['Linux Kernel Security and Isolation', 'assets/research/1', 5, null],
        ['Social Opinion Prediction with LLM Agents', 'assets/research/2', 1, null],
        ['Distributed Petri Nets for ROS Robotics', 'assets/research/3', 1, null],
        ['IoT Security with Blockchain', 'assets/research/4', 10, 'فروردین ۱۴۰۴'],
    ];
    foreach ($works as [$title, $dir, $count, $year]) {
        $files = [];
        for ($j = 1; $j <= $count; $j++) {
            $files[] = ['path' => $dir . '/' . $j . '.pdf', 'label' => $count > 1 ? 'PDF ' . $j : 'PDF'];
        }
        $update->execute([json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $year, $title]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . RESEARCH_REVERT_MIGRATION . "', '1')");
}
