<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Captcha\Model;

/**
 * Implementation of \Zend\Captcha\Image
 *
 * @author     Magento Core Team <core@magentocommerce.com>
 */
class DefaultModel extends \Zend\Captcha\Image implements \Magento\Captcha\Model\CaptchaInterface
{
    /**
     * Key in session for captcha code
     */
    const SESSION_WORD = 'word';

    /**
     * Min captcha lengths default value
     */
    const DEFAULT_WORD_LENGTH_FROM = 3;

    /**
     * Max captcha lengths default value
     */
    const DEFAULT_WORD_LENGTH_TO = 5;

    /**
     * @var \Magento\Captcha\Helper\Data
     */
    protected $captchaData;

    /**
     * Captcha expire time
     * @var int
     */
    protected $expiration;

    /**
     * Override default value to prevent a captcha cut off
     * @var int
     * @see \Zend\Captcha\Image::$fsize
     */
    protected $fsize = 22;

    /**
     * Captcha form id
     * @var string
     */
    protected $formId;

    /**
     * @var \Magento\Captcha\Model\ResourceModel\LogFactory
     */
    protected $resLogFactory;

    /**
     * Overrides parent parameter as session comes in constructor.
     *
     * @var bool
     */
    protected $keepSession = true;

    /**
     * @var \Magento\Framework\Session\SessionManagerInterface
     */
    protected $session;

    /**
     * @param \Magento\Framework\Session\SessionManagerInterface $session
     * @param \Magento\Captcha\Helper\Data $captchaData
     * @param ResourceModel\LogFactory $resLogFactory
     * @param string $formId
     * @throws \Zend\Captcha\Exception\ExtensionNotLoadedException
     */
    public function __construct(
        \Magento\Framework\Session\SessionManagerInterface $session,
        \Magento\Captcha\Helper\Data $captchaData,
        \Magento\Captcha\Model\ResourceModel\LogFactory $resLogFactory,
        $formId
    ) {
        parent::__construct();
        $this->session = $session;
        $this->captchaData = $captchaData;
        $this->resLogFactory = $resLogFactory;
        $this->formId = $formId;
    }

    /**
     * Returns key with respect of current form ID
     *
     * @param string $key
     * @return string
     */
    private function getFormIdKey($key)
    {
        return $this->formId . '_' . $key;
    }

    /**
     * Get Block Name
     *
     * @return string
     */
    public function getBlockName()
    {
        return \Magento\Captcha\Block\Captcha\DefaultCaptcha::class;
    }

    /**
     * Whether captcha is required to be inserted to this form
     *
     * @param null|string $login
     * @return bool
     */
    public function isRequired($login = null)
    {
        if ($this->isUserAuth()
            && !$this->isShownToLoggedInUser()
            || !$this->isEnabled()
            || !in_array(
                $this->formId,
                $this->getTargetForms()
            )
        ) {
            return false;
        }

        return $this->isShowAlways()
            || $this->isOverLimitAttempts($login)
            || $this->session->getData($this->getFormIdKey('show_captcha'));
    }

