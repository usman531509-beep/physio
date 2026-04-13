<?php
// ═══════════════════════════════════════════════
//  Thames Physio Services – Booking Form Handler
//  Uses SMTP for reliable delivery on Hostinger
// ═══════════════════════════════════════════════

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://thamesphysioservices.com');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ══════════════════════════════════════
//  SMTP CONFIGURATION — UPDATE PASSWORD
// ══════════════════════════════════════
$smtp_host = 'smtp.hostinger.com';
$smtp_port = 465;
$smtp_user = 'info@thamesphysioservices.com';
$smtp_pass = 'Physio675@';  // ← PUT YOUR EMAIL PASSWORD HERE

$clinic_email = 'info@thamesphysioservices.com';
$clinic_name  = 'Thames Physio Services';
$site_url     = 'https://thamesphysioservices.com';

// ── Collect & sanitize form data ──
$name         = htmlspecialchars(trim($_POST['name'] ?? ''));
$email        = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$phone        = htmlspecialchars(trim($_POST['phone'] ?? ''));
$booking_type = htmlspecialchars(trim($_POST['booking_type'] ?? ''));
$service      = htmlspecialchars(trim($_POST['service'] ?? ''));
$date         = htmlspecialchars(trim($_POST['date'] ?? ''));
$time         = htmlspecialchars(trim($_POST['time'] ?? ''));
$message      = htmlspecialchars(trim($_POST['message'] ?? ''));

// ── Validation ──
$errors = [];
if (empty($name))    $errors[] = 'Name is required';
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required';
if (empty($phone))   $errors[] = 'Phone number is required';
if (empty($service) || $service === 'Service Type') $errors[] = 'Please select a service type';

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

// Format date for display
$formatted_date = !empty($date) ? date('l, j F Y', strtotime($date)) : 'Not specified';
$formatted_time = (!empty($time) && $time !== 'Preferred Time') ? $time : 'Not specified';


// ════════════════════════════════════
//  SMTP MAILER (no external libraries)
// ════════════════════════════════════
function smtp_send($host, $port, $user, $pass, $from_email, $from_name, $to_email, $subject, $html_body, $reply_to_email = '', $reply_to_name = '') {
    
    $socket = @stream_socket_client(
        "ssl://{$host}:{$port}",
        $errno, $errstr, 30,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
    );
    
    if (!$socket) {
        error_log("SMTP connection failed: {$errno} - {$errstr}");
        return false;
    }
    
    // Helper to send command and get response
    $getResponse = function() use ($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) == ' ') break;
        }
        return $response;
    };
    
    $sendCmd = function($cmd) use ($socket, $getResponse) {
        fwrite($socket, $cmd . "\r\n");
        return $getResponse();
    };
    
    // Read greeting
    $getResponse();
    
    // EHLO
    $sendCmd("EHLO thamesphysioservices.com");
    
    // AUTH LOGIN
    $sendCmd("AUTH LOGIN");
    $sendCmd(base64_encode($user));
    $authResult = $sendCmd(base64_encode($pass));
    
    if (strpos($authResult, '235') === false) {
        error_log("SMTP auth failed: " . trim($authResult));
        fclose($socket);
        return false;
    }
    
    // MAIL FROM
    $sendCmd("MAIL FROM:<{$from_email}>");
    
    // RCPT TO
    $rcptResult = $sendCmd("RCPT TO:<{$to_email}>");
    if (strpos($rcptResult, '250') === false) {
        error_log("SMTP RCPT failed: " . trim($rcptResult));
        fclose($socket);
        return false;
    }
    
    // DATA
    $sendCmd("DATA");
    
    // Build email headers + body
    $boundary = md5(uniqid(time()));
    $reply_to = $reply_to_email ? "{$reply_to_name} <{$reply_to_email}>" : "{$from_name} <{$from_email}>";
    
    $emailData  = "From: {$from_name} <{$from_email}>\r\n";
    $emailData .= "To: <{$to_email}>\r\n";
    $emailData .= "Reply-To: {$reply_to}\r\n";
    $emailData .= "Subject: {$subject}\r\n";
    $emailData .= "MIME-Version: 1.0\r\n";
    $emailData .= "Content-Type: text/html; charset=UTF-8\r\n";
    $emailData .= "X-Mailer: ThamesPhysio/1.0\r\n";
    $emailData .= "\r\n";
    $emailData .= $html_body . "\r\n";
    $emailData .= ".";
    
    $dataResult = $sendCmd($emailData);
    
    // QUIT
    $sendCmd("QUIT");
    fclose($socket);
    
    $success = (strpos($dataResult, '250') !== false);
    if (!$success) {
        error_log("SMTP send failed: " . trim($dataResult));
    }
    return $success;
}


