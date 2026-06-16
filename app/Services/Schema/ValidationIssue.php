<?php

namespace App\Services\Schema;

class ValidationIssue
{
    public function __construct(
        public string $path,        // e.g. "pagos_inmueble.0.institucion_financiera"
        public string $field,       // e.g. "institucion_financiera"
        public string $rule,        // "required" | "format" | "catalog" | "required_if"
        public string $message,
    ) {}
}