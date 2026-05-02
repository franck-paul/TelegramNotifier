<?php

declare(strict_types=1);

namespace Dotclear\Plugin\TelegramNotifier;

use Dotclear\App;
use Dotclear\Database\MetaRecord;
use Dotclear\Database\Statement\SelectStatement;
use Dotclear\Helper\Html\Form\Checkbox;
use Dotclear\Helper\Html\Form\Label;
use Dotclear\Helper\Html\Form\Para;
use Exception;

/**
 * @brief       TelegramNotifier action handler.
 * @ingroup     TelegramNotifier
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class TelegramAction
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $name,
        public readonly string $description,
        public readonly string $permissions = '',
        public readonly mixed $condition = null,
    ) {
        if (!in_array($this->type, Telegram::SUPPORTED_TYPES, true)) {
            throw new Exception(__('Unsupported Telegram message type.'));
        }
    }

    /**
     * Check user permissions for this action.
     */
    public function checkUser(TelegramUser $user, bool $logged = false): bool
    {
        // no permissions required
        if ($this->permissions === '') {
            return true;
        }

        // user must be logged
        if ($logged && App::auth()->userID() != $user->user) {
            return false;
        }

        // user has required persmission
        foreach (explode(',', $this->permissions) as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all configured users that have action selected.
     *
     * @return  array<int, TelegramUser>
     */
    public function getUsers(): array
    {
        $res = [];
        $sql = new SelectStatement();
        $sql
            ->columns([
                'user_id',
                'pref_value',
            ])
            ->from(App::db()->con()->prefix() . App::userWorkspace()::WS_TABLE_NAME)
            ->and('pref_ws = ' . $sql->quote(My::id()))
            ->and('pref_id = ' . $sql->quote($this->id))
            ->where('user_id IS NOT NULL');

        $rs = $sql->select();

        if ($rs instanceof MetaRecord) {
            while ($rs->fetch()) {
                if ((bool) $rs->f('pref_value')) {
                    // Action enabled for this user
                    $user = TelegramUser::newFromUser((string) $rs->f('user_id'));
                    // Get only configured users
                    if ($user->isConfigured() && $this->checkUser($user)) {
                        $res[] = $user;
                    }
                }
            }
        }

        return $res;
    }

    /**
     * Get current user action form.
     */
    public function getForm(TelegramUser $user): Para
    {
        if ($this->checkUser($user, true)) {
            return (new Para())->items([
                (new Checkbox(My::id() . $this->id, $user->hasAction($this->id)))
                    ->value('1')
                    ->label((new Label($this->name, Label::INSIDE_TEXT_AFTER))->title($this->description)),
            ]);
        }

        return new Para();
    }

    /**
     * Save current user action form.
     */
    public function setForm(TelegramUser $user): void
    {
        if ($this->checkUser($user, true)) {
            My::prefs()->put($this->id, !empty($_POST[My::id() . $this->id]), App::userWorkspace()::WS_BOOL);
        }
    }
}
