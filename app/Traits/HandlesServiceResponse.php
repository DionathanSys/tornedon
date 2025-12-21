<?php

namespace App\Traits;

trait HandlesServiceResponse
{
    protected bool $success = false;
    protected string $message = '';
    protected array $data = [];
    protected array $errors = [];
    protected int $status = 200;

    public function success(string $message = '', array $data = [], int $status = 200): array
    {
        $this->success = true;
        $this->message = $message;
        $this->data = $data;
        $this->errors = [];
        $this->status = $status;

        return $this->toArray();
    }

    public function error(string $message = '', array $errors = [], int $status = 422): array
    {
        $this->success = false;
        $this->message = $message;
        $this->errors = $errors;
        $this->data = [];
        $this->status = $status;

        return $this->toArray();
    }

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function setErrors(array $errors): self
    {
        $this->errors = $errors;
        return $this;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'errors' => $this->errors,
            'status' => $this->status,
        ];
    }

    public function resetResponse(): self
    {
        $this->success = false;
        $this->message = '';
        $this->data = [];
        $this->errors = [];
        $this->status = 200;
        return $this;
    }
}