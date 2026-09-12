<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Auth\EmailVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers the six-digit verification code.
 *
 * `ShouldQueue` with `afterCommit`, which is the APPROVED shape
 * (docs/security/authentication.md §3): dispatching inside the transaction that
 * created the user would send a code for a row that a later rollback removes,
 * and sending mail synchronously ties registration's response time to an SMTP
 * handshake.
 *
 * Queue-shaped does not mean a queue worker is required. Locally
 * `QUEUE_CONNECTION=sync` runs it inline, so there is nothing extra to start —
 * and production flips one environment variable rather than changing this class.
 *
 * Copy is intentionally plain. Player-facing copy in tr/en/es lives in the
 * frontend; what matters here is that the code arrives.
 */
final class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $code)
    {
        // Dispatch only once the surrounding transaction has committed.
        // Registration creates the user and issues the code in one transaction;
        // a mail sent inside it would deliver a code for a row a rollback then
        // removes. `Queueable` owns the property, so this is set through its
        // method rather than redeclared.
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = EmailVerificationService::TTL_MINUTES;

        return (new MailMessage)
            ->subject('Purrenade — your verification code')
            ->greeting('Welcome to Purrenade!')
            ->line('Your email verification code is:')
            ->line($this->code)
            ->line("The code expires in {$minutes} minutes.")
            ->line('If you did not create a Purrenade account, you can ignore this message.');
    }
}