// ════════════════════════════════════
//  BUILD HTML EMAILS
// ════════════════════════════════════

// ── Email TO the clinic ──
$clinic_subject = "New Booking Request - {$name} | {$service}";

$clinic_html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <div style="max-width:600px;margin:2rem auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:2rem 2.5rem;text-align:center;">
      <h1 style="margin:0;color:#fff;font-size:1.5rem;font-weight:700;">New Booking Request</h1>
      <p style="margin:.5rem 0 0;color:#93c5fd;font-size:.9rem;">Thames Physio Services</p>
    </div>
    <div style="padding:2rem 2.5rem;">
      <p style="margin:0 0 1.5rem;color:#475569;font-size:.95rem;line-height:1.6;">
        A new appointment request has been submitted through the website. Details below:
      </p>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;">
        <h2 style="margin:0 0 1rem;color:#0f172a;font-size:1rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Patient Details</h2>
        <table style="width:100%;border-collapse:collapse;">
          <tr><td style="padding:.5rem 0;color:#64748b;font-size:.85rem;width:120px;">Name</td><td style="padding:.5rem 0;color:#0f172a;font-size:.95rem;font-weight:600;">{$name}</td></tr>
          <tr><td style="padding:.5rem 0;color:#64748b;font-size:.85rem;">Email</td><td style="padding:.5rem 0;color:#0f172a;font-size:.95rem;"><a href="mailto:{$email}" style="color:#1d4ed8;text-decoration:none;">{$email}</a></td></tr>
          <tr><td style="padding:.5rem 0;color:#64748b;font-size:.85rem;">Phone</td><td style="padding:.5rem 0;color:#0f172a;font-size:.95rem;"><a href="tel:{$phone}" style="color:#1d4ed8;text-decoration:none;">{$phone}</a></td></tr>
        </table>
      </div>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;">
        <h2 style="margin:0 0 1rem;color:#0f172a;font-size:1rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Appointment Details</h2>
        <table style="width:100%;border-collapse:collapse;">
          <tr><td style="padding:.5rem 0;color:#64748b;font-size:.85rem;width:120px;">Request Type</td><td style="padding:.5rem 0;color:#0f172a;font-size:.95rem;font-weight:600;">{$booking_type}</td></tr>
          <tr><td style="padding:.5rem 0;color:#64748b;font-size:.85rem;">Service</td><td style="padding:.5rem 0;color:#0f172a;font-size:.95rem;font-weight:600;">{$service}</td></tr>
          <tr><td style="padding:.5rem 0;color:#64748b;font-size:.85rem;">Preferred Date</td><td style="padding:.5rem 0;color:#0f172a;font-size:.95rem;">{$formatted_date}</td></tr>
          <tr><td style="padding:.5rem 0;color:#64748b;font-size:.85rem;">Preferred Time</td><td style="padding:.5rem 0;color:#0f172a;font-size:.95rem;">{$formatted_time}</td></tr>
        </table>
      </div>
HTML;

if (!empty($message)) {
    $clinic_html .= <<<HTML
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;">
        <h2 style="margin:0 0 .75rem;color:#0f172a;font-size:1rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Patient's Message</h2>
        <p style="margin:0;color:#334155;font-size:.95rem;line-height:1.7;white-space:pre-wrap;">{$message}</p>
      </div>
HTML;
}

$clinic_html .= <<<HTML
      <div style="text-align:center;margin-top:1.5rem;">
        <a href="mailto:{$email}?subject=Re: Your Booking Request - Thames Physio Services" style="display:inline-block;background:#1d4ed8;color:#fff;padding:.75rem 2rem;border-radius:9999px;text-decoration:none;font-weight:600;font-size:.9rem;">Reply to Patient</a>
        <a href="tel:{$phone}" style="display:inline-block;background:#0f172a;color:#fff;padding:.75rem 2rem;border-radius:9999px;text-decoration:none;font-weight:600;font-size:.9rem;margin-left:.5rem;">Call Patient</a>
      </div>
    </div>
    <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:1.25rem 2.5rem;text-align:center;">
      <p style="margin:0;color:#94a3b8;font-size:.8rem;">This email was sent from the booking form at <a href="{$site_url}" style="color:#1d4ed8;">thamesphysioservices.com</a></p>
    </div>
  </div>
</body>
</html>
HTML;


