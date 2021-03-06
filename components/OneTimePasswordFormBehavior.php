<?php
/**
 * OneTimePasswordFormBehavior class file.
 *
 * @author Jan Was <jwas@nets.com.pl>
 */

namespace nineinchnick\usr\components;

use Yii;
use nineinchnick\usr\Module;

/**
 * OneTimePasswordFormBehavior adds one time password validation to a login form model component.
 *
 * @property CFormModel $owner The owner model that this behavior is attached to.
 *
 * @author Jan Was <jwas@nets.com.pl>
 */
class OneTimePasswordFormBehavior extends FormModelBehavior
{
	public $oneTimePassword;

	private $_oneTimePasswordConfig = array(
		'authenticator' => null,
		'mode' => null,
		'required' => null,
		'timeout' => null,
		'secret' => null,
		'previousCode' => null,
		'previousCounter' => null,
	);

	private $_controller;

	/**
	 * @inheritdoc
	 */
	public function events() {
		return array_merge(parent::events(), array(
			\yii\base\Model::EVENT_AFTER_VALIDATE=>'afterValidate',
		));
	}

	/**
	 * @inheritdoc
	 */
	public function rules()
	{
		$rules = array(
			array('oneTimePassword', 'filter', 'filter'=>'trim', 'on'=>'verifyOTP'),
			array('oneTimePassword', 'default', 'on'=>'verifyOTP'),
			array('oneTimePassword', 'required', 'on'=>'verifyOTP'),
			array('oneTimePassword', 'validOneTimePassword', 'skipOnEmpty'=>false, 'except'=>'hybridauth'),
		);
		return $this->applyRuleOptions($rules);
	}

	/**
	 * @inheritdoc
	 */
	public function attributeLabels()
	{
		return array(
			'oneTimePassword' => Yii::t('usr','One Time Password'),
		);
	}

	public function getController()
	{
		return $this->_controller;
	}

	public function setController($value)
	{
		$this->_controller = $value;
	}

	public function getOneTimePasswordConfig()
	{
		return $this->_oneTimePasswordConfig;
	}

	public function setOneTimePasswordConfig(array $config)
	{
		foreach($config as $key => $value) {
			if ($this->_oneTimePasswordConfig[$key] === null)
				$this->_oneTimePasswordConfig[$key] = $value;
		}
		return $this;
	}

	protected function loadOneTimePasswordConfig()
	{
		$identity = $this->owner->getIdentity();
		if (!($identity instanceof \nineinchnick\usr\components\OneTimePasswordIdentityInterface))
			throw new \yii\base\Exception(Yii::t('usr','The {class} class must implement the {interface} interface.', ['class'=>get_class($identity),'interface'=>'\nineinchnick\usr\components\OneTimePasswordIdentityInterface']));
		list($previousCode, $previousCounter) = $identity->getOneTimePassword();
		$this->setOneTimePasswordConfig(array(
			'secret' => $identity->getOneTimePasswordSecret(),
			'previousCode' => $previousCode,
			'previousCounter' => $previousCounter,
		));
		return $this;
	}

	public function getOTP($key)
	{
		if ($this->_oneTimePasswordConfig[$key] === null) {
			$this->loadOneTimePasswordConfig();
		}
		return $this->_oneTimePasswordConfig[$key];
	}

	public function getNewCode()
	{
		$this->loadOneTimePasswordConfig();
		// extracts: $authenticator, $mode, $required, $timeout, $secret, $previousCode, $previousCounter
		extract($this->_oneTimePasswordConfig);
		return $authenticator->getCode($secret, $mode == Module::OTP_TIME ? null : $previousCounter);
	}

