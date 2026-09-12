<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Delivers the password-reset link.
 *
 * Password reset is a **link**, deliberately unlike email verification's code
 * (v0.3 boards 06 and 07). The two flows differ so that a player who has
 * received one cannot confuse it with the other, and so that a reset cannot be
 * completed by reading a six-digit number aloud.
 *
 * The link points at the **frontend**, not at this API: the player lands on a
 * Nuxt page, which posts the token back through the BFF. A link to this service
 * would open a page it does not have. The origin comes from configuration
 * (`config/frontend.php`) so that `.test` in development and a real host in
 * production are one environment variable apart.
 *
 * The email address is carried in the URL alongside the token because Laravel's
 * password broker verifies the pair — the token alone does not identify an
 * account.
 */
final class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token)
    {
        // Only after commit — see VerifyEmailNotification.
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
        /** @var User $notifiable */
        $minutes = (int) Config::integer('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Purrenade — reset your password')
            ->greeting('Password reset')
            ->line('You asked to reset your Purrenade password.')
            ->action('Choose a new password', $this->resetUrl($notifiable->email))
            ->line("The link expires in {$minutes} minutes and can be used once.")
            ->line('Your two-factor authentication is unaffected — resetting a password never changes it.')
            ->line('If you did not request this, no action is needed and your password has not changed.');
    }

    private function resetUrl(string $email): string
    {
        $base = rtrim(Config::string('frontend.url'), '/');
        $path = '/'.ltrim(Config::string('frontend.password_reset_path'), '/');

        return $base.$path.'?'.http_build_query([
            'token' => $this->token,
            'email' => $email,
        ]);
    }
}
