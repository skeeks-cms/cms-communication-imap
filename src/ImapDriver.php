<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\imap;

use skeeks\cms\communication\drivers\ConnectionDriver;
use skeeks\cms\communication\mail\MailDriverInterface;
use skeeks\cms\communication\mail\MailSendException;
use skeeks\cms\communication\mail\MailSessionInterface;
use skeeks\yii2\form\fields\NumberField;
use skeeks\yii2\form\fields\SelectField;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use yii\helpers\ArrayHelper;

/**
 * A mail server login: incoming mail over IMAP, outgoing over SMTP.
 * Works with any service: Yandex 360, Mail.ru, Gmail, an own server.
 * Use an application password where the service requires one.
 */
class ImapDriver extends ConnectionDriver implements MailDriverInterface
{
    /** Seconds to wait for the servers. */
    public int $timeout = 30;

    public const ENCRYPTION_SSL = 'ssl';
    public const ENCRYPTION_STARTTLS = 'starttls';
    public const ENCRYPTION_NONE = 'none';

    public $imap_host = '';
    public $imap_port = 993;
    public $imap_encryption = self::ENCRYPTION_SSL;

    public $smtp_host = '';
    public $smtp_port = 465;
    public $smtp_encryption = self::ENCRYPTION_SSL;

    public $username = '';
    public $password = '';

    public static function title(): string
    {
        return 'Почта IMAP/SMTP';
    }

    public static function channel(): string
    {
        return 'email';
    }

    public static function encryptions(): array
    {
        return [
            self::ENCRYPTION_SSL => 'SSL/TLS',
            self::ENCRYPTION_STARTTLS => 'STARTTLS',
            self::ENCRYPTION_NONE => 'Без шифрования',
        ];
    }

    public function secretAttributes(): array
    {
        return ['password'];
    }

    /** Servers of common mail services: domain => [imap host, smtp host, smtp port, smtp encryption]. */
    public const PRESETS = [
        'yandex' => ['imap.yandex.ru', 'smtp.yandex.ru', 465, self::ENCRYPTION_SSL],
        'mailru' => ['imap.mail.ru', 'smtp.mail.ru', 465, self::ENCRYPTION_SSL],
        'gmail' => ['imap.gmail.com', 'smtp.gmail.com', 465, self::ENCRYPTION_SSL],
        'rambler' => ['imap.rambler.ru', 'smtp.rambler.ru', 465, self::ENCRYPTION_SSL],
        'outlook' => ['outlook.office365.com', 'smtp.office365.com', 587, self::ENCRYPTION_STARTTLS],
    ];

    /**
     * Buttons of the mailbox form that fill the server fields:
     * title => [imap host, imap port, imap encryption, smtp host, smtp port, smtp encryption].
     */
    public static function presetButtons(): array
    {
        $buttons = [];
        $titles = ['yandex' => 'Яндекс и Яндекс 360', 'mailru' => 'Mail.ru', 'gmail' => 'Gmail', 'rambler' => 'Рамблер', 'outlook' => 'Outlook'];
        foreach ($titles as $key => $title) {
            [$imap, $smtp, $smtpPort, $smtpEncryption] = self::PRESETS[$key];
            $buttons[$title] = [
                'imap_host' => $imap, 'imap_port' => 993, 'imap_encryption' => self::ENCRYPTION_SSL,
                'smtp_host' => $smtp, 'smtp_port' => $smtpPort, 'smtp_encryption' => $smtpEncryption,
            ];
        }

        return $buttons;
    }

    private const DOMAIN_PRESETS = [
        'yandex.ru' => 'yandex', 'ya.ru' => 'yandex', 'yandex.com' => 'yandex', 'narod.ru' => 'yandex',
        'mail.ru' => 'mailru', 'bk.ru' => 'mailru', 'inbox.ru' => 'mailru', 'list.ru' => 'mailru', 'internet.ru' => 'mailru',
        'gmail.com' => 'gmail', 'googlemail.com' => 'gmail',
        'rambler.ru' => 'rambler',
        'outlook.com' => 'outlook', 'hotmail.com' => 'outlook', 'live.com' => 'outlook',
    ];

    /** Mail exchangers of corporate domains hosted by these services. */
    private const MX_PRESETS = [
        'yandex.net' => 'yandex', 'yandex.ru' => 'yandex',
        'mail.ru' => 'mailru',
        'google.com' => 'gmail', 'googlemail.com' => 'gmail',
        'outlook.com' => 'outlook',
    ];

    public function applyDefaultsForAddress(string $address): void
    {
        $address = mb_strtolower(trim($address));
        if ($this->username === '' || $this->username === null) {
            $this->username = $address;
        }
        if ($this->imap_host && $this->smtp_host) {
            return;
        }
        $preset = self::presetForDomain((string)substr((string)strrchr($address, '@'), 1));
        if ($preset === null) {
            return;
        }
        [$imap, $smtp, $smtpPort, $smtpEncryption] = self::PRESETS[$preset];
        if (!$this->imap_host) {
            $this->imap_host = $imap;
            $this->imap_port = 993;
            $this->imap_encryption = self::ENCRYPTION_SSL;
        }
        if (!$this->smtp_host) {
            $this->smtp_host = $smtp;
            $this->smtp_port = $smtpPort;
            $this->smtp_encryption = $smtpEncryption;
        }
    }

    /** Preset of a public service domain, or of a corporate domain by its MX records. */
    public static function presetForDomain(string $domain): ?string
    {
        $domain = mb_strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }
        if (isset(self::DOMAIN_PRESETS[$domain])) {
            return self::DOMAIN_PRESETS[$domain];
        }
        $records = @dns_get_record($domain, DNS_MX) ?: [];
        foreach ($records as $record) {
            $target = mb_strtolower(rtrim((string)($record['target'] ?? ''), '.'));
            foreach (self::MX_PRESETS as $suffix => $preset) {
                if ($target === $suffix || str_ends_with($target, '.'.$suffix)) {
                    return $preset;
                }
            }
        }