	public function validOneTimePassword($attribute,$params)
	{
		if($this->owner->hasErrors()) {
			return;
		}
		$this->loadOneTimePasswordConfig();
		// extracts: $authenticator, $mode, $required, $timeout, $secret, $previousCode, $previousCounter
		extract($this->_oneTimePasswordConfig);

		if (($mode !== Module::OTP_TIME && $mode !== Module::OTP_COUNTER) || (!$required && $secret === null)) {
			return true;
		}
		if ($required && $secret === null) {
			// generate and save a new secret only if required to do so, in other cases user must verify that the secret works
			$secret = $this->_oneTimePasswordConfig['secret'] = $authenticator->generateSecret();
			$this->owner->getIdentity()->setOneTimePasswordSecret($secret);
		}

		if ($this->isValidOTPCookie(Yii::$app->request->cookies->get(Module::OTP_COOKIE), $this->owner->username, $secret, $timeout)) {
			return true;
		}
		if (empty($this->owner->$attribute)) {
			$this->owner->addError($attribute,Yii::t('usr','Enter a valid one time password.'));
			$this->owner->scenario = 'verifyOTP';
			if ($mode === Module::OTP_COUNTER) {
				$this->_controller->sendEmail($this, 'oneTimePassword');
			}
			if (YII_DEBUG) {
				$this->oneTimePassword = $authenticator->getCode($secret, $mode === Module::OTP_TIME ? null : $previousCounter);
			}
			return false;
		}
		if ($mode === Module::OTP_TIME) {
			$valid = $authenticator->checkCode($secret, $this->owner->$attribute);
		} elseif ($mode === Module::OTP_COUNTER) {
			$valid = $authenticator->getCode($secret, $previousCounter) == $this->owner->$attribute;
		} else {
			$valid = false;
		}
		if (!$valid) {
			$this->owner->addError($attribute,Yii::t('usr','Entered code is invalid.'));
			$this->owner->scenario = 'verifyOTP';
			return false;
		}
		if ($this->owner->$attribute == $previousCode) {
			if ($mode === Module::OTP_TIME) {
				$message = Yii::t('usr','Please wait until next code will be generated.');
			} elseif ($mode === Module::OTP_COUNTER) {
				$message = Yii::t('usr','Please log in again to request a new code.');
			}
			$this->owner->addError($attribute,Yii::t('usr','Entered code has already been used.').' '.$message);
			$this->owner->scenario = 'verifyOTP';
			return false;
		}
		$this->owner->getIdentity()->setOneTimePassword($this->owner->$attribute, $mode === Module::OTP_TIME ? floor(time() / 30) : $previousCounter + 1);
		return true;
	}

	public function afterValidate($event)
	{
		if ($this->owner->scenario === 'hybridauth' || $this->owner->hasErrors())
			return;

		// extracts: $authenticator, $mode, $required, $timeout, $secret, $previousCode, $previousCounter
		extract($this->_oneTimePasswordConfig);

		$cookie = $this->createOTPCookie($this->owner->username, $secret, $timeout);
		Yii::$app->response->cookies->add($cookie);
	}

	public function createOTPCookie($username, $secret, $timeout, $time = null) {
		if ($time === null)
			$time = time();
		$data=array('username'=>$username, 'time'=>$time, 'timeout'=>$timeout);
		$cookie=new \yii\web\Cookie([
			'name'=>Module::OTP_COOKIE,
			'value'=>$time.':'.\yii\helpers\Security::hashData(serialize($data), $secret),
			'expire'=>time() + ($timeout <= 0 ? 10*365*24*3600 : $timeout),
			'httpOnly'=>true,
		]);
		return $cookie;
	}

	public function isValidOTPCookie($cookie, $username, $secret, $timeout, $time = null) {
		if ($time === null)
			$time = time();

		if(!$cookie || empty($cookie->value) || !is_string($cookie->value)) {
			return false;
		}
		$parts = explode(":",$cookie->value,2);
		if (count($parts)!=2) {
			return false;
		}
		list($creationTime,$hash) = $parts;
		$data=array('username'=>$username, 'time'=>(int)$creationTime, 'timeout'=>$timeout);
		$validHash = \yii\helpers\Security::hashData(serialize($data), $secret);
		return ($timeout <= 0 || $creationTime + $timeout >= $time) && $hash === $validHash;
	}
}
