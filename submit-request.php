<?php
declare(strict_types=1);

const RESTAURANT_PHONE = '+256709792543';
const RESTAURANT_WHATSAPP = 'whatsapp:+256709792543';

function respond(int $status, string $title, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $safeTitle . ' | Fresh Bites</title>';
    echo '<link rel="stylesheet" href="style.css"></head><body>';
    echo '<main class="container"><section class="reservation-note">';
    echo '<h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p>';
    echo '<p><a href="index.html">Home</a> | <a href="products.html">Menu</a> | ';
    echo '<a href="reservations.html">Reservations</a></p>';
    echo '</section></main></body></html>';
    exit;
}

function requiredField(string $key, int $maxLength): string
{
    $submittedValue = $_POST[$key] ?? '';
    if (!is_string($submittedValue)) {
        throw new InvalidArgumentException('Please check the required form fields and try again.');
    }

    $value = trim($submittedValue);
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

    if ($value === '' || strlen($value) > $maxLength) {
        throw new InvalidArgumentException('Please check the required form fields and try again.');
    }

    return $value;
}

function optionalField(string $key, int $maxLength): string
{
    $submittedValue = $_POST[$key] ?? '';
    if (!is_string($submittedValue)) {
        throw new InvalidArgumentException('Please check the form fields and try again.');
    }

    $value = trim($submittedValue);
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

    if (strlen($value) > $maxLength) {
        throw new InvalidArgumentException('One of the form fields is too long.');
    }

    return $value;
}

function sendTwilioMessage(
    string $sid,
    string $token,
    array $fields
): void {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is unavailable.');
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the Twilio request.');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $sid . ':' . $token,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Twilio connection failed: ' . $curlError);
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $decoded = json_decode($response, true);
        $providerMessage = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';
        throw new RuntimeException(
            'Twilio returned HTTP ' . $httpStatus . ($providerMessage !== '' ? ': ' . $providerMessage : '')
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, 'Method not allowed', 'Please submit this form from the Fresh Bites website.');
}

try {
    $submittedType = $_POST['request_type'] ?? '';
    $type = is_string($submittedType) ? $submittedType : '';
    if (!in_array($type, ['reservation', 'order'], true)) {
        throw new InvalidArgumentException('Please choose a valid request type.');
    }

    $name = requiredField('name', 100);
    $email = requiredField('email', 254);
    $phone = requiredField('phone', 25);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid email address.');
    }

    if (!preg_match('/^\+?[0-9\s().-]{7,25}$/', $phone)) {
        throw new InvalidArgumentException('Please enter a valid phone number.');
    }

    if ($type === 'reservation') {
        $date = requiredField('date', 10);
        $time = requiredField('time', 5);
        $guests = requiredField('guests', 10);
        $dateValue = DateTime::createFromFormat('!Y-m-d', $date);

        if (
            $dateValue === false ||
            $dateValue->format('Y-m-d') !== $date ||
            $date < date('Y-m-d') ||
            !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) ||
            !in_array($guests, ['1', '2', '3', '4', '5', '6', '7-plus'], true)
        ) {
            throw new InvalidArgumentException('Please check the reservation date, time, and guest count.');
        }

        $requestLabel = 'Reservation';
        $details = 'Date: ' . $date . ', time: ' . $time . ', guests: ' . $guests;
        $notes = optionalField('special-request', 1000);
    } else {
        $orderDetails = requiredField('order_details', 1200);
        $fulfillment = requiredField('fulfillment', 20);
        $deliveryAddress = optionalField('delivery_address', 500);

        if (!in_array($fulfillment, ['Pickup', 'Delivery'], true)) {
            throw new InvalidArgumentException('Please choose pickup or delivery.');
        }

        if ($fulfillment === 'Delivery' && $deliveryAddress === '') {
            throw new InvalidArgumentException('Please enter a delivery address.');
        }

        $requestLabel = 'Order';
        $details = $orderDetails . ' (' . strtolower($fulfillment) . ')';
        if ($deliveryAddress !== '') {
            $details .= '; address: ' . $deliveryAddress;
        }
        $notes = optionalField('notes', 500);
    }

    if ($notes !== '') {
        $details .= '; notes: ' . $notes;
    }

    $message = 'New Fresh Bites ' . $requestLabel . "\n"
        . 'Name: ' . $name . "\n"
        . 'Phone: ' . $phone . "\n"
        . 'Email: ' . $email . "\n"
        . 'Details: ' . $details;

    $sid = trim((string)getenv('TWILIO_ACCOUNT_SID'));
    $token = trim((string)getenv('TWILIO_AUTH_TOKEN'));
    $smsFrom = trim((string)getenv('TWILIO_SMS_FROM'));
    $whatsappFrom = trim((string)getenv('TWILIO_WHATSAPP_FROM'));
    $whatsappContentSid = trim((string)getenv('TWILIO_WHATSAPP_CONTENT_SID'));

    if ($sid === '' || $token === '' || $smsFrom === '' || $whatsappFrom === '' || $whatsappContentSid === '') {
        error_log('Fresh Bites notifications are not configured: one or more Twilio environment variables are missing.');
        respond(503, 'Notifications not ready', 'The restaurant notification service is not configured yet. Please call +256 709792543 to place your request.');
    }

    $sent = [];
    $failures = [];

    try {
        sendTwilioMessage($sid, $token, [
            'From' => $smsFrom,
            'To' => RESTAURANT_PHONE,
            'Body' => $message,
        ]);
        $sent[] = 'SMS';
    } catch (RuntimeException $exception) {
        error_log('Fresh Bites SMS notification failed: ' . $exception->getMessage());
        $failures[] = 'SMS';
    }

    try {
        sendTwilioMessage($sid, $token, [
            'From' => $whatsappFrom,
            'To' => RESTAURANT_WHATSAPP,
            'ContentSid' => $whatsappContentSid,
            'ContentVariables' => json_encode([
                '1' => $requestLabel,
                '2' => $name,
                '3' => $phone,
                '4' => $details,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $sent[] = 'WhatsApp';
    } catch (RuntimeException | JsonException $exception) {
        error_log('Fresh Bites WhatsApp notification failed: ' . $exception->getMessage());
        $failures[] = 'WhatsApp';
    }

    if (count($sent) === 0) {
        respond(502, 'Request not sent', 'We could not deliver your request to the restaurant. Please call +256 709792543.');
    }

    if (count($failures) > 0) {
        respond(202, 'Request sent', 'The restaurant received your request by ' . implode(' and ', $sent)
            . ', but the ' . implode(' and ', $failures) . ' notification could not be sent. Please do not submit it again; call the restaurant if you need confirmation.');
    }

    respond(200, 'Request sent', 'Your request was sent to Fresh Bites by SMS and WhatsApp. The team will contact you to confirm it.');
} catch (InvalidArgumentException $exception) {
    respond(400, 'Please check your details', $exception->getMessage());
} catch (Throwable $exception) {
    error_log('Fresh Bites request handler failed: ' . $exception->getMessage());
    respond(500, 'Something went wrong', 'We could not process your request. Please call +256 709792543.');
}
