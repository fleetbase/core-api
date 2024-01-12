<?php

namespace Fleetbase\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

class TestEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $subject = '🎉 Your Fleetbase Mail Configuration Works!';

        return $this
            ->subject($subject)
            ->html((new MailMessage())
                    ->greeting($subject)
                    ->line('Hello! This is a test email from Fleetbase to confirm that your mail configuration works.')
                    ->render()
            );
    }
}
