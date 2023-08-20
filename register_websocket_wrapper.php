<?php

stream_wrapper_register('ws', Valtzu\StreamWrapper\WebsocketStreamWrapper::class);
stream_wrapper_register('wss', Valtzu\StreamWrapper\WebsocketStreamWrapper::class);
