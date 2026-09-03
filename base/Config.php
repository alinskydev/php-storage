<?php

namespace Base;

use Dotenv\Dotenv;

class Config
{
    private static self $instance;

    public bool $isDebugEnabled;
    public string $secretKey;

    private function __construct() {}

    public static function init(): static
    {
        if (!isset(static::$instance)) {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
            $dotenv->load();

            $instance = new static();
            $instance->isDebugEnabled = $_ENV['IS_DEBUG_ENABLED'];
            $instance->secretKey = $_ENV['SECRET_KEY'];

            self::$instance = $instance;
        }

        return self::$instance;
    }

    public static function get(string $param): ?string
    {
        return static::$data[$param];
    }
}
