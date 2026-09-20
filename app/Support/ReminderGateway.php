<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class ReminderGateway
{
    public static function ready(string $provider): bool
    {
        if ($provider === 'smtp') {
            return config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host')) && filled(config('mail.from.address'));
        }
        $c = config('integrations.'.$provider, []);
        if ($provider === 'meta') {
            return filled($c['token'] ?? null) && preg_match('/^v[0-9]+\.[0-9]+$/', $c['version'] ?? '') && preg_match('/^[0-9]+$/', $c['phone_id'] ?? '') && filled($c['template'] ?? null);
        }

        return $provider === 'evolution' && str_starts_with($c['url'] ?? '', 'https://') && filled($c['token'] ?? null) && filled($c['instance'] ?? null);
    }

    public static function send(string $provider, string $destination, array $parts): array
    {
        if (! self::ready($provider)) {
            return ['status' => 'failed', 'error_code' => 'provider_not_configured'];
        }
        [$name,$professional,$date,$time,$phone] = $parts;
        $text = "Olá, {$name}! Lembramos que sua consulta com {$professional} está agendada para {$date}, às {$time}. Caso precise cancelar ou reagendar, entre em contato diretamente com o profissional pelo telefone {$phone}.";
        if ($provider === 'smtp') {
            config(['mail.mailers.smtp.timeout' => 5]);
            Mail::raw($text, fn ($message) => $message->to($destination)->subject('Lembrete de consulta'));

            return ['status' => 'accepted'];
        }
        $c = config('integrations.'.$provider);
        $http = Http::acceptJson()->connectTimeout(3)->timeout(5)->withoutRedirecting();
        if ($provider === 'meta') {
            $response = $http->withToken($c['token'])->post('https://graph.facebook.com/'.$c['version'].'/'.$c['phone_id'].'/messages', ['messaging_product' => 'whatsapp', 'to' => $destination, 'type' => 'template', 'template' => ['name' => $c['template'], 'language' => ['code' => $c['language']], 'components' => [['type' => 'body', 'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => $value], $parts)]]]]);
            $id = $response->json('messages.0.id');
        } else {
            $response = $http->withHeaders(['apikey' => $c['token']])->post(rtrim($c['url'], '/').'/message/sendText/'.rawurlencode($c['instance']), ['number' => $destination, 'text' => $text]);
            $id = $response->json('key.id');
        }
        if ($response->successful() && is_string($id) && $id !== '') {
            return ['status' => 'accepted', 'provider_message_id' => $id];
        }

        return ['status' => ($response->clientError() && $response->status() !== 408) ? 'failed' : 'unknown', 'error_code' => 'http_'.$response->status()];
    }
}
