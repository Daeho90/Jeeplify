<?php
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$mail = new PHPMailer(true);
try {
    $mail->SMTPDebug  = 2; // Show full debug output
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = getenv('SMTP_USERNAME');
    $mail->Password   = getenv('SMTP_PASSWORD');
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom(getenv('SMTP_USERNAME'), 'Jeeplify BCD');
    $mail->addAddress('itsmejoeven18@gmail.com'); // your own email

    $mail->Subject = 'Jeeplify SMTP Test';
    $mail->Body    = 'If you see this, SMTP is working!';

    $mail->send();
    echo 'SUCCESS: Email sent!';
} catch (PHPMailerException $e) {
    echo 'FAILED: ' . $mail->ErrorInfo;
}
?>