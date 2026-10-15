<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;

final class TransportGuard
{
    /** Strict mocks reject unexpected calls. Tests may explicitly expect/record selected calls. */
    public static function install(): array
    {
        Http::preventStrayRequests();
        $mocks = [];
        foreach ([
            Mail::class => \Illuminate\Mail\MailManager::class,
            Notification::class => \Illuminate\Notifications\ChannelManager::class,
            Broadcast::class => \Illuminate\Broadcasting\BroadcastManager::class,
            Queue::class => \Illuminate\Queue\QueueManager::class,
            Bus::class => \Illuminate\Bus\Dispatcher::class,
        ] as $facade => $type) {
            $mocks[$facade] = Mockery::mock($type);
            $facade::swap($mocks[$facade]);
        }

        return $mocks;
    }
}
