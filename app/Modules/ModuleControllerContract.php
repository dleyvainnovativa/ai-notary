<?php

namespace App\Modules;

interface ModuleControllerContract
{
    /**
     * Declares the named input files this module accepts.
     * Returns an array of ModuleInput objects.
     */
    public function inputs(): array;

    /**
     * The merged schema describing the FULL output shape (all inputs combined).
     * Drives the review form + validation in Phase 5.
     */
    public function schema(): array;

    /**
     * Module-specific cleanup of the MERGED, confidence-wrapped AI output.
     */
    public function postProcess(array $merged): array;

    public function formSchema(): array;
}
