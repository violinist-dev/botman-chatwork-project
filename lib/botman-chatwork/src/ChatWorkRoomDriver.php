<?php

namespace Revolution\BotMan\Drivers\ChatWork;

use BotMan\BotMan\Users\User;
use Illuminate\Support\Collection;
use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Interfaces\VerifiesService;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Outgoing\Question;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\HeaderBag;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\Messages\Conversations\Conversation;

class ChatWorkRoomDriver extends HttpDriver
{
    const DRIVER_NAME = 'ChatWorkRoom';

    const API_ENDPOINT = 'https://api.chatwork.com/v2/';

    const EVENT_TYPE = 'message_created';

    const TOKEN_TYPE = 'webhook_room_token';

    const ACCOUNT_ID = 'account_id';

    protected $messages = [];

    /**
     * @var HeaderBag
     */
    protected $headers = null;

    /**
     * @param Request $request
     */
    public function buildPayload(Request $request)
    {
        $this->config = Collection::make($this->config->get('chatwork', []));

        $this->payload = new ParameterBag((array)json_decode($request->getContent(), true));

        $this->event = Collection::make($this->payload->get('webhook_event'));

        $this->headers = $request->headers;
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        return $this->validateSignature() && $this->payload->get('webhook_event_type') === static::EVENT_TYPE;
    }

    /**
     * @param  \BotMan\BotMan\Messages\Incoming\IncomingMessage $message
     *
     * @return \BotMan\BotMan\Messages\Incoming\Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        return Answer::create($message->getText())->setMessage($message);
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        if (empty($this->messages)) {
            $messageText = $this->event->get('body');
            $account_id = $this->event->get(static::ACCOUNT_ID);
            $room_id = $this->event->get('room_id');
            $this->messages = [new IncomingMessage($messageText, $account_id, $room_id, $this->event)];
        }

        return $this->messages;
    }

    /**
     * @return bool
     */
    protected function isBot()
    {
        return false;
    }

    /**
     * @param string|OutgoingMessage|Question $message
     * @param IncomingMessage                 $matchingMessage
     * @param array                           $additionalParameters
     *s
     *
     * @return array
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        info($message);

        if ($message instanceof Question) {
            $payload['body'] = $this->getReply($matchingMessage) . $message->getText();
        } elseif ($message instanceof OutgoingMessage) {
            $payload['body'] = $this->getReply($matchingMessage) . $message->getText();
        } else {
            $payload['body'] = $this->getReply($matchingMessage) . $message;
        }

        return $payload;
    }

    /**
     * @param mixed $payload
     *
     * @return Response
     */
    public function sendPayload($payload)
    {
        $headers = [
            'X-ChatWorkToken: ' . $this->config->get('api_token'),
        ];

        info($payload);

        info($this->event->get('room_id'));

        $res = $this->http->post(
            self::API_ENDPOINT . 'rooms/' . $this->event->get('room_id') . '/messages',
            [],
            $payload,
            $headers);

        info($res);

        return $res;
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->config->get(static::TOKEN_TYPE));
    }

    /**
     *
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     *
     * @return string
     */
    public function getReply(IncomingMessage $matchingMessage)
    {
        $payload = $matchingMessage->getPayload();

        $rp = "[rp aid={$payload->get(static::ACCOUNT_ID)} to={$payload->get('room_id')}-{$payload->get('message_id')}]\n";

        return $rp;
    }

    /**
     * Retrieve User information.
     *
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     *
     * @return User
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        $payload = $matchingMessage->getPayload();

        return new User($payload->get(static::ACCOUNT_ID));
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string          $endpoint
     * @param array           $parameters
     * @param IncomingMessage $matchingMessage
     *
     * @return Response
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        $headers = [
            'X-ChatWorkToken: ' . $this->config->get('api_token'),
        ];

        return $this->http->post(self::API_ENDPOINT . $endpoint, [], $parameters, $headers);
    }

    /**
     * @return bool
     */
    protected function validateSignature()
    {
        info(static::class);

        $known = $this->headers->get('X-ChatWorkWebhookSignature', '');

        info($known);

        $hash = hash_hmac('sha256', $this->content, base64_decode($this->config->get(static::TOKEN_TYPE)), true);
        $hash = base64_encode($hash);

        info('hash : ' . $hash);

        return hash_equals(
            $known,
            $hash
        );
    }
}
