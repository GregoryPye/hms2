<?php

namespace App\Mail\Tools;

use HMS\Entities\Tools\Tool;
use HMS\Entities\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookingCancelledForMaintenance extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @var string
     */
    public $fullName;

    /**
     * @var string
     */
    public $toolName;

    /**
     * Create a new message instance.
     *
     * @param User $user
     * @param Tool $tool
     *
     * @return void
     */
    public function __construct(User $user, Tool $tool)
    {
        $this->fullName = $user->getFullname();
        $this->toolName = $tool->getName();
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject(config('branding.space_name') . ': ' . $this->toolName . ' booking cancelled')
                    ->markdown('emails.tools.bookingCancelled');
    }
}
