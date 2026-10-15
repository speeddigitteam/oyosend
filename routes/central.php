<?php

use App\Core\Http\Controllers\FindWorkspaceController;
use App\Core\Http\Controllers\LocaleController;
use App\Core\Registration\Http\Controllers\RegistrationController;
use App\Core\Website\Http\Controllers\BlogController;
use App\Core\Website\Http\Controllers\SiteController;
use App\Core\Website\Http\Controllers\SiteFilesController;
use App\Core\Website\Models\BlogPost;
use App\Core\Website\SiteApps;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central site - APP_DOMAIN
|--------------------------------------------------------------------------
|
| Loaded inside the `web` + `central` middleware groups with the `central.`
| name prefix. No tenant is active here and nobody is signed in: sessions
| belong to a workspace's own host.
|
*/

/*
| The public website (docs/WEBSITE.md, ADR 0033): pages rendered on the server so that people, search engines and
| answer engines all read the same thing. Every page exists in English at the root and in Bengali under /bn.
*/
$website = static function (): void {
    Route::get('apps', [SiteController::class, 'apps'])->name('apps');
    Route::get('apps/{app}', [SiteController::class, 'app'])->whereIn('app', SiteApps::slugs())->name('app');
    Route::get('pricing', [SiteController::class, 'pricing'])->name('pricing');
    Route::get('security', [SiteController::class, 'security'])->name('security');
    Route::get('about', [SiteController::class, 'about'])->name('about');
    Route::get('faq', [SiteController::class, 'faq'])->name('faq');
    Route::get('contact', [SiteController::class, 'contact'])->name('contact');
    Route::post('contact', [SiteController::class, 'sendMessage'])->middleware('throttle:5,10')->name('contact.send');
    Route::get('signin', [SiteController::class, 'signin'])->name('signin');

    // The blog (ADR 0037): every article in the languages it is written in, and a feed per language.
    Route::get('blog', [BlogController::class, 'index'])->name('blog');
    Route::get('blog/feed.xml', [BlogController::class, 'feed'])->name('blog_feed');
    Route::get('blog/{post}', [BlogController::class, 'show'])->where('post', BlogPost::SLUG_PATTERN)->name('post');

    Route::get('privacy', [SiteController::class, 'legal'])->defaults('document', 'privacy')->name('privacy');
    Route::get('terms', [SiteController::class, 'legal'])->defaults('document', 'terms')->name('terms');
    Route::get('refund-policy', [SiteController::class, 'legal'])->defaults('document', 'refund')->name('refund');
};

Route::get('/', [SiteController::class, 'home'])->middleware('site.locale:en')->name('home');
Route::middleware('site.locale:en')->name('site.')->group($website);

Route::prefix('bn')->middleware('site.locale:bn')->name('site.bn.')->group(function () use ($website): void {
    Route::get('/', [SiteController::class, 'home'])->name('home');
    $website();
});

// For crawlers: what they may read, every page with its other language, and a plain summary for AI assistants.
Route::get('robots.txt', [SiteFilesController::class, 'robots'])->name('robots');
Route::get('sitemap.xml', [SiteFilesController::class, 'sitemap'])->name('sitemap');
Route::get('llms.txt', [SiteFilesController::class, 'llms'])->name('llms');

Route::post('workspace', FindWorkspaceController::class)
    ->middleware('throttle:20,1')
    ->name('workspace.find');

Route::post('locale', LocaleController::class)->name('locale.update');

// Signing up for a workspace. Answers 404 while registration is closed.
Route::get('register', [RegistrationController::class, 'create'])->name('register');
Route::post('register', [RegistrationController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('register.store');
Route::get('register/sent', [RegistrationController::class, 'sent'])->name('register.sent');

// The website's "start your workspace" card: carries the company and email to the form above.
Route::post('register/start', [RegistrationController::class, 'start'])
    ->middleware('throttle:20,1')
    ->name('register.start');

// The emailed link: a page first, so that only a person pressing the button creates anything.
Route::get('register/confirm/{token}', [RegistrationController::class, 'confirm'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:30,1')
    ->name('register.confirm');
Route::post('register/confirm/{token}', [RegistrationController::class, 'complete'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('register.complete');