        return null;
    }

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['imap_host', 'smtp_host', 'username', 'password'], 'filter', 'filter' => static fn($v) => trim((string)$v)],
            // Filled from the mailbox address on the server; no browser-side check.
            [['imap_host', 'smtp_host', 'username'], 'required', 'whenClient' => 'function () { return false; }',
                'message' => 'Укажите «{attribute}»: для этого домена сервер не определился автоматически.'],
            [['password'], 'required', 'when' => static fn(self $m) => $m->requireSecrets, 'whenClient' => 'function () { return false; }',
                'message' => 'Укажите пароль приложения.'],
            [['imap_host', 'smtp_host', 'username'], 'string', 'max' => 255],
            [['password'], 'string', 'max' => 1024],
            [['imap_port', 'smtp_port'], 'integer', 'min' => 1, 'max' => 65535],
            [['imap_encryption', 'smtp_encryption'], 'in', 'range' => array_keys(self::encryptions())],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'imap_host' => 'Сервер входящей почты (IMAP)',
            'imap_port' => 'Порт IMAP',
            'imap_encryption' => 'Шифрование IMAP',
            'smtp_host' => 'Сервер исходящей почты (SMTP)',
            'smtp_port' => 'Порт SMTP',
            'smtp_encryption' => 'Шифрование SMTP',
            'username' => 'Логин',
            'password' => 'Пароль',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'imap_host' => 'Например imap.yandex.ru, imap.mail.ru, imap.gmail.com.',
            'smtp_host' => 'Например smtp.yandex.ru, smtp.mail.ru, smtp.gmail.com.',
            'username' => 'Обычно полный адрес ящика.',
            'password' => 'Пароль приложения, если сервис его требует (Яндекс, Mail.ru, Gmail).',
        ]);
    }

    public function checkConnection(): array
    {
        $result = [];

        try {
            $session = $this->openSession();
            $folders = $session->folders();
            $session->close();
            $roles = array_column(array_filter($folders, static fn($f) => $f['role'] !== null), 'name', 'role');
            $result[] = ['label' => 'Входящая почта (IMAP)', 'ok' => true, 'message' => sprintf(
                'Вход выполнен. Папок: %d. Входящие: %s. Отправленные: %s.',
                count($folders),
                $roles['inbox'] ?? 'не найдены',
                isset($roles['sent']) ? mb_convert_encoding($roles['sent'], 'UTF-8', 'UTF7-IMAP') : 'не найдены'
            )];
        } catch (\Throwable $e) {
            $result[] = ['label' => 'Входящая почта (IMAP)', 'ok' => false, 'message' => $this->safeMessage($e)];
        }

        try {
            $transport = $this->createSmtpTransport();
            $transport->start();
            $transport->stop();
            $result[] = ['label' => 'Исходящая почта (SMTP)', 'ok' => true, 'message' => 'Вход выполнен.'];
        } catch (\Throwable $e) {
            $result[] = ['label' => 'Исходящая почта (SMTP)', 'ok' => false, 'message' => $this->safeMessage($e)];
        }

        return $result;
    }

    public function openSession(): MailSessionInterface
    {
        $client = new ImapClient($this->imap_host, (int)$this->imap_port, $this->imap_encryption, $this->timeout);
        $client->connect();
        $client->login($this->username, $this->password);

        return new ImapSession($client);
    }

    public function send(Email $email): void
    {
        $transport = $this->createSmtpTransport();
        try {
            $transport->start();
        } catch (\Throwable $e) {
            // Nothing was sent yet.
            throw MailSendException::failed($this->safeMessage($e));
        }

        try {
            $transport->send($email);
        } catch (TransportExceptionInterface $e) {
            $message = $this->safeMessage($e);
            // A broken connection or a timeout after DATA leaves the outcome unknown.
            if (preg_match('/timed? ?out|closed unexpectedly|broken pipe|connection.*(reset|lost)/i', $e->getMessage())) {
                throw MailSendException::uncertain($message);
            }
            throw MailSendException::failed($message);
        } finally {
            try {
                $transport->stop();
            } catch (\Throwable $e) {
            }
        }
    }

    private function createSmtpTransport(): EsmtpTransport
    {
        $transport = new EsmtpTransport($this->smtp_host, (int)$this->smtp_port, $this->smtp_encryption === self::ENCRYPTION_SSL);
        if ($this->smtp_encryption === self::ENCRYPTION_NONE) {
            $transport->setAutoTls(false);
        } elseif ($this->smtp_encryption === self::ENCRYPTION_STARTTLS) {
            $transport->setRequireTls(true);
        }
        $transport->setUsername($this->username);
        $transport->setPassword($this->password);
        $transport->getStream()->setTimeout($this->timeout);

        return $transport;
    }

    /** Error text without the login password. */
    private function safeMessage(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($this->password !== '') {
            $message = str_replace([$this->password, base64_encode($this->password)], '***', $message);
        }

        return mb_substr($message, 0, 500);
    }

    public function getConfigFormFields()
    {
        return [
            'username',
            'password',
            'imap_host',
            'imap_port' => ['class' => NumberField::class],
            'imap_encryption' => ['class' => SelectField::class, 'items' => self::encryptions(), 'allowNull' => false],
            'smtp_host',
            'smtp_port' => ['class' => NumberField::class],
            'smtp_encryption' => ['class' => SelectField::class, 'items' => self::encryptions(), 'allowNull' => false],
        ];
    }
}
