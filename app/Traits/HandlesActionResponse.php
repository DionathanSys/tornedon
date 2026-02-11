<?php

namespace App\Traits;

trait HandlesActionResponse
{
    use GeneratesErrorCode;

    protected bool $success = false;
    protected ?string $message = null;
    protected array $errors = [];
    protected ?string $errorCode = null;

    public function setSuccess(): void
    {
        $this->success = true;
    }

    public function setError(string|null $message = null, array $errors = [], ?string $errorCode = null): void
    {
        $this->success   = false;
        $this->message   = $message;
        $this->errors    = $errors;
        $this->errorCode = $errorCode ?? $this->generateErrorCode();
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function hasError(): bool
    {
        return !empty($this->errors) || !$this->success;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}