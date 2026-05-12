<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundMessage;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VkWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $payload = $request->all();
        $type = $payload['type'] ?? '';
        $groupId = (int) ($payload['group_id'] ?? 0);

        $account = SocialAccount::query()
            ->where('provider', SocialAccount::PROVIDER_VK_GROUP)
            ->where('provider_user_id', (string) $groupId)
            ->first();

        if (! $account) {
            return response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }

        $secret = $account->meta['callback_secret'] ?? null;
        if ($secret !== null && ($payload['secret'] ?? null) !== $secret) {
            return response('forbidden', 403, ['Content-Type' => 'text/plain']);
        }

        if ($type === 'confirmation') {
            $code = (string) ($account->meta['confirmation_code'] ?? '');

            $meta = $account->meta ?? [];
            if (($meta['confirmation_pending'] ?? false) === true && empty($meta['callback_confirmed_at'])) {
                $meta['callback_confirmed_at'] = now()->toIso8601String();
                $meta['confirmation_pending'] = false;
                $account->meta = $meta;
                $account->save();
            }

            return response($code, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }

        if ($type === 'message_new') {
            $this->dispatchMessage($account, $payload);
        }

        return response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    private function dispatchMessage(SocialAccount $account, array $payload): void
    {
        $object = $payload['object'] ?? [];
        $message = $object['message'] ?? $object;
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            return;
        }

        $peerId = (int) ($message['peer_id'] ?? 0);
        $fromId = (int) ($message['from_id'] ?? 0);
        $groupId = (int) ($payload['group_id'] ?? 0);
        $cmid = (int) ($message['conversation_message_id'] ?? $message['id'] ?? 0);
        $idempotencyKey = $groupId.'_'.$peerId.'_'.$cmid;

        ProcessInboundMessage::dispatch(
            $account->id,
            $groupId,
            $peerId,
            $fromId,
            $text,
            $idempotencyKey,
        );
    }
}
