<?php
declare(strict_types=1);

const LANGUAGES = ['en', 'fa'];

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
        'contact' => 'Contact',
        'phone' => 'Phone',
        'email' => 'Email',
        'view_project' => 'View project',
        'back_to_top' => 'Back to top',
        'full_description' => 'Full description in the IDE',
        'open_pdf' => 'Open PDF',
        'download_pptx' => 'Download PPTX',
        'empty_projects' => 'Projects will appear here.',
        'empty_papers' => 'Research will appear here.',
        'empty_slides' => 'Slides will appear here.',
        'default_name' => 'Your Name',
        'default_headline' => 'Software engineer',
        'default_title' => 'Portfolio',
        'lang_switch' => 'فارسی',
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
        'private_code_more' => 'Contact me for a walkthrough.',
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
        'contact' => 'تماس',
        'phone' => 'تلفن',
        'email' => 'ایمیل',
        'view_project' => 'مشاهده پروژه',
        'back_to_top' => 'بازگشت به بالای صفحه',
        'full_description' => 'توضیحات کامل در IDE',
        'open_pdf' => 'باز کردن PDF',
        'download_pptx' => 'دانلود PPTX',
        'empty_projects' => 'پروژه‌ها اینجا نمایش داده می‌شوند.',
        'empty_papers' => 'پژوهش‌ها اینجا نمایش داده می‌شوند.',
        'empty_slides' => 'اسلایدها اینجا نمایش داده می‌شوند.',
        'default_name' => 'نام شما',
        'default_headline' => 'مهندس نرم‌افزار',
        'default_title' => 'پورتفولیو',
        'lang_switch' => 'English',
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
        'private_code_more' => 'برای توضیح و نمایش عملی با من تماس بگیرید.',
        'contact_me' => 'تماس با من',
        'view_label' => 'نمایش پروژه‌ها',
        'view_normal' => 'عادی',
        'view_ide' => 'IDE',
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
