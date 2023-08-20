<?php

namespace Valtzu\StreamWrapper;

use Exception;
use RuntimeException;

/**
 * @property resource $context
 */
class WebsocketStreamWrapper
{
    /** @var resource $stream */
    private $stream;
    private string $buffer = '';
    private string $key;

    /**
     * @throws Exception
     */
    public function stream_open($path, $mode, $options, &$opened_path): bool
    {
        $this->key = base64_encode(random_bytes(16));
        $headers = [
            'Connection: Upgrade',
            'Upgrade: websocket',
            'Sec-Websocket-Version: 13',
            "Sec-Websocket-Key: $this->key",
        ];

        stream_context_set_option($this->context, ['http' => ['ignore_errors' => true, 'header' => $headers]]);
        if (!$this->stream = fopen($opened_path = 'http' . substr($path, 2), 'r', context: $this->context)) {
            return false;
        }

        $headers = stream_get_meta_data($this->stream)['wrapper_data'];
        if (!$this->isValidHandshakeResponse($headers)) {
            @fclose($this->stream);
            return false;
        }

        return true;
    }

    public function stream_read($count): string|false
    {
        $blocking = stream_get_meta_data($this->stream)['blocked'];

        do {
            $payload = '';
            $opcode = null;
            do {
                $header = $this->readExact(2);
                if ($opcode === null && $header === '') {
                    return '';
                }
                $opcode ??= $header[0] & "\x0f";
                [, $payloadLength] = match ($payloadLength = ($header[1] & "\x7f")) {
                    "\x7e" => unpack('n', $this->readExact(2)),
                    "\x7f" => unpack('J', $this->readExact(8)),
                    default => unpack('C', $payloadLength),
                };

                $maskingKey = ($header[1] & "\x80") === "\x80" ? $this->readExact(4) : "\x00\x00\x00\x00";
                if ($payloadLength > 0) {
                    $payload .= $this->readExact($payloadLength) ^ str_repeat($maskingKey, ($payloadLength >> 2) + 1);
                }
            } while (!($header[0] & "\x80"));

            $this->reply($opcode, $payload);

        } while (!in_array($opcode, ["\1", "\2"]) && $blocking);

        $buffer = substr($this->buffer, 0, $count);
        $this->buffer = substr($this->buffer, $count) . substr($payload, $left = $count - strlen($buffer));

        return $buffer . substr($payload, 0, $left);
    }

    public function stream_cast()
    {
        return $this->stream;
    }

    public function stream_write($data): int|false
    {
        if ($this->write("\x01", $data) === false) {
            return false;
        }

        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return feof($this->stream);
    }

    public function stream_close(): void
    {
        if (gettype($this->stream) === 'resource') {
            stream_set_blocking($this->stream, true);
            $this->write("\x08", "\x03\xE9"); // close with going-away status
            $this->readExact(2);
            fclose($this->stream);
        }
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return match ($option) {
            STREAM_OPTION_BLOCKING => stream_set_blocking($this->stream, (bool)$arg1),
            STREAM_OPTION_WRITE_BUFFER => stream_set_write_buffer($this->stream, $arg2) === 0,
            STREAM_OPTION_READ_TIMEOUT => stream_set_timeout($this->stream, $arg1, $arg2),
            default => false,
        };
    }

    private function readExact(int $length, bool $throw = true): string
    {
        for ($data = '', $left = $length; $left > 0; $data .= $buffer, $left -= strlen($buffer)) {
            $buffer = fread($this->stream, $left);
            if ($this->handleReadError($buffer, $length, $left, $throw) === '') {
                return '';
            }
        }

        return $data;
    }

    private function handleReadError(string|false $readResult, int $requestedBytes, int $bytesLeft, bool $throw = false): string
    {
        if ($readResult === false) {
            if (stream_get_meta_data($this->stream)['timed_out'] ?? false) {
                throw new RuntimeException('Read timeout');
            }

            throw new RuntimeException("Wanted to read $requestedBytes bytes but missed $bytesLeft");
        }

        if ($readResult === '' && $throw && $requestedBytes !== $bytesLeft) {
            throw new RuntimeException("Empty read; connection dead?");
        }

        return $readResult;
    }

    private function reply(string $opcode, string $payload): int|false
    {
        return match ($opcode) {
            "\x09" => $this->write("\x10", $payload),
            default => 0,
        };
    }

    /**
     * @throws Exception
     */
    private function write(string $opCode, string $payload): int|false
    {
        $payloadLength = strlen($payload);

        return fwrite(
            $this->stream,
            ("\x80" | $opCode)
            . match (true) {
                $payloadLength < 0x7e => pack('C', $payloadLength | 0x80),
                $payloadLength <= 0xffff => pack('Cn', 0xfe, $payloadLength),
                default => pack('CJ', 0xff, $payloadLength),
            }
            . ($maskingKey = random_bytes(4))
            . ($payload ^ str_repeat($maskingKey, ($payloadLength >> 2) + 1)),
        );
    }

    private function isValidHandshakeResponse(array $headers): bool
    {
        if (!str_starts_with(array_shift($headers), 'HTTP/1.1 101 ')) {
            return false;
        }

        $headers = array_map(fn ($header) => explode(': ', $header, 2), $headers);
        $headers = array_combine(array_map(strtolower(...), array_column($headers, 0)), array_column($headers, 1));

        return ($headers['upgrade'] ?? null) === 'websocket'
            || ($headers['connection'] ?? null) === 'upgrade'
            || ($headers['sec-websocket-accept'] ?? null) === base64_encode(sha1("{$this->key}258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true))
            ;
    }
}
