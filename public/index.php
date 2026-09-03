<?php

require __DIR__ . '/../vendor/autoload.php';

use Base\Config;
use Base\ResponseHelper;
use Base\StringHelper;
use claviska\SimpleImage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\UploadedFile;

$app = AppFactory::create();
$config = Config::init();

$customErrorHandler = function (
    ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails,
) use ($app) {
    $response = $app->getResponseFactory()->createResponse();
    return ResponseHelper::error($response, $exception->getMessage());
};

$errorMiddleware = $app->addErrorMiddleware($config->isDebugEnabled, true, true);
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

$headers = getallheaders();

if ($headers['Storage-Secret-Key'] !== $config->secretKey) {
    throw new Exception('Not found.');
}

$app->get('/welcome', function (Request $request, Response $response, $args) {
    return ResponseHelper::success($response, ['message' => 'Welcome!']);
});

$app->post('/api/get/images', function (Request $request, Response $response, $args) use ($config) {
    $uri = $request->getUri();
    $baseUrl = $uri->getScheme() . '://' . $uri->getHost();

    $body = $request->getParsedBody();
    $files = $body['files'] ?? null;

    if (!is_array($files)) throw new Exception('Files are required');
    if (count($files) < 1 || count($files) > 10) throw new Exception('Max files quantity is 10');

    $paths = [];

    foreach ($files as $key => $file) {
        $path = $file['path'] ?? null;
        $action = $file['action'] ?? null;
        $sizes = $file['sizes'] ?? null;

        if (in_array($action, ['scaleDown']) && $sizes) {
            $sizes = explode('|', $sizes);
            $actionAndSizes = array_map(fn($value) => "{$action}_$value", $sizes);
            $actionAndSizes = array_combine($actionAndSizes, $actionAndSizes);
            $paths[$key] = array_map(fn($value) => ['raw' => "$baseUrl/$path"], $actionAndSizes);
        }

        $paths[$key]['original']['raw'] = "$baseUrl/$path";

        try {
            $sourceFile = __DIR__ . "/$path";

            if (!$path) throw new \Exception('File not exists');
            if (!is_file($sourceFile)) throw new \Exception('File not exists');

            $extension = pathinfo($sourceFile, PATHINFO_EXTENSION);
            $extension = mb_strtolower($extension);

            switch ($action) {
                case 'scaleDown':
                    if (!$sizes) throw new Exception('Incorrect size');

                    foreach ($sizes as $size) {
                        $size = (int)$size;

                        if ($size <= 0 || $size > 1920) throw new Exception('Incorrect size');

                        $thumbPath = "storage/thumbs/$action";
                        $thumbPath .= $size ? "/$size" : '';
                        $thumbName = hash('sha256', $sourceFile) . filemtime($sourceFile) . ".$extension";

                        $savePath = __DIR__ . "/$thumbPath";
                        $thumbFile = "$savePath/$thumbName";
                        $thumbUrl = "$thumbPath/$thumbName";

                        if (!is_file($thumbFile)) {
                            if (!is_dir($savePath)) {
                                mkdir($savePath, 0777, true);
                                chown($savePath, 'www-data');
                            }

                            $image = new SimpleImage($sourceFile);

                            if ($image->getWidth() > $size) {
                                $image->resize($size, null);
                                $image->toFile($thumbFile);
                            } else {
                                $thumbUrl = $path;
                            }
                        }

                        $paths[$key]["{$action}_{$size}"]['raw'] = "$baseUrl/$thumbUrl";
                    }

                    break;
            }
        } catch (\Throwable $e) {
        }
    }

    return ResponseHelper::success($response, ['files' => $paths]);
});

$app->post('/api/upload', function (Request $request, Response $response, $args) use ($config) {
    $body = $request->getParsedBody();
    $type = $body['type'] ?? null;
    $size = $body['size'] ?? null;
    $folder = $body['folder'] ?? 'all';

    switch ($type) {
        case 'files':
            $files = $request->getUploadedFiles()['files'] ?? [];

            if (!is_array($files)) throw new Exception('Files are required');
            if (count($files) < 1 || count($files) > 10) throw new Exception('Max files quantity is 10');

            $paths = [];

            array_walk_recursive($files, function (UploadedFile $file) use ($size, $folder, &$paths) {
                if ($file->getSize() > 1024 * 1024 * 10) throw new Exception('Max file size is 10 MB');

                $filePath = $file->getFilePath();
                $mimeType = mime_content_type($filePath);

                $exception = explode('.', $file->getClientFilename());
                $extension = end($exception);

                if (!in_array($mimeType, ['audio/mpeg', 'audio/wav'])) throw new Exception('File mime type is incorrect');
                if (!in_array($extension, ['mp3', 'wav'])) throw new Exception('File extension is incorrect');

                $returnPath = "storage/files/$folder/" . date('Y/m/d');
                $savePath = __DIR__ . "/$returnPath";
                $name = uniqid() . '_' . StringHelper::uuidv4() . ".$extension";

                if (!is_dir($savePath)) {
                    mkdir($savePath, 0777, true);
                    chown($savePath, 'www-data');
                }

                $file->moveTo("$savePath/$name");
                chown("$savePath/$name", 'www-data');

                $paths[] = "$returnPath/$name";
            });

            return ResponseHelper::success($response, ['files' => $paths]);

        case 'images':
            $files = $request->getUploadedFiles()['files'] ?? [];

            if (!is_array($files)) throw new Exception('Files are required');
            if (count($files) < 1 || count($files) > 10) throw new Exception('Max files quantity is 10');

            $paths = [];

            array_walk_recursive($files, function (UploadedFile $file) use ($size, $folder, &$paths) {
                if ($file->getSize() > 1024 * 1024 * 2) throw new Exception('Max file size is 2 MB');

                $filePath = $file->getFilePath();
                $mimeType = mime_content_type($filePath);

                $exception = explode('.', $file->getClientFilename());
                $extension = end($exception);

                if (!in_array($mimeType, ['image/jpg', 'image/jpeg', 'image/png'])) throw new Exception('File is not an image');
                if (!in_array($extension, ['jpg', 'jpeg', 'png'])) throw new Exception('File is not an image');

                $returnPath = "storage/images/$folder/" . date('Y/m/d');
                $savePath = __DIR__ . "/$returnPath";
                $name = uniqid() . '_' . StringHelper::uuidv4() . ".$extension";

                if (!is_dir($savePath)) {
                    mkdir($savePath, 0777, true);
                    chown($savePath, 'www-data');
                }

                $image = new SimpleImage($filePath);

                if ($size) {
                    $sizes = explode('x', $size);

                    if (count($sizes) !== 2) throw new Exception('Incorrect size');

                    $sizes = array_map(fn($value) => (int)$value, $sizes);

                    if ($image->getWidth() >= $image->getHeight()) {
                        $image->getWidth() > $sizes[0] ? $image->resize($sizes[0], null) : null;
                    } else {
                        $image->getHeight() > $sizes[1] ? $image->resize(null, $sizes[1]) : null;
                    }
                }

                $image->toFile("$savePath/$name");
                chown("$savePath/$name", 'www-data');

                $paths[] = "$returnPath/$name";
            });

            return ResponseHelper::success($response, ['files' => $paths]);

        default:
            return ResponseHelper::error($response, 'Invalid type');
    }
});

$app->run();
