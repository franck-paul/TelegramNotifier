<?php

declare(strict_types=1);

namespace Dotclear\Plugin\TelegramNotifier;

use Dotclear\App;
use Dotclear\Helper\Network\HttpClient;
use Exception;

/**
 * @brief       TelegramNotifier message handler.
 * @ingroup     TelegramNotifier
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class Telegram
{
    /**
     * Telegram configuration fields.
     */
    public const CONFIGURATION_KEYS = [
        'chat',
        'token',
    ];

    /**
     * Supported telegram message type.
     */
    public const SUPPORTED_TYPES = [
        'message',
    ];

    /**
     * Telegram bot APIURL.
     */
    public const BOT_API_URL = 'https://api.telegram.org/bot%s/%s';

    /**
     * Registerded action sstack.
     *
     * @var     array<string, TelegramAction> $actions
     */
    private array $actions = [];

    /**
     * Current action.
     */
    private TelegramAction|false $action;

    /**
     * Current message content.
     */
    private string $content = '';

    /**
     * Current message content format.
     */
    private string $format = '';

    /**
     * Current origin of action.
     */
    private string $origin = '';

    /**
     * Create a new telegram instance.
     */
    public function __construct()
    {
        App::behavior()->callBehavior(My::id() . 'AddActions', $this);
    }

    /**
     * Register actions.
     *
     * @param   array<int, TelegramAction>  $actions
     */
    public function addActions(array $actions): void
    {
        foreach ($actions as $action) {
            if ($action instanceof TelegramAction) {
                $this->actions[$action->id] = $action;
            }
        }
    }

    /**
     * Get all registered actions.
     *
     * @return  array<string, TelegramAction>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * Get a registered action.
     */
    public function getAction(string $id): false|TelegramAction
    {
        return $this->actions[$id] ?? false;
    }

    /**
     * Set current telegram action.
     */
    public function setAction(string $id): self
    {
        $this->action = $this->getAction($id);

        return $this;
    }

    /**
     * Set current telegram content format.
     */
    public function setFormat(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Set current telegram content.
     */
    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Set current telegram origin.
     */
    public function setOrigin(string $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    /**
     * Send telegram.
     */
    public function send(): void
    {
        if (!$this->action) {
            return;
        }

        $data = [];

        if ($this->action->type === 'message' && $this->content !== '') {
            foreach ($this->action->getUsers() as $user) {
                // If action has a condition, check it
                if (is_callable($this->action->condition) && !call_user_func($this->action->condition, $user, $this->origin)) {
                    continue;
                }

                $data['text'] = $this->content;
                $this->query($user, 'sendMessage', $data);
            }
        }
    }

    /**
     * Send a telegram message.
     *
     * @see https://core.telegram.org/bots/api#responseparameters
     *
     * @param   array<string, mixed>    $data
     */
    public function query(TelegramUser $user, string $endpoint, array $data): bool
    {
        $data['chat_id'] = $user->chat;
        if ($this->format !== '') {
            $data['parse_mode'] = $this->format;
        }

        $url  = sprintf(self::BOT_API_URL, $user->token, $endpoint);
        $path = '';

        try {
            // init bot API
            $client = HttpClient::initClient($url, $path);
            if ($client === false) {
                throw new Exception(__('Failed to init Telegram API'));
            }

            // call bot API
            $client->post($path, $data);

            // get bot API response
            $rsp = json_decode((string) $client->getContent(), true);
            if (!isset($rsp['ok']) || !$rsp['ok']) {
                throw new Exception($rsp['description'] ?? __('Failed to call Telegram API'));
            }
        } catch (Exception $exception) {
            if (App::config()->debugMode()) {
                throw $exception;
            }

            return false;
        }

        return true;
    }
}
