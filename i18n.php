<?php
declare(strict_types=1);

const LANGUAGES = ['en', 'fa'];
const DEFAULT_LANG = 'fa';

const STRINGS = [
    'en' => [
        'nav_home' => 'Home',
        'nav_projects' => 'Projects',
        'nav_papers' => 'Research',
        'nav_slides' => 'Slides',
        'nav_journey' => 'Education & experience',
        'nav_contact' => 'Contact',
        'toggle_theme' => 'Toggle theme',
        'download_cv' => 'Download CV',
        'email_me' => 'Email me',
        'projects' => 'Projects',
        'papers' => 'Research',
        'presentations' => 'Slides',
        'journey' => 'Education & experience',
        'education' => 'Education',
        'experience' => 'Experience',
        'skills' => 'Skills',
        'languages' => 'Languages',
        'website_link' => 'Website (opens in a new tab)',
        'skills_languages' => 'Skills & languages',
        'contact' => 'Contact',
        'phone' => 'Phone',
        'email' => 'Email',
        'location' => 'Location',
        'view_project' => 'View project',
        'view_in_ide' => 'View in IDE',
        'back_to_top' => 'Back to top',
        'full_description' => 'Full description in the IDE',
        'open_pdf' => 'Open PDF',
        'download_pptx' => 'Download PPTX',
        'empty_projects' => 'Projects will appear here.',
        'empty_papers' => 'Research will appear here.',
        'empty_slides' => 'Slides will appear here.',
        'default_name' => 'Your Name',
        'default_headline' => 'Software Engineer / Flutter Developer',
        'default_title' => 'Portfolio',
        'lang_switch' => 'فارسی',
        'language' => 'زبان / Language',
        'lang_fa' => '/persian',
        'lang_en' => '/english',
        'lang_bin' => 'Binary',
        'lets_chat' => "Let's Chat",
        'stat_projects' => 'Projects',
        'stat_papers' => 'Research',
        'stat_presentations' => 'Slides',
        'files' => 'Files',
        'files_project' => 'Project',
        'expand_all' => 'Expand all',
        'collapse_all' => 'Collapse all',
        'select_file' => 'Select a file to preview it here.',
        'no_preview' => 'No preview for this file type. Use Download.',
        'download' => 'Download',
        'private_code' => 'Source code for this file is private.',
        'contact_me' => 'Contact me',
        'view_label' => 'Projects view',
        'view_normal' => 'Normal',
        'view_ide' => 'IDE',
    ],
    'fa' => [
        'nav_home' => 'خانه',
        'nav_projects' => 'پروژه‌ها',
        'nav_papers' => 'پژوهش‌ها',
        'nav_slides' => 'اسلایدها',
        'nav_journey' => 'تحصیلات و سوابق',
        'nav_contact' => 'تماس',
        'toggle_theme' => 'تغییر تم',
        'download_cv' => 'دانلود رزومه',
        'email_me' => 'ارسال ایمیل',
        'projects' => 'پروژه‌ها',
        'papers' => 'پژوهش‌ها',
        'presentations' => 'اسلایدها',
        'journey' => 'تحصیلات و سوابق کاری',
        'education' => 'تحصیلات',
        'experience' => 'سوابق کاری',
        'skills' => 'مهارت‌ها',
        'languages' => 'زبان‌ها',
        'website_link' => 'وب‌سایت (در برگه‌ی جدید باز می‌شود)',
        'skills_languages' => 'مهارت‌ها و زبان‌ها',
        'contact' => 'تماس',
        'phone' => 'تلفن',
        'email' => 'ایمیل',
        'location' => 'موقعیت',
        'view_project' => 'مشاهده پروژه',
        'view_in_ide' => 'مشاهده در IDE',
        'back_to_top' => 'بازگشت به بالای صفحه',
        'full_description' => 'توضیحات کامل در IDE',
        'open_pdf' => 'باز کردن PDF',
        'download_pptx' => 'دانلود PPTX',
        'empty_projects' => 'پروژه‌ها اینجا نمایش داده می‌شوند.',
        'empty_papers' => 'پژوهش‌ها اینجا نمایش داده می‌شوند.',
        'empty_slides' => 'اسلایدها اینجا نمایش داده می‌شوند.',
        'default_name' => 'نام شما',
        'default_headline' => 'مهندس نرم افزار / توسعه دهنده فلاتر',
        'default_title' => 'پورتفولیو',
        'lang_switch' => 'English',
        'language' => 'زبان / Language',
        'lang_fa' => '/persian',
        'lang_en' => '/english',
        'lang_bin' => 'باینری',
        'lets_chat' => 'گفتگو',
        'stat_projects' => 'پروژه',
        'stat_papers' => 'پژوهش',
        'stat_presentations' => 'اسلاید',
        'files' => 'فایل‌ها',
        'files_project' => 'پروژه',
        'expand_all' => 'باز کردن همه',
        'collapse_all' => 'بستن همه',
        'select_file' => 'یک فایل را انتخاب کنید تا اینجا نمایش داده شود.',
        'no_preview' => 'پیش‌نمایشی برای این نوع فایل نیست. از دکمه‌ی دانلود استفاده کنید.',
        'download' => 'دانلود',
        'private_code' => 'کد منبع این فایل خصوصی است.',
        'contact_me' => 'تماس با من',
        'view_label' => 'نمایش پروژه‌ها',
        'view_normal' => 'عادی',
        'view_ide' => 'IDE',
    ],
];

/** Language from ?lang=, else the saved cookie, else English. Saves the choice for later visits. */
/** The language the page is rendered in. Binary is an English page in a binary style, drawn by the script; without the script it stays English. */
function current_lang(): string {
    $requested = $_GET['lang'] ?? null;
    if ($requested !== null && in_array($requested, [...LANGUAGES, 'bin'], true)) {
        // Binary mode is never saved: only a normal language goes into the cookie
        if ($requested !== 'bin') {
            setcookie('lang', $requested, [
                'expires' => time() + 31536000,
                'path' => '/',
                'samesite' => 'Lax',
            ]);
        }
        return $requested === 'bin' ? 'en' : $requested;
    }
    // Default for a visitor with no saved choice is Persian; a saved binary cookie (from older visits) falls back to it too
    $cookie = $_COOKIE['lang'] ?? DEFAULT_LANG;
    if ($cookie === 'bin') {
        return DEFAULT_LANG;
    }
    return in_array($cookie, LANGUAGES, true) ? $cookie : DEFAULT_LANG;
}

/** The UTF-8 bytes of a word as 8-bit groups, so the language menu can show the word in binary. */
function text_to_bits(string $text): string {
    return implode(' ', array_map(static fn(int $byte): string => str_pad(decbin($byte), 8, '0', STR_PAD_LEFT), array_values(unpack('C*', $text) ?: [])));
}

function t(string $key, string $lang): string {
    return STRINGS[$lang][$key] ?? STRINGS['en'][$key] ?? $key;
}
