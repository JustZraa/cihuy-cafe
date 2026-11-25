<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env.php';

function send_email(string $recipient, string $subject, string $body): void
{
    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('A valid recipient email address is required.');
    }
    if (str_contains($subject, "\r") || str_contains($subject, "\n")) {
        throw new InvalidArgumentException('The email subject cannot contain line breaks.');
    }

    $host = cozycafe_env('SMTP_HOST');
    $port = cozycafe_env('SMTP_PORT', '587');
    $username = cozycafe_env('SMTP_USER');
    $password = cozycafe_env('SMTP_PASS');
    $fromName = cozycafe_env('SMTP_FROM_NAME', 'CozyCafe');

    if (
        $host === null
        || !preg_match('/\A[A-Za-z0-9.-]+\z/', $host)
        || $port === null
        || !ctype_digit($port)
        || !in_array((int) $port, [465, 587], true)
        || $username === null
        || filter_var($username, FILTER_VALIDATE_EMAIL) === false
        || $password === null
        || $password === ''
        || $fromName === null
        || $fromName === ''
    ) {
        throw new RuntimeException('SMTP configuration is incomplete or invalid.');
    }

    $portNumber = (int) $port;
    $transport = $portNumber === 465 ? 'ssl' : 'tcp';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ],
    ]);

    $socket = @stream_socket_client(
        $transport . '://' . $host . ':' . $portNumber,
        $errorCode,
        $errorMessage,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if ($socket === false) {
        throw new RuntimeException(
            'Unable to connect to the configured SMTP server (' . $errorCode . '): ' . $errorMessage
        );
    }

    try {
        if (!stream_set_timeout($socket, 15)) {
            throw new RuntimeException('Unable to configure the SMTP connection timeout.');
        }

        cozycafe_smtp_read_response($socket, [220]);
        cozycafe_smtp_command($socket, 'EHLO cozycafe.local', [250]);

        if ($portNumber === 587) {
            cozycafe_smtp_command($socket, 'STARTTLS', [220]);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('Unable to establish a verified TLS connection to the SMTP server.');
            }
            cozycafe_smtp_command($socket, 'EHLO cozycafe.local', [250]);
        }

        cozycafe_smtp_command($socket, 'AUTH LOGIN', [334]);
        cozycafe_smtp_command($socket, base64_encode($username), [334]);
        cozycafe_smtp_command($socket, base64_encode($password), [235]);
        cozycafe_smtp_command($socket, 'MAIL FROM:<' . $username . '>', [250]);
        cozycafe_smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        cozycafe_smtp_command($socket, 'DATA', [354]);

        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $messageId = bin2hex(random_bytes(16)) . '@' . $host;
        $message = implode("\r\n", [
            'From: ' . $encodedFromName . ' <' . $username . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $encodedSubject,
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($body), 76, "\r\n")),
            '.',
            '',
        ]);
        cozycafe_smtp_write($socket, $message);
        cozycafe_smtp_read_response($socket, [250]);
        cozycafe_smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function cozycafe_smtp_command(mixed $socket, string $command, array $expectedCodes): string
{
    if (str_contains($command, "\r") || str_contains($command, "\n")) {
        throw new InvalidArgumentException('SMTP commands cannot contain line breaks.');
    }

    cozycafe_smtp_write($socket, $command . "\r\n");
    return cozycafe_smtp_read_response($socket, $expectedCodes);
}

function cozycafe_smtp_write(mixed $socket, string $data): void
{
    $length = strlen($data);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($socket, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('The SMTP server connection closed while sending data.');
        }
        $offset += $written;
    }
}

function cozycafe_smtp_read_response(mixed $socket, array $expectedCodes): string
{
    $response = '';
    $lineCount = 0;

    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            $metadata = stream_get_meta_data($socket);
            $reason = !empty($metadata['timed_out']) ? 'The SMTP server response timed out.' : 'The SMTP server closed the connection.';
            throw new RuntimeException($reason);
        }

        $response .= $line;
        $lineCount++;
        if ($lineCount > 100 || preg_match('/\A([0-9]{3})([ -])/', $line, $matches) !== 1) {
            throw new RuntimeException('The SMTP server returned a malformed response.');
        }
    } while ($matches[2] === '-');

    $responseCode = (int) $matches[1];
    if (!in_array($responseCode, $expectedCodes, true)) {
        throw new RuntimeException('The SMTP server rejected a command with response code ' . $responseCode . '.');
    }

    return $response;
}
