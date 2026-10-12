<?php
declare(strict_types=1);

const LANGUAGES = ['en', 'fa'];

const STRINGS = [
    'en' => [
        'nav_home' => 'Home',
        'nav_projects' => 'Projects',
        'nav_papers' => 'Papers',
        'nav_slides' => 'Presentations',
        'nav_journey' => 'Education & experience',
        'nav_contact' => 'Contact',
        'toggle_theme' => 'Toggle theme',
        'download_cv' => 'Download CV',
        'email_me' => 'Email me',
        'projects' => 'Projects',
        'papers' => 'Papers',
        'presentations' => 'Presentations',
        'journey' => 'Education & experience',
        'education' => 'Education',
        'experience' => 'Experience',
        'contact' => 'Contact',
        'phone' => 'Phone',
        'filter_all' => 'All',
        'filter_work' => 'Work',
        'filter_academic' => 'Academic',
        'view_project' => 'View project',
        'open_pdf' => 'Open PDF',
        'download_pptx' => 'Download PPTX',
        'empty_projects' => 'Projects will appear here.',
        'empty_papers' => 'Papers will appear here.',
        'empty_slides' => 'Presentations will appear here.',
        'default_name' => 'Your Name',
        'default_headline' => 'Software engineer',
        'default_title' => 'Portfolio',
        'lang_switch' => 'فارسی',
        'lets_chat' => "Let's Chat",
        'stat_projects' => 'Projects',
        'stat_papers' => 'Papers',
        'stat_presentations' => 'Presentations',
    ],
    'fa' => [
        'nav_home' => 'خانه',
        'nav_projects' => 'پروژه‌ها',
        'nav_papers' => 'مقالات',
        'nav_slides' => 'ارائه‌ها',
        'nav_journey' => 'تحصیلات و سوابق',
        'nav_contact' => 'تماس',
        'toggle_theme' => 'تغییر تم',
        'download_cv' => 'دانلود رزومه',
        'email_me' => 'ارسال ایمیل',
        'projects' => 'پروژه‌ها',
        'papers' => 'مقالات',
        'presentations' => 'ارائه‌ها',
        'journey' => 'تحصیلات و سوابق کاری',
        'education' => 'تحصیلات',
        'experience' => 'سوابق کاری',
        'contact' => 'تماس',
        'phone' => 'تلفن',
        'filter_all' => 'همه',
        'filter_work' => 'کاری',
        'filter_academic' => 'دانشگاهی',
        'view_project' => 'مشاهده پروژه',
        'open_pdf' => 'باز کردن PDF',
        'download_pptx' => 'دانلود PPTX',
        'empty_projects' => 'پروژه‌ها اینجا نمایش داده می‌شوند.',
        'empty_papers' => 'مقالات اینجا نمایش داده می‌شوند.',
        'empty_slides' => 'ارائه‌ها اینجا نمایش داده می‌شوند.',
        'default_name' => 'نام شما',
        'default_headline' => 'مهندس نرم‌افزار',
        'default_title' => 'پورتفولیو',
        'lang_switch' => 'English',
        'lets_chat' => 'گفتگو',
        'stat_projects' => 'پروژه',
        'stat_papers' => 'مقاله',
        'stat_presentations' => 'ارائه',
    ],
];

/** Language from ?lang=, else the saved cookie, else English. Saves the choice for later visits. */
function current_lang(): string {
    $requested = $_GET['lang'] ?? null;
    if ($requested !== null && in_array($requested, LANGUAGES, true)) {
        setcookie('lang', $requested, [
            'expires' => time() + 31536000,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        return $requested;
    }
    $cookie = $_COOKIE['lang'] ?? 'en';
    return in_array($cookie, LANGUAGES, true) ? $cookie : 'en';
}

function t(string $key, string $lang): string {
    return STRINGS[$lang][$key] ?? STRINGS['en'][$key] ?? $key;
}
