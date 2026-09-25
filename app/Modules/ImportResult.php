<?php

namespace App\Modules;

class ImportResult
{
    /**
     * @param array $data      flat review form data (same shape the form collects)
     * @param array $warnings  [{path: ?string, message: string}] — path = form field, null = whole file
     * @param array $meta      e.g. ['notaria' => ['clave','entidad','num_notaria'], 'operations' => n]
     */
    public function __construct(
        public array $data,
        public array $warnings = [],
        public array $meta = [],
    ) {}

    public function warn(string $message, ?string $path = null): void
    {
        $this->warnings[] = ['path' => $path, 'message' => $message];
    }
}
