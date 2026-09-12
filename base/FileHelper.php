<?php

namespace Base;

use claviska\SimpleImage;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

class FileHelper
{
    public static function getImages(
        Request $request,
        Response $response,
    ): ResponseInterface {
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

                            $thumbPath = "../public/storage/thumbs/$action";
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
    }

    public static function upload(
        Request $request,
        Response $response,
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $type = $body['type'] ?? null;
        $size = $body['size'] ?? null;
        $folder = $body['folder'] ?? 'all';

        $config = match ($type) {
            'files' => new FileUploadConfigDTO(
                maxSize: 10,
                mimeTypes: [
                    'application/pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ],
                extensions: ['pdf', 'docx', 'xlsx'],
                folder: 'files',
                savingProcess: function ($file, $filePath, $size, $savePath, $name) {
                    $file->moveTo("$savePath/$name");
                    chown("$savePath/$name", 'www-data');
                },
            ),
            'images' => new FileUploadConfigDTO(
                maxSize: 2,
                mimeTypes: [
                    'image/jpg',
                    'image/jpeg',
                    'image/png',
                ],
                extensions: ['jpg', 'jpeg', 'png'],
                folder: 'images',
                savingProcess: function ($file, $filePath, $size, $savePath, $name) {
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
                },
            ),
        };

        $files = $request->getUploadedFiles()['files'] ?? [];

        if (!is_array($files)) throw new Exception('Files are required');
        if (count($files) < 1 || count($files) > 10) throw new Exception('Max files quantity is 10');

        $paths = [];

        array_walk_recursive($files, function (UploadedFile $file) use ($config, $size, $folder, &$paths) {
            if ($file->getSize() > 1024 * 1024 * $config->maxSize) throw new Exception("Max file size is $config->maxSize MB");

            $filePath = $file->getFilePath();
            $mimeType = mime_content_type($filePath);

            $extension = explode('.', $file->getClientFilename());
            $extension = end($extension);

            if (!in_array($mimeType, $config->mimeTypes)) throw new Exception('File mime type is incorrect');
            if (!in_array($extension, $config->extensions)) throw new Exception('File extension is incorrect');

            $returnPath = "../public/storage/$config->folder/$folder/" . date('Y/m/d');
            $savePath = __DIR__ . "/$returnPath";
            $name = uniqid() . '_' . StringHelper::uuidv4() . ".$extension";

            if (!is_dir($savePath)) {
                mkdir($savePath, 0777, true);
                chown($savePath, 'www-data');
            }

            ($config->savingProcess)($file, $filePath, $size, $savePath, $name);

            $paths[] = "$returnPath/$name";
        });

        return ResponseHelper::success($response, ['files' => $paths]);
    }
}
