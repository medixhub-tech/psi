<?php

namespace App\ExternalApi;

use Illuminate\Support\Facades\Http;

class ViaCep
{
    public static function consult(string $cep): array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()->get('https://viacep.com.br/ws/'.$cep.'/json/');
            $data = $response->json();
            if (! $response->successful() || ! is_array($data)) {
                return ['status' => 'unavailable'];
            }
            if (! empty($data['erro'])) {
                return ['status' => 'not_found'];
            }
            if (preg_replace('/[^0-9]/', '', $data['cep'] ?? '') !== $cep || ! is_string($data['localidade'] ?? null) || ! preg_match('/^[A-Z]{2}$/', $data['uf'] ?? '')) {
                return ['status' => 'unavailable'];
            }
            $address = [];
            foreach (['street' => 'logradouro', 'district' => 'bairro', 'city' => 'localidade', 'state' => 'uf'] as $field => $source) {
                if (! is_string($data[$source] ?? null) || mb_strlen($data[$source]) > 160) {
                    return ['status' => 'unavailable'];
                }
                $address[$field] = $data[$source];
            }

            return ['status' => 'found', 'address' => $address];
        } catch (\Throwable) {
            return ['status' => 'unavailable'];
        }
    }
}
