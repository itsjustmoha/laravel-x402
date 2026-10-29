<?php

declare(strict_types=1);

/*
 * Router for `php -S`: a tiny stand-in facilitator used by CurlHttpTransportTest.
 * It enforces the request shape of specs/x402-specification-v2.md section 7.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = (string) file_get_contents('php://input');

header('Content-Type: application/json');

switch ($path) {
    case '/echo':
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
            'testHeader' => $_SERVER['HTTP_X_TEST'] ?? null,
            'body' => $body,
        ]);
        break;

    case '/redirect':
        http_response_code(308);
        header('Location: /echo');
        break;

    case '/slow':
        sleep(1);
        echo '{}';
        break;

    case '/facilitator/verify':
    case '/facilitator/settle':
        $request = json_decode($body, true);
        $wellFormed = is_array($request)
            && ($request['x402Version'] ?? null) === 2
            && is_array($request['paymentPayload']['accepted'] ?? null)
            && ($request['paymentRequirements']['amount'] ?? null) === '10000'
            && $request['paymentPayload']['accepted'] == $request['paymentRequirements'];

        if (! $wellFormed) {
            http_response_code(400);
            echo json_encode(['isValid' => false, 'success' => false, 'invalidReason' => 'invalid_payload', 'errorReason' => 'invalid_payload']);
            break;
        }

        echo str_ends_with($path, 'verify')
            ? json_encode(['isValid' => true, 'payer' => $request['paymentPayload']['payload']['authorization']['from']])
            : json_encode(['success' => true, 'transaction' => '0x'.str_repeat('ab', 32), 'network' => 'eip155:84532', 'payer' => $request['paymentPayload']['payload']['authorization']['from']]);
        break;

    default:
        http_response_code(404);
        echo '{"error":"not found"}';
}
