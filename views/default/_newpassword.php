				<?= $form->field($model, 'newPassword')->passwordInput() ?>

<?php if ($module->dicewareEnabled): ?>
		<p><a id="Users_generatePassword" href="#"><?= Yii::t('usr', 'Generate a password') ?></a></p>
<?php
$diceUrl = yii\helpers\Html::url(['password']);
$diceLabel = Yii::t('usr', 'Use this password?\nTo copy it to the clipboard press Ctrl+C.');
$passwordId = yii\helpers\Html::getInputId($model, 'newPassword');
$verifyId = yii\helpers\Html::getInputId($model, 'newVerify');
$script = <<<JavaScript
$('#Users_generatePassword').on('click',function(){
	$.getJSON('{$diceUrl}', function(data){
		var text = window.prompt("{$diceLabel}", data);
		if (text != null)
			$('#{$passwordId}').val(text);
			$('#{$verifyId}').val(text);
	});
	return false;
});
JavaScript;
$this->registerJs($script);
?>
<?php endif; ?>

				<?= $form->field($model, 'newVerify')->passwordInput() ?>

