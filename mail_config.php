<?php
// ==========================================
// mail_config.php - SMTP Email Configuration
// ==========================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer (already in your project)
require_once 'src/Exception.php';
require_once 'src/PHPMailer.php';
require_once 'src/SMTP.php';

/**
 * Load environment variables from a .env file if they are not already set.
 *
 * @param string $path Absolute path to the .env file.
 */
function loadDotEnvFile(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $name = trim($parts[0]);
        $value = trim($parts[1]);
        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

/**
 * Send email using PHPMailer with SMTP
 * 
 * @param string $recipient Email address of the recipient
 * @param string $subject Email subject
 * @param string $body Email body (plain text or HTML)
 * @param string $altBody Alternative plain text body (optional)
 * @return bool True if sent successfully, false otherwise
 */
function send_application_email($recipient, $subject, $body, $altBody = '') {
    // Load environment variables from .env if they are not already loaded.
    loadDotEnvFile(__DIR__ . '/../.env');

    $mail = new PHPMailer(true);

    try {
        // ==========================================
        // SMTP CONFIGURATION - CHANGE THESE VALUES
        // ==========================================
        $mail->isSMTP();

        $smtpHostRaw = trim(getenv('SMTP_HOST') ?: '');
        $smtpPortEnv = trim(getenv('SMTP_PORT') ?: '587');
        $smtpUsername = trim(getenv('SMTP_USERNAME') ?: '');
        $smtpPassword = trim(getenv('SMTP_PASSWORD') ?: '');
        $smtpFromEmail = trim(getenv('SMTP_FROM_EMAIL') ?: $smtpUsername ?: 'noreply@localhost');

        if ($smtpHostRaw === '') {
            error_log('Mail Error: SMTP_HOST is not configured. Set SMTP_HOST in .env or supply a valid SMTP host.');
            return false;
        }

        // Support SMTP_HOST values like "smtp.gmail.com:587" or "localhost:25"
        $smtpHost = $smtpHostRaw;
        $smtpPort = $smtpPortEnv;
        if (preg_match('/^(.+?):(\d+)$/', $smtpHostRaw, $matches)) {
            $smtpHost = trim($matches[1]);
            $smtpPort = trim($matches[2]);
        }

        $mail->Host = $smtpHost;
        $mail->Port = max(1, (int)$smtpPort);

        $isLocalHost = in_array($smtpHost, ['localhost', '127.0.0.1'], true);
        $mail->SMTPAuth = $smtpUsername !== '' && $smtpPassword !== '';
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;

        error_log(sprintf('Mail Debug: SMTP host=%s, port=%d, auth=%s', $mail->Host, $mail->Port, $mail->SMTPAuth ? 'yes' : 'no'));

        if ($mail->SMTPAuth) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = true;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        if (!$mail->SMTPAuth && !$isLocalHost) {
            error_log('Mail Error: SMTP authentication is disabled but SMTP_HOST is not localhost. Provide SMTP_USERNAME and SMTP_PASSWORD or use a local SMTP server.');
            return false;
        }

        // Allow self-signed certificates in local/dev environments
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        // ==========================================
        // SENDER & RECIPIENT
        // ==========================================
        $mail->setFrom($smtpFromEmail, 'Personal Finance Manager');
        $mail->addAddress($recipient);

        // ==========================================
        // EMAIL CONTENT
        // ==========================================
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody ?: strip_tags($body);

        // ==========================================
        // SEND THE EMAIL
        // ==========================================
        $mail->send();
        return true;

    } catch (Exception $e) {
        // Log error for debugging
        error_log("Mail Error: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send OTP email specifically for password reset
 * 
 * @param string $recipient User's email address
 * @param string $username  User's username (for personalization)
 * @param string $otp       6-digit OTP code
 * @return bool True if sent successfully
 */
function send_otp_email($recipient, $username, $otp) {
    $subject = '🔐 Password Reset OTP - Personal Finance Manager';
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #1464ff; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
            .otp-box { 
                background: #eef4ff; 
                padding: 20px; 
                text-align: center; 
                border-radius: 10px;
                font-size: 36px;
                letter-spacing: 10px;
                font-weight: bold;
                color: #1464ff;
                border: 2px dashed #1464ff;
                margin: 20px 0;
            }
            .footer { text-align: center; font-size: 12px; color: #888; margin-top: 20px; }
            .warning { color: #dc3545; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>💰 Personal Finance Manager</h2>
            </div>
            <div class='content'>
                <h3>Hello {$username}!</h3>
                <p>You requested a password reset for your Personal Finance Manager account.</p>
                <p>Use the One-Time Password (OTP) below to reset your password:</p>
                <div class='otp-box'>{$otp}</div>
                <p><strong>This OTP expires in 1 hour.</strong></p>
                <p>If you did not request this password reset, please ignore this email.</p>
                <hr>
                <p class='warning'>⚠️ For security, never share this OTP with anyone.</p>
            </div>
            <div class='footer'>
                <p>Personal Finance Manager &bull; Rajarata University of Sri Lanka</p>
                <p>This is an automated email. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $altBody = "Hello {$username},\n\n";
    $altBody .= "You requested a password reset for your Personal Finance Manager account.\n\n";
    $altBody .= "Your OTP is: {$otp}\n\n";
    $altBody .= "This OTP expires in 1 hour.\n\n";
    $altBody .= "If you did not request this, please ignore this email.\n";
    
    return send_application_email($recipient, $subject, $body, $altBody);
}
?>