    /**
     * Check if CAPTCHA has to be shown to logged in user on this form
     *
     * @return bool
     */
    public function isShownToLoggedInUser()
    {
        $forms = (array)$this->captchaData->getConfig('shown_to_logged_in_user');
        foreach ($forms as $formId => $isShownToLoggedIn) {
            if ($isShownToLoggedIn && $this->formId == $formId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check is over limit attempts
     *
     * @param string $login
     * @return bool
     */
    private function isOverLimitAttempts($login)
    {
        return $this->isOverLimitIpAttempt() || $this->isOverLimitLoginAttempts($login);
    }

    /**
     * Returns number of allowed attempts for same login
     *
     * @return int
     */
    private function getAllowedAttemptsForSameLogin()
    {
        return (int)$this->captchaData->getConfig('failed_attempts_login');
    }

    /**
     * Returns number of allowed attempts from same IP
     *
     * @return int
     */
    private function getAllowedAttemptsFromSameIp()
    {
        return (int)$this->captchaData->getConfig('failed_attempts_ip');
    }

    /**
     * Check is over limit saved attempts from one ip
     *
     * @return bool
     */
    private function isOverLimitIpAttempt()
    {
        $countAttemptsByIp = $this->getResourceModel()->countAttemptsByRemoteAddress();
        return $countAttemptsByIp >= $this->getAllowedAttemptsFromSameIp();
    }

    /**
     * Is Over Limit Login Attempts
     *
     * @param string $login
     * @return bool
     */
    private function isOverLimitLoginAttempts($login)
    {
        if ($login != false) {
            $countAttemptsByLogin = $this->getResourceModel()->countAttemptsByUserLogin($login);
            return $countAttemptsByLogin >= $this->getAllowedAttemptsForSameLogin();
        }
        return false;
    }

    /**
     * Check is user auth
     *
     * @return bool
     */
    private function isUserAuth()
    {
        return $this->session->isLoggedIn();
    }

    /**
     * Whether to respect case while checking the answer
     *
     * @return bool
     */
    public function isCaseSensitive()
    {
        return (string)$this->captchaData->getConfig('case_sensitive');
    }

    /**
     * Get font to use when generating captcha
     *
     * @return string
     */
    public function getFont()
    {
        $font = (string)$this->captchaData->getConfig('font');
        $fonts = $this->captchaData->getFonts();

        if (isset($fonts[$font])) {
            $fontPath = $fonts[$font]['path'];
        } else {
            $fontData = array_shift($fonts);
            $fontPath = $fontData['path'];
        }

        return $fontPath;
    }

    /**
     * After this time isCorrect() is going to return FALSE even if word was guessed correctly
     *
     * @return int
     */
    public function getExpiration()
    {
        if (!$this->expiration) {
            /**
             * as "timeout" configuration parameter specifies timeout in minutes - we multiply it on 60 to set
             * expiration in seconds
             */
            $this->expiration = (int)$this->captchaData->getConfig('timeout') * 60;
        }
        return $this->expiration;
    }

    /**
     * Get timeout for session token
     *
     * @return int
     */
    public function getTimeout()
    {
        return $this->getExpiration();
    }

    /**
     * Get captcha image directory
     *
     * @return string
     */
    public function getImgDir()
    {
        return $this->captchaData->getImgDir();
    }

    /**
     * Get captcha image base URL
     *
     * @return string
     */
    public function getImgUrl()
    {
        return $this->captchaData->getImgUrl();
    }

    /**
     * Checks whether captcha was guessed correctly by user
     *
     * @param string $word
     * @return bool
     */
    public function isCorrect($word)
    {
        $storedWord = $this->getWord();
        $this->clearWord();

        if (!$word || !$storedWord) {
            return false;
        }

        if (!$this->isCaseSensitive()) {
            $storedWord = strtolower($storedWord);
            $word = strtolower($word);
        }
        return $word === $storedWord;
    }

    /**
     * Return full URL to captcha image
     *
     * @return string
     */
    public function getImgSrc()
    {
        return $this->getImgUrl() . $this->getId() . $this->getSuffix();
    }

    /**
     * Log attempt
     *
     * @param string $login
     * @return $this
     */
    public function logAttempt($login)
    {
        if ($this->isEnabled() && in_array($this->formId, $this->getTargetForms())) {
            $this->getResourceModel()->logAttempt($login);
            if ($this->isOverLimitLoginAttempts($login)) {
                $this->setShowCaptchaInSession(true);
            }
        }
        return $this;
    }

    /**
     * Set show_captcha flag in session
     *
     * @param bool $value
     * @return void
     */
    public function setShowCaptchaInSession($value = true)
    {
        if ($value !== true) {
            $value = false;
        }

        $this->session->setData($this->getFormIdKey('show_captcha'), $value);
    }

    /**
     * Generate word used for captcha render
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function generateWord()
    {
        $word = '';
        $symbols = $this->getSymbols();
        $wordLen = $this->getWordLen();
        for ($i = 0; $i < $wordLen; $i++) {
            $word .= $symbols[array_rand($symbols)];
        }
        return $word;
    }

    /**
     * Get symbols array to use for word generation
     *
     * @return array
     */
    private function getSymbols()
    {
        return str_split((string)$this->captchaData->getConfig('symbols'));
    }

    /**
     * Returns length for generating captcha word. This value may be dynamic.
     *
     * @return int
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getWordLen()
    {
        $from = 0;
        $to = 0;
        $length = (string)$this->captchaData->getConfig('length');
        if (!is_numeric($length)) {
            if (preg_match('/(\d+)-(\d+)/', $length, $matches)) {
                $from = (int)$matches[1];
                $to = (int)$matches[2];
            }
        } else {
            $from = (int)$length;
            $to = (int)$length;
        }

        if ($to < $from || $from < 1 || $to < 1) {
            $from = self::DEFAULT_WORD_LENGTH_FROM;
            $to = self::DEFAULT_WORD_LENGTH_TO;
        }

        return \Magento\Framework\Math\Random::getRandomNumber($from, $to);
    }

    /**
     * Whether to show captcha for this form every time
     *
     * @return bool
     */
    private function isShowAlways()
    {
        if ((string)$this->captchaData->getConfig('mode') == \Magento\Captcha\Helper\Data::MODE_ALWAYS) {
            return true;
        }

        if ((string)$this->captchaData->getConfig('mode') == \Magento\Captcha\Helper\Data::MODE_AFTER_FAIL
            && $this->getAllowedAttemptsForSameLogin() == 0
        ) {
            return true;
        }

        $alwaysFor = $this->captchaData->getConfig('always_for');
        foreach ($alwaysFor as $nodeFormId => $isAlwaysFor) {
            if ($isAlwaysFor && $this->formId == $nodeFormId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether captcha is enabled at this area
     *
     * @return bool
     */
    private function isEnabled()
    {
        return (string)$this->captchaData->getConfig('enable');
    }

    /**
     * Retrieve list of forms where captcha must be shown
     *
     * For frontend this list is based on current website
     *
     * @return array
     */
    private function getTargetForms()
    {
        $formsString = (string)$this->captchaData->getConfig('forms');
        return explode(',', $formsString);
    }

    /**
     * Get captcha word
     *
     * @return string
     */
    public function getWord()
    {
        $sessionData = $this->session->getData($this->getFormIdKey(self::SESSION_WORD));
        return time() < $sessionData['expires'] ? $sessionData['data'] : null;
    }

    /**
     * Set captcha word
     *
     * @param  string $word
     * @return $this
     */
    protected function setWord($word)
    {
        $this->session->setData(
            $this->getFormIdKey(self::SESSION_WORD),
            ['data' => $word, 'expires' => time() + $this->getTimeout()]
        );
        $this->word = $word;
        return $this;
    }

    /**
     * Set captcha word
     *
     * @return $this
     */
    private function clearWord()
    {
        $this->session->unsetData($this->getFormIdKey(self::SESSION_WORD));
        $this->word = null;
        return $this;
    }

    /**
     * Override function to generate less curly captcha that will not cut off
     *
     * @see \Zend\Captcha\Image::_randomSize()
     * @return int
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function randomSize()
    {
        return \Magento\Framework\Math\Random::getRandomNumber(280, 300) / 100;
    }

    /**
     * Overlap of the parent method
     *
     * @return void
     *
     * Now deleting old captcha images make crontab script
     * @see \Magento\Captcha\Cron\DeleteExpiredImages::execute
     *
     * Added SuppressWarnings since this method is declared in parent class and we can not use other method name.
     * @SuppressWarnings(PHPMD.ShortMethodName)
     */
    protected function gc()
    {
        //do nothing
    }

    /**
     * Get resource model
     *
     * @return \Magento\Captcha\Model\ResourceModel\Log
     */
    private function getResourceModel()
    {
        return $this->resLogFactory->create();
    }
}
