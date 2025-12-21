<?php

namespace App\Traits;

trait HandlesActionResponse
{
    protected bool $success = false;
    protected array $errors = [];

    public function success(): void
    {
        $this->success = true;
    }

    public function error(string $message = '', array $errors = []): void
    {
        $this->success = false;
        $this->errors = $errors;

    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

}