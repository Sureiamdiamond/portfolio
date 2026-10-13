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
    $ipam = ['org' => 'شرکت دانش‌بنیان ایده‌پرداز دانش نوین آرشام (ایپام)', 'org_en' => 'Ideh Pardaz Danesh Novin Arsham (IPAM)', 'website' => 'https://ipaam.ir/', 'city' => 'تهران', 'city_en' => 'Tehran'];
    $azad = ['website_w' => 'https://wtb.iau.ir/', 'website_c' => 'https://ctb.iau.ir/'];
    return [
        [
            'kind' => 'education', 'title' => 'Master of Science in Computer Engineering (software)', 'title_fa' => 'کارشناسی ارشد مهندسی کامپیوتر (گرایش نرم‌افزار)',
            'org' => 'Islamic Azad University, Central Tehran Branch', 'org_fa' => 'دانشگاه آزاد اسلامی واحد تهران مرکز',
            'city' => 'Tehran', 'city_fa' => 'تهران', 'period' => 'Oct 2025 – now', 'period_fa' => 'مهر ۱۴۰۴ – اکنون',
            'website' => $azad['website_c'], 'details' => 'Major: Software', 'details_fa' => 'گرایش: نرم‌افزار', 'bullets' => '', 'bullets_fa' => '', 'sort' => 1,
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
            'kind' => 'experience', 'title' => 'Intern (Codeyad project-based course)', 'title_fa' => 'کاراموز (دوره پروژه‌محور کدیاد)',
            'org' => 'Codeyad', 'org_fa' => 'کدیاد', 'city' => 'Tehran', 'city_fa' => 'تهران',
            'period' => 'Apr 2023 – Nov 2023', 'period_fa' => 'اردیبهشت ۱۴۰۲ – آبان ۱۴۰۲',
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
SDUI | | parent:Flutter
Stac | | parent:Flutter
Riverpod | | parent:Flutter
AI |
Claude | | parent:AI
Codex | | parent:AI
Antigravity | | parent:AI
Grok | | parent:AI
Git | git
Http / Dio | | more
GitHub | github | more
GitLab | gitlab | more
Figma | figma | more
Jira | jira | more
Firebase | firebase | more";

// Same list for the Persian page: only the AI group name differs.
const SKILLS_FA_LIST = "Dart | dart
Flutter | flutter
GetX | getx | parent:Flutter
Bloc | bloc | parent:Flutter
SDUI | | parent:Flutter
Stac | | parent:Flutter
Riverpod | | parent:Flutter
هوش مصنوعی |
Claude | | parent:هوش مصنوعی
Codex | | parent:هوش مصنوعی
Antigravity | | parent:هوش مصنوعی
Grok | | parent:هوش مصنوعی
Git | git
Http / Dio | | more
GitHub | github | more
GitLab | gitlab | more
Figma | figma | more
Jira | jira | more
Firebase | firebase | more";

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
