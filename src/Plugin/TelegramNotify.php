<?php

namespace PHPCensor\Plugin;

use Exception;
use GuzzleHttp\Client;
use PHPCensor\Builder;
use PHPCensor\Common\Exception\InvalidArgumentException;
use PHPCensor\Model\Build;
use PHPCensor\Plugin;

/**
 * Telegram Plugin
 *
 * @package    PHP Censor
 * @subpackage Application
 *
 * @author LEXASOFT <lexasoft83@gmail.com>
 * @author Dmitry Khomutov <poisoncorpsee@gmail.com>
 */
class TelegramNotify extends Plugin
{
    protected $authToken;
    protected $message;
    protected $buildMsg;
    protected $recipients;
    protected $sendLog;

    /**
     * @return string
     */
    public static function pluginName()
    {
        return 'telegram_notify';
    }

    /**
     * Standard Constructor
     *
     * @throws Exception
     */
    public function __construct(Builder $builder, Build $build, array $options = [])
    {
        parent::__construct($builder, $build, $options);

        if (empty($options['auth_token'])) {
            throw new InvalidArgumentException("Not setting telegram 'auth_token'");
        }

        if (empty($options['recipients'])) {
            throw new InvalidArgumentException("Not setting recipients");
        }

        if (\array_key_exists('auth_token', $options)) {
            $this->authToken = $this->builder->interpolate($options['auth_token'], true);
        }

        $this->message = '[%ICON_BUILD%] [%PROJECT_TITLE%](%PROJECT_LINK%)' .
            ' - [Build #%BUILD_ID%](%BUILD_LINK%) has finished ' .
            'for commit [%SHORT_COMMIT_ID% (%COMMITTER_EMAIL%)](%COMMIT_LINK%) ' .
            'on branch [%BRANCH%](%BRANCH_LINK%)';

        if (isset($options['message'])) {
            $this->message = $options['message'];
        }

        $this->recipients = [];
        if (\is_string($options['recipients'])) {
            $this->recipients = [$options['recipients']];
        } elseif (\is_array($options['recipients'])) {
            $this->recipients = $options['recipients'];
        }

        $this->sendLog = isset($options['send_log']) && ((bool)$options['send_log'] !== false);
    }

    /**
     * Run Telegram plugin.
     * @return bool
     */
    public function execute()
    {
        $message = $this->buildMessage();
        $client  = new Client();
        $url     = '/bot' . $this->authToken . '/sendMessage';

        foreach ($this->recipients as $chatId) {
            $chatId = $this->builder->interpolate($chatId, true);
            [$chatId, $topicId] = $this->splitChatIdAndTopicId($chatId);

            $params = [
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ];

            if ($topicId !== null) {
                $params['message_thread_id'] = $topicId;
            }

            $client->post(('https://api.telegram.org' . $url), [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $params,
            ]);

            if ($this->sendLog) {
                $params = [
                    'chat_id'    => $chatId,
                    'text'       => $this->buildMsg,
                    'parse_mode' => 'Markdown',
                ];

                if ($topicId !== null) {
                    $params['message_thread_id'] = $topicId;
                }

                $client->post(('https://api.telegram.org' . $url), [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $params,
                ]);
            }
        }

        return true;
    }

    /**
     * Build message.
     * @return string
     */
    private function buildMessage()
    {
        $this->buildMsg = '';
        $buildIcon      = $this->build->isSuccessful() ? '✅' : '❌';
        $buildLog       = $this->build->getLog();
        $buildLog       = \str_replace(['[0;32m', '[0;31m', '[0m', '/[0m'], '', $buildLog);
        $buildMessages  = \explode('RUNNING PLUGIN: ', $buildLog);

        foreach ($buildMessages as $bm) {
            $pos      = \mb_strpos($bm, "\n");
            $firstRow = \mb_substr($bm, 0, $pos);

            //skip long outputs
            if (\in_array($firstRow, ['slack_notify', 'php_loc', 'telegram_notify'], true)) {
                continue;
            }

            $this->buildMsg .= '*RUNNING PLUGIN: ' . $firstRow . "*\n";
            $this->buildMsg .= $firstRow === 'composer' ? '' : ('```' . \mb_substr($bm, $pos) . '```');
        }

        return $this->builder->interpolate(\str_replace(['%ICON_BUILD%'], [$buildIcon], $this->message));
    }

    /**
     * Split chat group id to chat id and topic id
     *
     * @param int|string $chatId
     * @return array{string, string|null}
     */
    protected function splitChatIdAndTopicId($chatId)
    {
        $parts = \explode('/', \trim((string) $chatId) . '/');
        $topicId = $parts[1] !== '' ? $parts[1] : null;

        return [$parts[0], $topicId];
    }
}
