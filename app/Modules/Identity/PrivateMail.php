<?php

namespace App\Modules\Identity;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class PrivateMail
{
    public static function send(string $email, string $subject, string $link): void
    {
        if (config('mail.default') === 'log') {
            throw new RuntimeException('Account mail requires a non-logging mail transport.');
        }

        try {
            Mail::raw("Open this link to continue:\n\n".$link."\n\nIf you did not request this message, you can ignore it.", function ($message) use ($email, $subject): void {
                $message->to($email)->subject($subject);
            });
        } catch (Throwable) {
            // Some mail transports include the contents of rejected messages in their exceptions.
            throw new RuntimeException('Account mail delivery failed.');
        }
    }

    public static function enqueue(object $job): void
    {
        try {
            Bus::dispatch($job);
        } catch (Throwable) {
            // A transport outage must not disclose whether a guest email exists.
            Log::error('emenu.mail.enqueue_failed', ['job' => get_class($job)]);
        }
    }
}
