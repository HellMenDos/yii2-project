<?php

namespace app\modules\bot\controllers\groups;

use Yii;
use app\modules\bot\components\Controller;
use app\modules\bot\models\BotChatCaptcha;
use app\modules\bot\models\BotChatGreeting;
use app\modules\bot\models\Chat;
use app\modules\bot\models\ChatSetting;
use app\modules\bot\models\ChatMember;
use app\modules\bot\models\User as TelegramUser;
use app\modules\bot\controllers\groups\JoinCaptchaController;

/**
* Class SystemMessageController
*
* @package app\modules\bot\controllers\groups
*/
class SystemMessageController extends Controller
{
    /**
    * @return array
    */
    public function actionNewChatMembers()
    {
        if ($this->getUpdate()->getMessage()->getNewChatMembers()) {
            $telegramChat = $this->getTelegramChat();

            $joinHiderStatus = $telegramChat->getSetting(ChatSetting::JOIN_HIDER_STATUS);
            $joinCaptchaStatus = $telegramChat->getSetting(ChatSetting::JOIN_CAPTCHA_STATUS);
            $greetingStatus = $telegramChat->getSetting(ChatSetting::GREETING_STATUS);
            $role = JoinCaptchaController::ROLE_VERIFIED;

            if (isset($joinHiderStatus) && ($joinHiderStatus->value == ChatSetting::JOIN_HIDER_STATUS_ON)) {
                // Remove join message
                $this->getBotApi()->deleteMessage(
                    $telegramChat->chat_id,
                    $this->getUpdate()->getMessage()->getMessageId()
                );
            }

            foreach ($this->getUpdate()->getMessage()->getNewChatMembers() as $newChatMember) {
                $telegramUser = TelegramUser::findOne([
                    'provider_user_id' => $newChatMember->getId(),
                ]);

                if (!$telegramUser) {
                    $telegramUser = TelegramUser::createUser($newChatMember);
                    $telegramUser->updateInfo($newChatMember);
                    $telegramUser->save();
                }

                if (isset($joinCaptchaStatus) && ($joinCaptchaStatus->value == ChatSetting::JOIN_CAPTCHA_STATUS_ON)) {
                    if (!$newChatMember->isBot()) {
                        $role = JoinCaptchaController::ROLE_UNVERIFIED;
                    }
                }

                if (!$chatMember = $telegramChat->getChatMemberByUser($telegramUser)) {
                    $telegramChatMember = $this->getBotApi()->getChatMember(
                        $telegramChat->chat_id,
                        $telegramUser->provider_user_id
                    );

                    $telegramChat->link('users', $telegramUser, [
                        'status' => $telegramChatMember->getStatus(),
                        'role' => $role,
                    ]);
                } else {
                    $chatMember->setAttributes([
                         'role' => $role,
                    ]);
                    $chatMember->save();
                }

                // Send greeting message
                if (isset($greetingStatus) && ($greetingStatus->value == ChatSetting::GREETING_STATUS_ON)) {
                    if (!$newChatMember->isBot()) {
                        $this->run('greeting/show-greeting', [
                            'telegramUserId' => $telegramUser->id,
                        ]);
                    }
                }
            }
        }
    }

    /**
    * @return array
    */
    public function actionLeftChatMember()
    {
        if ($this->getUpdate()->getMessage()->getLeftChatMember()) {
            $telegramChat = $this->getTelegramChat();
            $telegramUser = $this->getTelegramUser();

            $joinHiderStatus = $telegramChat->getSetting(ChatSetting::JOIN_HIDER_STATUS);

            if (isset($joinHiderStatus) && $joinHiderStatus->value == ChatSetting::JOIN_HIDER_STATUS_ON) {
                // Remove left message
                $this->getBotApi()->deleteMessage(
                    $telegramChat->chat_id,
                    $this->getUpdate()->getMessage()->getMessageId()
                );
            }

            // Remove captcha message if user left the group
            // Doesn't work if someone kicked the user from the group
            $botCaptcha = BotChatCaptcha::find()
                ->where([
                    'chat_id' => $telegramChat->id,
                    'provider_user_id' => $telegramUser->provider_user_id,
                ])
                ->one();

            if (isset($botCaptcha)) {
                $this->getBotApi()->deleteMessage(
                    $telegramChat->chat_id,
                    $botCaptcha->captcha_message_id
                );

                $botCaptcha->delete();
            }

            // Remove greeting message if user left the group
            // Doesn't work if someone kicked the user from the group
            $botGreeting = BotChatGreeting::find()
                ->where([
                    'chat_id' => $telegramChat->id,
                    'provider_user_id' => $telegramUser->provider_user_id,
                ])
                ->one();

            if (isset($botGreeting)) {
                $this->getBotApi()->deleteMessage(
                    $telegramChat->chat_id,
                    $botGreeting->message_id
                );

                $botGreeting->delete();
            }
        }
    }

    public function actionGroupToSupergroup()
    {
        if ($this->getUpdate()->getMessage()->getMigrateToChatId()) {
            $telegramChat = $this->getTelegramChat();

            $telegramChat->setAttributes([
                'type' => Chat::TYPE_SUPERGROUP,
                'chat_id' => $this->getMessage()->getMigrateToChatId(),
            ]);

            $telegramChat->save();
        }
    }
}
