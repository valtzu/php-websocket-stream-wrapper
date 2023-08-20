<?php

namespace Valtzu\StreamWrapper\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('internet')]
class PostmanEchoServiceTest extends TestCase
{
    #[Test]
    public function synchronousOpenSendAndReceive()
    {
        $socket = fopen('wss://ws.postman-echo.com/raw', 'r+');

        $this->assertIsResource($socket);
        $this->assertSame(11, fwrite($socket, 'Hello world'));
        $this->assertSame('Hello world', fread($socket, 128));
        $this->assertTrue(fclose($socket));
    }

    #[Test]
    public function asynchronousOpenSendAndReceive()
    {
        $socket = fopen('wss://ws.postman-echo.com/raw', 'r+');
        stream_set_blocking($socket, false);

        $this->assertIsResource($socket);
        $this->assertSame(11, fwrite($socket, 'Hello world'));
        $this->assertSame('', fread($socket, 128));
        $read = [$socket];
        $this->assertSame(1, stream_select($read, $write, $except, null));
        $this->assertSame('Hello world', fread($socket, 128));
        $this->assertSame('', fread($socket, 128));
        $this->assertTrue(fclose($socket));
    }
}
