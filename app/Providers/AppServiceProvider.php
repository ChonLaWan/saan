<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Support\Modules\ModuleRegistry::class);
        $this->app->singleton(\App\Support\PublicCsp::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Real client IP and scheme behind Cloudflare or a TLS-terminating proxy; nothing is trusted unless TRUSTED_PROXIES is set.
        $trusted = trim((string) config('saan.trusted_proxies', ''));
        if ($trusted !== '') {
            \Illuminate\Http\Middleware\TrustProxies::at($trusted === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $trusted)))));
            \Illuminate\Http\Middleware\TrustProxies::withHeaders(\Illuminate\Http\Request::HEADER_X_FORWARDED_FOR | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO); // never Host/Port: a visitor-supplied X-Forwarded-Host would poison reset/invite links (APP_URL stays the source for mail links)
        }
        // S3: internal edition with a push URL: writes to published tables mark streams as changed, and a request that changed something tries one cheap synchronous delivery at its end.
        // Outbox::touch does nothing until a push URL exists (the .env value or the address saved by pairing), so a wizard-paired install gets its markers too.
        if (\App\Support\Saan\Edition::isInternal()) {
            \App\Support\Saan\Publish\Outbox::watch();
            $this->app->terminating(fn () => \App\Support\Saan\Publish\Publisher::afterRequest());
        }
        // S5: the public edition keeps no plaintext of a visitor, so a validation redirect must not flash the typed personal data into the session store.
        if (\App\Support\Saan\Edition::isPublic()) {
            $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
            if (method_exists($handler, 'dontFlash')) $handler->dontFlash(['name', 'email', 'phone', 'message', 'a', 'code', 'cookie']);
        }
        // mail.school_fallback comes from config/mail.php (env MAIL_MAILER); never derive it from mail.default, which is 'school' once cached.
        if(config('mail.default')!=='school'&&!config('mail.school_fallback'))config(['mail.school_fallback'=>config('mail.default','log')]);
        config(['mail.default'=>'school','mail.mailers.school'=>['transport'=>'school']]);
        \Illuminate\Support\Facades\Mail::extend('school',fn()=>app(\App\Domain\SchoolMail::class)->transport());
    }
}
