<?php

namespace App\ExternalApi;

use Illuminate\Support\Facades\Http;

class CpfLookup
{
    public static function valid(string $cpf): bool
    {
        if (! preg_match('/^[0-9]{11}$/', $cpf) || preg_match('/^(.)\1{10}$/', $cpf)) {
            return false;
        }
        for ($length = 9; $length <= 10; $length++) {
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cpf[$i] * ($length + 1 - $i);
            }
            $digit = ($sum * 10) % 11;
            if (($digit === 10 ? 0 : $digit) !== (int) $cpf[$length]) {
                return false;
            }
        }

        return true;
    }

    /** @return array{status: string, name?: string} */
    public static function consult(string $cpf): array
    {
        $url = config('integrations.cpf.url');
        $token = config('integrations.cpf.token');
        $localHttp = is_string($url)
            && app()->environment(['local', 'homologacao'])
            && parse_url($url, PHP_URL_SCHEME) === 'http'
            && parse_url($url, PHP_URL_HOST) === '127.0.0.1';
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || (parse_url($url, PHP_URL_SCHEME) !== 'https' && ! $localHttp) || ! is_string($token) || $token === '') {
            return ['status' => 'unavailable'];
        }
        try {
            $response = Http::acceptJson()->withToken($token)->connectTimeout(3)->timeout(20)->withoutRedirecting()->post(rtrim($url, '/').'/consulta/cpf', ['cpf' => $cpf]);
            if (! $response->successful()) {
                return ['status' => 'unavailable'];
            }
            $data = $response->json();
            if (! is_array($data) || ! empty($data['erro']) || preg_replace('/[^0-9]/', '', (string) ($data['cpf'] ?? '')) !== $cpf) {
                return ['status' => 'unavailable'];
            }
            if (($data['encontrado'] ?? null) === false && ($data['cpf_inexistente'] ?? false) === true) {
                return ['status' => 'not_recognized'];
            }
            if (($data['encontrado'] ?? null) === false) {
                return ['status' => 'not_found'];
            }
            $name = $data['nome_certidao'] ?? null;
            if (($data['encontrado'] ?? null) !== true || ! is_string($name) || trim($name) === '' || mb_strlen($name) > 160) {
                return ['status' => 'unavailable'];
            }
            if (isset($data['cpf_certidao']) && preg_replace('/[^0-9]/', '', (string) $data['cpf_certidao']) !== $cpf) {
                return ['status' => 'unavailable'];
            }

            return ['status' => 'found', 'name' => trim($name)];
        } catch (\Throwable) {
            return ['status' => 'unavailable'];
        }
    }
}
