<?php

namespace Tests;

use App\Domains\Telegram\Runtime\RoxiRuntime;

class ImportRuntimeFake extends RoxiRuntime
{
    public array $messages = [];

    public array $prompts = [];

    public array $copies = [];

    public bool $loseSendResponse = false;

    public bool $hideSearchResult = false;

    public bool $failSuccessNotification = false;

    public bool $failNextPrompt = false;

    public ?\Closure $duringCopy = null;

    public ?\Closure $duringSearch = null;

    public array $searches = [];

    public array $deletions = [];

    public bool $loseDeleteResponse = false;

    public function deleteMessages(int $chatId, array $messageIds): void
    {
        $this->deletions[] = ['chat' => $chatId, 'ids' => $messageIds];
        if ($this->loseDeleteResponse) {
            throw new \RuntimeException('Delete response lost');
        }
    }

    public function getMessages(int $chatId, array $messageIds): array
    {
        return ['messages' => array_values(array_intersect_key($this->messages, array_flip($messageIds)))];
    }

    public function methodCallAsyncRead(string $method, array $arguments): array
    {
        if ($method === 'messages.sendMessage') {
            if ($this->failNextPrompt) {
                $this->failNextPrompt = false;
                throw new \RuntimeException('Prompt response lost');
            }
            if ($this->failSuccessNotification && str_starts_with($arguments['message'], 'Saved ')) {
                throw new \RuntimeException('Notification response lost');
            }
            $this->prompts[] = $arguments;

            return ['_' => 'updateShortSentMessage', 'id' => 100 + count($this->prompts)];
        }
        if ($method === 'messages.sendMedia') {
            $this->copies[] = $arguments;
            ($this->duringCopy ?? static function (): void {})();
            if ($this->loseSendResponse) {
                throw new \RuntimeException('Response lost');
            }

            return ['_' => 'updateShortSentMessage', 'id' => 900 + count($this->copies)];
        }
        if ($method === 'messages.search') {
            $this->searches[] = $arguments;
            ($this->duringSearch ?? static function (): void {})();

            return ['messages' => $this->hideSearchResult ? [] : [['id' => 901, 'out' => true, 'message' => $this->copies[0]['message'] ?? $arguments['q'],
                'media' => ['document' => ['_' => 'document', 'id' => 77]]]]];
        }
        throw new \RuntimeException('Unexpected Telegram method '.$method);
    }
}
