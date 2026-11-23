<?php

namespace App\Domains\Telegram\Contracts;

interface RoxiLoginSessionClient
{
    public function isAuthorized(): bool;

    public function requestCode(string $phone): void;

    public function completeCode(string $code): bool;

    public function completePassword(string $password): void;

    public function username(): ?string;

    public function logout(): void;
}
