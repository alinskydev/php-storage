<?php

namespace Base;

class FileUploadConfigDTO
{
    public function __construct(
        public float $maxSize,
        public array $mimeTypes,
        public array $extensions,
        public string $folder,
        public \Closure $savingProcess,
    ) {}
}
