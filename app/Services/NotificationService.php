<?php
declare(strict_types=1);
namespace App\Services;

use App\Config\Mail;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

final class NotificationService
{
    public function sendSignalEmail(string $subject, string $body): bool
    {
        if (trim(Mail::host()) === '' || trim(Mail::alertTo()) === '') {
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = Mail::host();
            $mail->Port       = Mail::port();
            $mail->SMTPAuth   = true;
            $mail->Username   = Mail::username();
            $mail->Password   = Mail::password();
            $mail->SMTPSecure = Mail::encryption();
            $mail->setFrom(Mail::fromAddress(), Mail::fromName());
            $mail->addAddress(Mail::alertTo());
            $mail->Subject    = $subject;
            $mail->Body       = $body;
            $mail->send();

            return true;

        } catch (Exception $e) {
            error_log('[NotificationService] Email failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Same SMTP setup as sendSignalEmail(), addressed to an arbitrary recipient — used by password reset. */
    public function sendTo(string $to, string $subject, string $body): bool
    {
        if (trim(Mail::host()) === '' || trim($to) === '') {
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = Mail::host();
            $mail->Port       = Mail::port();
            $mail->SMTPAuth   = true;
            $mail->Username   = Mail::username();
            $mail->Password   = Mail::password();
            $mail->SMTPSecure = Mail::encryption();
            $mail->setFrom(Mail::fromAddress(), Mail::fromName());
            $mail->addAddress($to);
            $mail->Subject    = $subject;
            $mail->Body       = $body;
            $mail->send();

            return true;

        } catch (Exception $e) {
            error_log('[NotificationService] Email to ' . $to . ' failed: ' . $e->getMessage());
            return false;
        }
    }
}