// ── Confirmation email TO the patient ──
$patient_subject = "Booking Confirmed - Thames Physio Services";

$patient_html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <div style="max-width:600px;margin:2rem auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:2.5rem;text-align:center;">
      <h1 style="margin:0;color:#fff;font-size:1.5rem;font-weight:700;">Booking Request Received!</h1>
      <p style="margin:.75rem 0 0;color:#93c5fd;font-size:.95rem;line-height:1.5;">Thank you, {$name}. We'll get back to you shortly.</p>
    </div>
    <div style="padding:2rem 2.5rem;">
      <p style="margin:0 0 1.5rem;color:#475569;font-size:.95rem;line-height:1.7;">
        We've received your appointment request and our team will confirm your booking within <strong>30 minutes</strong> during business hours (Mon-Sat, 08:00-19:00).
      </p>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;">
        <h2 style="margin:0 0 1rem;color:#1d4ed8;font-size:1rem;font-weight:700;">Your Booking Summary</h2>
        <table style="width:100%;border-collapse:collapse;">
          <tr><td style="padding:.5rem 0;color:#64748b;font-size:.85rem;width:130px;">Service</td><td style="padding:.5rem 0;color:#0f172a;font-size:.95rem;font-weight:600;">{$service}</td></tr>
          <tr><td style="padding:.5rem 0;color:#64748b;font-size:.85rem;">Preferred Date</td><td style="padding:.5rem 0;color:#0f172a;font-size:.95rem;">{$formatted_date}</td></tr>
          <tr><td style="padding:.5rem 0;color:#64748b;font-size:.85rem;">Preferred Time</td><td style="padding:.5rem 0;color:#0f172a;font-size:.95rem;">{$formatted_time}</td></tr>
        </table>
      </div>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;">
        <h2 style="margin:0 0 .75rem;color:#0f172a;font-size:1rem;font-weight:700;">What Happens Next?</h2>
        <ol style="margin:0;padding-left:1.25rem;color:#475569;font-size:.9rem;line-height:1.8;">
          <li>Our team reviews your request</li>
          <li>We contact you to confirm date and time</li>
          <li>Your physiotherapist visits you at home</li>
        </ol>
      </div>
      <p style="margin:0;color:#475569;font-size:.9rem;line-height:1.7;">
        Need to reach us sooner? Call <a href="tel:07745670210" style="color:#1d4ed8;text-decoration:none;font-weight:600;">07745 670210</a> 
        or email <a href="mailto:info@thamesphysioservices.com" style="color:#1d4ed8;text-decoration:none;font-weight:600;">info@thamesphysioservices.com</a>
      </p>
    </div>
    <div style="background:#0f172a;padding:1.5rem 2.5rem;text-align:center;">
      <p style="margin:0 0 .5rem;color:#fff;font-size:.95rem;font-weight:700;">Thames Physio Services</p>
      <p style="margin:0;color:#64748b;font-size:.8rem;">29-39 London Road, Twickenham</p>
      <p style="margin:.5rem 0 0;color:#64748b;font-size:.75rem;">© 2026 Thames Physio Services Limited. All rights reserved.</p>
    </div>
  </div>
</body>
</html>
HTML;


// ════════════════════════════════════
//  SEND BOTH EMAILS VIA SMTP
// ════════════════════════════════════

// 1. Send booking notification TO clinic
$clinic_sent = smtp_send(
    $smtp_host, $smtp_port, $smtp_user, $smtp_pass,
    $clinic_email, $clinic_name,       // from
    $clinic_email,                      // to (clinic receives it)
    $clinic_subject, $clinic_html,
    $email, $name                       // reply-to (patient)
);

// 2. Send confirmation TO patient
$patient_sent = false;
if (!empty($email)) {
    $patient_sent = smtp_send(
        $smtp_host, $smtp_port, $smtp_user, $smtp_pass,
        $clinic_email, $clinic_name,   // from
        $email,                         // to (patient)
        $patient_subject, $patient_html,
        $clinic_email, $clinic_name     // reply-to (clinic)
    );
}

// Log results
error_log("BOOKING | {$name} ({$email}) | Clinic: " . ($clinic_sent ? 'OK' : 'FAIL') . " | Patient: " . ($patient_sent ? 'OK' : 'FAIL'));

if ($clinic_sent || $patient_sent) {
    echo json_encode([
        'success' => true,
        'message' => 'Your booking request has been sent successfully! We\'ll get back to you within 30 minutes during business hours.'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sorry, there was a problem sending your request. Please call us directly at 07745 670210.'
    ]);
}
?>
