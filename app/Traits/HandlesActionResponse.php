<?php

namespace App\Traits;

trait HandlesActionResponse
{
    protected bool $success = false;
    protected array $errors = [];

    public function setSuccess(): void
    {
        $this->success = true;
    }

    public function setError(array $errors = []): void
    {
        $this->success = false;
        $this->errors = $errors;

    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function hasError(): bool
    {
        return !empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

}