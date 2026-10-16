<?php

// Test-owned HTTP server/fixture bootstrap; refuse unsafe input before vendor loading.
require_once '/app/tests/Support/Environment.php';
try { preflight(); }
catch (Throwable $error) { fwrite(STDERR, $error->getMessage()."\n"); exit(78); }

function browserApplication(bool $serving = false)
{
    $app = application();
    \Tests\Support\TransportGuard::install();
    config(['hms.features.tools' => true, 'services.stripe.key' => null]);
    if ($serving) {
        // Array sessions cannot survive separate real HTTP requests. Only this guarded
        // test server uses file sessions, in the container's disposable storage tmpfs.
        config(['session.driver' => 'file', 'session.files' => '/app/storage/framework/sessions',
            'session.lottery' => [0, 100], 'session.secure' => false, 'session.domain' => null,
            'app.url' => 'http://application:8080']);
        ensure(config('session.driver') === 'file' && config('session.files') === '/app/storage/framework/sessions',
            'Unsafe browser session storage');
        foreach (['private','public'] as $kind) {
            $path = '/app/storage/framework/browser/'.$kind.'.pem';
            ensure(is_file($path) && !is_link($path), 'Missing synthetic browser signing key');
            config(['passport.'.$kind.'_key' => file_get_contents($path)]);
        }
        // PR #645 permits exactly this synthetic notification intent. This is a
        // strict recording port, not a mailer or worker; all other calls fail.
        $mail = \Mockery::mock(\Illuminate\Mail\MailManager::class);
        $mail->shouldReceive('to')->andReturnUsing(static function($recipient) {
            $fixture=json_decode(file_get_contents('/app/storage/framework/browser/fixture.json'),true,512,JSON_THROW_ON_ERROR);
            ensure($recipient instanceof \HMS\Entities\User && $recipient->getId()===$fixture['reader']
                && $recipient->getEmail()==='browser_reader@example.invalid', 'Unexpected browser notification recipient');
            $pending=\Mockery::mock(\Illuminate\Mail\PendingMail::class);
            $pending->shouldReceive('send')->once()->withArgs(static function($message) use ($recipient) {
                ensure($message instanceof \App\Mail\Tools\BookingCancelledForMaintenance
                    && $message instanceof \Illuminate\Contracts\Queue\ShouldQueue
                    && $message->fullName===$recipient->getFullname() && $message->toolName==='browser',
                    'Unexpected browser notification payload');
                return true;
            })->andReturnUsing(static function() use ($recipient) {
                file_put_contents('/app/storage/framework/browser/mail.jsonl',
                    json_encode(['recipient_id'=>$recipient->getId(),'tool'=>'browser'],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
            });
            return $pending;
        });
        \Illuminate\Support\Facades\Mail::swap($mail);
    }
    // URL resolution only. Assets themselves must be separately prepared and local.
    $app->instance(\Illuminate\Foundation\Mix::class, static function ($path, $directory='') {
        ensure($directory === '' && in_array($path, ['/css/app.css','/css/vue.css','/js/manifest.js','/js/vendor.js','/js/app.js'], true),
            'Unexpected browser asset lookup');
        return new \Illuminate\Support\HtmlString($path);
    });
    $events = $app['events'];
    $logger = $app->make(\App\Listeners\RoleUpdateLogger::class);
    $local = [\App\Events\Roles\UserAddedToRole::class => 'onUserAddedToRole',
              \App\Events\Roles\UserRemovedFromRole::class => 'onUserRemovedFromRole'];
    foreach ($local as $type => $method) {
        $events->forget($type);
        $events->listen($type, static fn($event) => $logger->$method($event));
    }
    $events->forget(\App\Events\Roles\RoleCreated::class);
    $events->listen(\App\Events\Roles\RoleCreated::class, static fn() => null);
    // EventFake intercepts these types before Laravel's ShouldBroadcast dispatch.
    // Replacing listeners alone would still attempt a queued broadcast first.
    \Illuminate\Support\Facades\Event::fake(browserBookingEvents());
    return $app;
}

function browserBookingEvents(): array
{
    return [\App\Events\Tools\NewBooking::class, \App\Events\Tools\BookingChanged::class,
            \App\Events\Tools\BookingCancelled::class];
}

function recordBrowserEvents(): void
{
    $path = '/app/storage/framework/browser/events.jsonl';
    ensure(is_dir(dirname($path)), 'Browser fixture directory missing');
    foreach (browserBookingEvents() as $type) {
        foreach (\Illuminate\Support\Facades\Event::dispatched($type) as $event) {
            file_put_contents($path, json_encode(['type' => basename(str_replace('\\','/',$type))], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        }
    }
}
