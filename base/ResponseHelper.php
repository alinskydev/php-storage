<?php

namespace Base;

use Psr\Http\Message\ResponseInterface as Response;

class ResponseHelper
{
    public static function success(Response $response, string|array $message): Response
    {
        $message = is_array($message) ? json_encode($message) : json_encode(['message' => $message]);
        $response->getBody()->write($message);
        return $response;
    }

    public static function error(Response $response, string $message): Response
    {
        $message = json_encode(['message' => $message]);
        $response->getBody()->write($message);
        return $response->withStatus(400);
    }
}
