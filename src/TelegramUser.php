<?php

declare(strict_types=1);

namespace Dotclear\Plugin\TelegramNotifier;

use Dotclear\App;
use Dotclear\Database\MetaRecord;
use Dotclear\Database\Statement\SelectStatement;
use Dotclear\Helper\Html\Form\Input;
use Dotclear\Helper\Html\Form\Label;
use Dotclear\Helper\Html\Form\Para;
use Dotclear\Helper\Html\Html;
use Exception;

/**
 * @brief       TelegramNotifier user handler.
 * @ingroup     TelegramNotifier
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class TelegramUser
{
    /**
     * User selected actions.
     *
     * @var     array<int, string>  $actions
     */
    private array $actions = [];

    /**
     * User is admin on current blog.
     */
    private bool $admin = false;

    /**
     * User email.
     *
     * @var array<array-key, string>
     */
    private array $emails = [];

    /**
     * Create a new user instance.
     */
    public function __construct(
        public readonly string $user = '',
        public readonly int $chat = 0,
        public readonly string $token = ''
    ) {
        if ($this->isConfigured()) {
            // Get user selected actions
            $sql = new SelectStatement();
            $sql
                ->columns([
                    'pref_id',
                    'pref_value',
                ])
                ->from(App::db()->con()->prefix() . App::userWorkspace()::WS_TABLE_NAME)
                ->and('pref_ws = ' . $sql->quote(My::id()))
                ->where('user_id = ' . $sql->quote($this->user));

            $rs = $sql->select();

            if ($rs instanceof MetaRecord) {
                while ($rs->fetch()) {
                    if (!in_array($rs->f('pref_id'), Telegram::CONFIGURATION_KEYS) && (bool) $rs->f('pref_value')) {
                        $this->actions[] = trim((string) $rs->f('pref_id'));
                    }
                }
            }

            // Check if user is (super)admin on current blog
            $rs = App::users()->getUser($this->user);

            if (!$rs->isEmpty()) {
                $this->admin = $rs->admin() !== '';

                // Get all possible user emails
                $this->emails[] = $rs->user_email;
                $user_prefs     = App::userPreferences()->createFromUser((string) $rs->user_id, 'profile');
                if ((string) $user_prefs->profile->mails !== '') {
                    $emails = array_map(trim(...), explode(',', (string) $user_prefs->profile->mails));
                    if ($emails !== []) {
                        $this->emails = array_merge($this->emails, $emails);
                    }
                }
            }
        }
    }

    /**
     * Check if configuration is not empty.
     */
    public function isConfigured(): bool
    {
        return $this->user !== '' && $this->chat !== 0 && $this->token !== '';
    }

    /**
     * Check if user has a given permission on blog.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->admin) {
            return true;
        }

        $blogs = App::users()->getUserPermissions($this->user);

        // user has (no) permissions on blog
        return isset($blogs[App::blog()->id()]['p']) && !empty($blogs[App::blog()->id()]['p'][$permission]);
    }

    /**
     * Get user emails
     *
     * @return array<array-key, string>
     */
    public function getEmails(): array
    {
        return $this->emails;
    }

    /**
     * Get current user configuration form.
     *
     * @return  array<int, Para>
     */
    public function getForm(): array
    {
        if (App::auth()->userID() == $this->user) {
            return [
                (new Para())
                    ->class('field')
                    ->items([
                        (new Input(My::id() . 'chat'))
                            ->size(60)
                            ->maxlength(255)
                            ->value(Html::escapeHTML((string) $this->chat))
                            ->label(new Label(__('Telegram chat ID:'), Label::INSIDE_TEXT_BEFORE)),
                    ]),
                (new Para())
                    ->class('field')
                    ->items([
                        (new Input(My::id() . 'token'))
                            ->size(60)
                            ->maxlength(255)
                            ->value(Html::escapeHTML($this->token))
                            ->label(new Label(__('Telegram bot token:'), Label::INSIDE_TEXT_BEFORE)),
                    ]),
            ];
        }

        return [];
    }

    /**
     * Save current user configuration form.
     */
    public function setForm(): void
    {
        if (App::auth()->userID() == $this->user) {
            $chat  = (int) $_POST[My::id() . 'chat'] ?: 0;
            $token = (string) $_POST[My::id() . 'token'] ?: '';

            if ($chat !== $this->chat || $token !== $this->token) {
                // Test config
                if ($chat !== 0 && $token !== '') {
                    $user     = new self($this->user, $chat, $token);
                    $telegram = new Telegram();
                    if (!$telegram->query($user, 'getChat', ['chat_id' => $chat])) {
                        throw new Exception(__('Bad Telegram configuration'));
                    }
                }

                // Save config
                My::prefs()->put('chat', $chat, App::userWorkspace()::WS_INT);
                My::prefs()->put('token', $token, App::userWorkspace()::WS_STRING);
            }
        }
    }

    /**
     * Check if user has selected an action.
     */
    public function hasAction(string $action): bool
    {
        return in_array($action, $this->actions);
    }

    /**
     * Get a user Telegram API configuration.
     */
    public static function newFromUser(string $user_id): self
    {
        $res = [];
        $sql = new SelectStatement();
        $sql
            ->columns([
                'pref_id',
                'pref_value',
                'pref_type',
            ])
            ->from(App::db()->con()->prefix() . App::userWorkspace()::WS_TABLE_NAME)
            ->and('pref_ws = ' . $sql->quote(My::id()))
            ->where('user_id = ' . $sql->quote($user_id));

        $rs = $sql->select();

        if ($rs instanceof MetaRecord) {
            while ($rs->fetch()) {
                if (in_array($rs->f('pref_id'), Telegram::CONFIGURATION_KEYS)) {
                    $name  = trim((string) $rs->f('pref_id'));
                    $value = $rs->f('pref_value');
                    $type  = $rs->f('pref_type');

                    if ($type === App::userWorkspace()::WS_ARRAY) {
                        $value = @json_decode((string) $value, true);
                    } elseif ($type === App::userWorkspace()::WS_FLOAT || $type === App::userWorkspace()::WS_DOUBLE) {
                        $type = App::userWorkspace()::WS_FLOAT;
                    } elseif ($type !== App::userWorkspace()::WS_BOOL && $type !== App::userWorkspace()::WS_INT) {
                        $type = App::userWorkspace()::WS_STRING;
                    }

                    settype($value, $type);
                    $res[$name] = $value;
                }
            }
        }

        $chat  = isset($res['chat'])  && is_numeric($res['chat']) ? (int) $res['chat'] : 0;
        $token = isset($res['token']) && is_string($res['token']) ? $res['token'] : '';

        return new self($user_id, $chat, $token);
    }
}
