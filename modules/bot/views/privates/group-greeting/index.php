<b><?= $chatTitle ?></b><br/>
<br/>
<?= Yii::t('bot', 'Greeting') ?> - <?= Yii::t('bot', 'sends a welcome message to newly joined members') ?>. <?= Yii::t('bot', 'Ignores telegram bots') ?>.<br/>
<br/>
————<br/>
<br/>
<?= $this->render('@bot/views/groups/greeting/show-greeting', [
    'user' => $telegramUser,
    'message' => $messageSetting->value,
]); ?>
<br/>
————<br/>
