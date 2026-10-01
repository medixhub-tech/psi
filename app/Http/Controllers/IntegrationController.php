<?php

namespace App\Http\Controllers;

use App\ExternalApi\GoogleCalendar;
use App\ExternalApi\ReminderGateway;
use App\Support\Audit;
use App\Support\IntegrationCredentials;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IntegrationController extends Controller
{
    public function index(): View
    {
        return view('integrations.index', ['credentials' => ['evolution' => array_diff_key(IntegrationCredentials::get('evolution'), array_flip(['token'])), 'google' => array_diff_key(IntegrationCredentials::get('google'), array_flip(['client_secret']))], 'secrets' => ['evolution' => filled(IntegrationCredentials::get('evolution')['token'] ?? null), 'google' => filled(IntegrationCredentials::get('google')['client_secret'] ?? null)], 'settings' => DB::table('integration_settings')->where('id', 1)->first(), 'connection' => DB::table('google_connections')->where('active', true)->first(['id', 'calendar_id']), 'reminders' => DB::table('reminders')->orderByDesc('id')->paginate(20), 'calendar' => DB::table('calendar_events')->orderByDesc('id')->limit(20)->get(), 'ready' => ['evolution' => ReminderGateway::ready('evolution'), 'meta' => ReminderGateway::ready('meta'), 'smtp' => ReminderGateway::ready('smtp'), 'google' => GoogleCalendar::configured()]]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['whatsapp_enabled' => 'required|boolean', 'email_enabled' => 'required|boolean', 'whatsapp_provider' => ['required', Rule::in(['meta', 'evolution'])], 'lock_version' => 'required|integer|min:1']);
        if (($data['whatsapp_enabled'] && ! ReminderGateway::ready($data['whatsapp_provider'])) || ($data['email_enabled'] && ! ReminderGateway::ready('smtp'))) {
            throw ValidationException::withMessages(['integration' => 'Configure as credenciais do provedor antes de ativar.']);
        }
        DB::transaction(function () use ($data) {
            DB::table('practice')->where('id', 1)->lockForUpdate()->firstOrFail();
            $settings = DB::table('integration_settings')->where('id', 1)->lockForUpdate()->first();
            if ((int) $settings->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['integration' => 'A configuracao mudou. Reabra a pagina.']);
            }DB::table('integration_settings')->where('id', 1)->update(array_replace($data, ['lock_version' => $settings->lock_version + 1]));
            Audit::record('integrations.updated', 'integration_settings', 1);
        });

        return to_route('integrations.index')->with('status', 'Canais de lembrete atualizados.');
    }

    public function credentials(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['google', 'evolution'], true), 404);
        $request->validate(['lock_version' => 'required|integer|min:1']);
        $rules = $provider === 'google'
            ? ['client_id' => 'required|string|max:255', 'client_secret' => 'nullable|string|max:2048', 'calendar_id' => 'required|string|max:255|not_in:primary']
            : ['url' => 'required|url:https|max:2048', 'token' => 'nullable|string|max:2048', 'instance' => 'required|string|max:120'];
        $data = $request->validate($rules);
        if ($provider === 'evolution' && (parse_url($data['url'], PHP_URL_USER) !== null || parse_url($data['url'], PHP_URL_PASS) !== null || parse_url($data['url'], PHP_URL_QUERY) !== null || parse_url($data['url'], PHP_URL_FRAGMENT) !== null)) {
            throw ValidationException::withMessages(['url' => 'Use a URL base HTTPS, sem credenciais, parâmetros ou fragmento.']);
        }
        DB::transaction(function () use ($request, $provider, $data) {
            DB::table('practice')->where('id', 1)->lockForUpdate()->firstOrFail();
            $settings = DB::table('integration_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ((int) $settings->lock_version !== (int) $request->input('lock_version')) {
                throw ValidationException::withMessages(['integration' => 'A configuração mudou. Reabra a página.']);
            }
            if ($provider === 'google' && DB::table('google_connections')->where('active', true)->exists()) {
                throw ValidationException::withMessages(['integration' => 'Desconecte o Google antes de alterar suas credenciais ou calendário.']);
            }
            $secret = $provider === 'google' ? 'client_secret' : 'token';
            if (empty($data[$secret])) {
                $data[$secret] = IntegrationCredentials::get($provider)[$secret] ?? null;
            }
            if (empty($data[$secret])) {
                throw ValidationException::withMessages([$secret => 'Informe a credencial para concluir a configuração.']);
            }
            DB::table('integration_settings')->where('id', 1)->update([$provider.'_credentials' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'lock_version' => $settings->lock_version + 1]);
            Audit::record('integrations.credentials_updated', 'integration_settings', 1);
        });
        if ($provider === 'google') {
            $request->session()->forget('google_oauth');
        }

        return to_route('integrations.index')->with('status', 'Configuração salva. '.($provider === 'google' ? 'Agora autorize sua conta em Conectar Google Agenda.' : 'A instância deve estar conectada ao WhatsApp na Evolution API.'));
    }

    public function connect(Request $request): RedirectResponse
    {
        abort_unless(GoogleCalendar::configured(), 422, 'Salve as credenciais Google no painel antes de conectar.');
        $state = Str::random(64);
        $verifier = Str::random(64);
        $request->session()->put('google_oauth', ['state' => $state, 'verifier' => $verifier, 'fingerprint' => IntegrationCredentials::googleFingerprint(), 'at' => time(), 'calendar' => (IntegrationCredentials::get('google')['calendar_id'] ?? null)]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => (IntegrationCredentials::get('google')['client_id'] ?? null), 'redirect_uri' => route('integrations.google.callback'), 'response_type' => 'code', 'scope' => 'https://www.googleapis.com/auth/calendar.events', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256']));
    }

    public function callback(Request $request): RedirectResponse
    {
        $saved = $request->session()->pull('google_oauth');
        abort_unless(is_array($saved) && is_string($request->input('state')) && hash_equals($saved['state'], $request->input('state')) && time() - $saved['at'] < 600, 403);
        abort_unless(hash_equals(IntegrationCredentials::googleFingerprint(), $saved['fingerprint'] ?? ''), 403);
        if ($request->has('error')) {
            return to_route('integrations.index')->withErrors(['integration' => 'Conexao Google nao autorizada.']);
        }
        $data = $request->validate(['code' => 'required|string|max:4096']);
        try {
            $response = Http::asForm()->connectTimeout(3)->timeout(5)->withoutRedirecting()->post('https://oauth2.googleapis.com/token', ['client_id' => (IntegrationCredentials::get('google')['client_id'] ?? null), 'client_secret' => (IntegrationCredentials::get('google')['client_secret'] ?? null), 'redirect_uri' => route('integrations.google.callback'), 'code' => $data['code'], 'code_verifier' => $saved['verifier'], 'grant_type' => 'authorization_code']);
            $refresh = $response->json('refresh_token');
            if (! $response->successful() || ! is_string($refresh) || $refresh === '') {
                return to_route('integrations.index')->withErrors(['integration' => 'Nao foi possivel obter acesso offline. Conecte novamente.']);
            }
        } catch (\Throwable) {
            return to_route('integrations.index')->withErrors(['integration' => 'Falha de conexao com Google. Tente novamente.']);
        }
        DB::transaction(function () use ($refresh, $saved) {
            DB::table('practice')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(IntegrationCredentials::googleFingerprint(), $saved['fingerprint']), 403);
            DB::table('google_connections')->where('active', true)->update(['active' => false, 'refresh_token_ciphertext' => '']);
            $existing = DB::table('google_connections')->where('calendar_id', $saved['calendar'])->first();
            if ($existing) {
                $id = $existing->id;
                DB::table('google_connections')->where('id', $id)->update(['refresh_token_ciphertext' => Crypt::encryptString($refresh), 'active' => true]);
                DB::table('calendar_events')->where('connection_id', $id)->where('status', 'error')->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now()]);
            } else {
                $id = DB::table('google_connections')->insertGetId(['namespace' => (string) Str::uuid(), 'calendar_id' => $saved['calendar'], 'refresh_token_ciphertext' => Crypt::encryptString($refresh), 'active' => true, 'created_at' => now()]);
            }Audit::record('integrations.google_connected', 'google_connection', $id);
        });

        return to_route('integrations.index')->with('status', 'Google conectado. Consultas futuras serao exportadas pelo cron.');
    }

    public function disconnect(): RedirectResponse
    {
        DB::transaction(function () {
            DB::table('practice')->where('id', 1)->lockForUpdate()->firstOrFail();
            DB::table('google_connections')->where('active', true)->update(['active' => false, 'refresh_token_ciphertext' => '']);
            Audit::record('integrations.google_disconnected', 'practice', 1);
        });

        return to_route('integrations.index')->with('status', 'Sincronizacao interrompida. Eventos existentes no Google foram preservados.');
    }

    public function retryCalendar(int $event): RedirectResponse
    {
        DB::table('calendar_events')->where('id', $event)->where('status', 'error')->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'error_code' => null]);
        Audit::record('integrations.calendar_retry', 'calendar_event', $event);

        return to_route('integrations.index')->with('status', 'Sincronizacao colocada na fila.');
    }
}
