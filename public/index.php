<?php

require __DIR__ . '/../vendor/autoload.php';

use Base\Config;
use Base\FileHelper;
use Base\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;

$app = AppFactory::create();
$config = Config::init();

$errorMiddleware = $app->addErrorMiddleware($config->isDebugEnabled, true, true);
$errorMiddleware->setDefaultErrorHandler(function (
    ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails,
) use ($app) {
    $response = $app->getResponseFactory()->createResponse();
    return ResponseHelper::error($response, $exception->getMessage());
});

$app->add(function (Request $request, RequestHandler $handler) use ($app, $config) {
    $secretKey = $request->getHeaderLine('Storage-Secret-Key');

    if ($config->secretKey === '' || !hash_equals($config->secretKey, $secretKey)) {
        return ResponseHelper::error($app->getResponseFactory()->createResponse(), 'Not found.');
    }

    return $handler->handle($request);
});

$app->get('/welcome', function (Request $request, Response $response, $args) {
    return ResponseHelper::success($response, ['message' => 'Welcome!']);
});

$app->post('/api/get/images', fn(Request $request, Response $response, $args) => FileHelper::getImages(
    request: $request,
    response: $response,
));

$app->post('/api/upload', fn(Request $request, Response $response, $args) => FileHelper::upload(
    request: $request,
    response: $response,
));

$app->run();
