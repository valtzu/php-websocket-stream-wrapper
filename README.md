# WebSocket stream wrapper for PHP 8.1+

## How to use

```php

// All http & tls options work like usual
$context = stream_context_create([
  'http' => [...],
  'ssl' => [...],
]);

$socket = fopen('wss://ws.postman-echo.com/raw', 'r+', context: $context);

// Non-blocking mode also supported!
// stream_set_blocking($socket, false);

fwrite($socket, 'Hello world');
echo "Received: ", fread($socket, 128), PHP_EOL;

fclose($socket);
```

## How it works internally

Turns out even though you can't open `http` stream wrapper in write mode, you can still write into the socket. So here we use http/tls wrappers for handshake and after that just continue operating on the socket that's left open. See [WebsocketStreamWrapper.php](src/WebsocketStreamWrapper.php) for more.

## Contributing

All contributions are welcome.
