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
 * Send email using PHPMailer with SMTP
 * 
 * @param string $recipient Email address of the recipient
 * @param string $subject Email subject
 * @param string $body Email body (plain text or HTML)
 * @param string $altBody Alternative plain text body (optional)
 * @return bool True if sent successfully, false otherwise
 */
function send_application_email($recipient, $subject, $body, $altBody = '') {
    $mail = new PHPMailer(true);

    try {
        // ==========================================
        // SMTP CONFIGURATION - CHANGE THESE VALUES
        // ==========================================
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';              // Gmail SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'businessauj@gmail.com';        // YOUR GMAIL ADDRESS
        $mail->Password   = 'bwus iwlw eeaf hpqs';         // YOUR 16-CHARACTER APP PASSWORD
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // ==========================================
        // SENDER & RECIPIENT
        // ==========================================
        $mail->setFrom('businessauj@gmail.com', 'Personal Finance Manager');
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