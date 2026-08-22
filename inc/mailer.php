<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../vendor/autoload.php'; // PHPMailer via Composer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email via SendGrid SMTP using PHPMailer
 *
 * @param string       $to_email     Recipient email address
 * @param string       $to_name      Recipient display name
 * @param string       $subject      Email subject line
 * @param string       $body_html    HTML email body
 * @param string       $body_text    Plain text fallback (optional)
 * @param array        $cc_emails    Optional list of CC addresses ['email@x.com', ...]
 * @param string       $error_detail Populated with error info on failure
 * @param string       $from_email   From address (defaults to logged-in user's email).
 *                                   NOTE: must be on a domain authorized in SendGrid.
 *                                   All @wbaspen.com addresses are covered by the domain auth.
 * @param string       $from_name    From display name (defaults to logged-in user's full name)
 * @return bool
 */
function send_email(string $to_email, string $to_name, string $subject, string $body_html, string $body_text = '', array $cc_emails = [], string &$error_detail = '', string $from_email = '', string $from_name = ''): bool
{
    $mail = new PHPMailer(true);

    try {
        // SendGrid SMTP settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.sendgrid.net';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'apikey'; // SendGrid requires the literal string 'apikey'
        $mail->Password   = SENDGRID_API_KEY;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // From: use the logged-in user's email from the session (or explicit override for cron).
        // Agent and system addresses must be on a domain authorized in SendGrid.
        $from_email = $from_email ?: ($_SESSION['user_email']      ?? 'noreply@monthausint.com');
        $from_name  = $from_name  ?: trim(($_SESSION['user_first_name'] ?? '') . ' ' . ($_SESSION['user_last_name'] ?? '')) ?: 'Mont Haus';
        $mail->setFrom($from_email, $from_name);
        $mail->addReplyTo($from_email, $from_name);

        // Primary recipient
        $mail->addAddress($to_email, $to_name);

        // CC addresses (hard-coded per template in the email_templates.cc_emails column)
        foreach ($cc_emails as $cc) {
            $cc = trim($cc);
            if ($cc !== '' && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                $mail->addCC($cc);
            }
        }

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body_html;
        $mail->AltBody = $body_text ?: strip_tags($body_html); // fallback to stripped HTML if no plain text provided

        $mail->send();
        return true;

    } catch (Exception $e) {
        $error_detail = $mail->ErrorInfo ?: $e->getMessage();
        error_log('Mailer Error: ' . $error_detail);
        return false;
    }
}


/**
 * Strip the category prefix from an email subject for actual sending.
 * The UI shows prefixes like "Listings:", "Open House:", "Rental:" for clarity,
 * but they should be omitted from the delivered email subject line.
 *
 * @param string $subject       Full subject as composed in the UI
 * @param string $template_file Template filename, e.g. 'new_listing_template.php'
 * @return string               Subject with the category prefix removed
 */
function strip_subject_prefix(string $subject, string $template_file): string {
    $prefixes = [
        'new_listing_template.php'   => 'Listings: ',
        'price_change_template.php'  => 'Listings: ',
        'open_house_template.php'    => 'Open House: ',
        'open_canceled_template.php' => 'Open House: ',
        'new_rental_template.php'    => 'Rental: ',
        're-rental_template.php'     => 'Rental: ',
    ];
    $prefix = $prefixes[$template_file] ?? '';
    if ($prefix !== '' && str_starts_with($subject, $prefix)) {
        return trim(substr($subject, strlen($prefix)));
    }
    return $subject;
}


// --- EXAMPLE USAGE ---
// Uncomment below to test a single send

// $sent = send_email(
//     'recipient@example.com',
//     'Recipient Name',
//     'Test Email from SendGrid',
//     '<h1>Hello!</h1><p>This is a test email sent via SendGrid + PHPMailer.</p>',
// );
//
// echo $sent ? 'Email sent successfully.' : 'Email failed — check error log.';
