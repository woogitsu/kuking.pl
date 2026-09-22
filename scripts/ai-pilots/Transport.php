<?php

declare(strict_types=1);

namespace Kuking\AiPilots;

use DomainException;
use Illuminate\Http\Client\Factory;
use Throwable;

/** Klient wyłącznie przyrządu CLI, bez połączenia z aplikacją i jej .env. */
final class Transport
{
    public const MODEL = 'gpt-5.4-nano-2026-03-17';

    public const ENDPOINT = 'https://api.openai.com/v1/responses';

    public const MAX_BODY_BYTES = 24000;

    public function __construct(private Factory $http, private string $key) {}

    public function payload(string $task, string $source): array
    {
        $payload = [
            'model' => self::MODEL, 'store' => false,
            'reasoning' => ['effort' => 'none'],
            'instructions' => Pilot::prompt($task),
            'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => Pilot::input($task, $source)]]]],
            'max_output_tokens' => $task === 'recipe' ? 1600 : 256,
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'kuking_'.$task, 'strict' => true, 'schema' => Pilot::schema($task)]],
        ];
        if (strlen(self::encode($payload)) > self::MAX_BODY_BYTES) {
            throw new DomainException('Skróć wejście pilota: całe żądanie przekracza 24000 bajtów.');
        }

        return $payload;
    }

    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function run(string $task, string $source): array
    {
        $payload = $this->payload($task, $source);
        if (trim($this->key) === '') {
            throw new DomainException('Udostępnij klucz w środowisku przyrządu.');
        }
        $body = self::encode($payload);
        $start = hrtime(true);
        $result = ['requests' => 1, 'input_chars' => mb_strlen($source), 'input_bytes' => strlen($source),
            'request_bytes' => strlen($body), 'model' => self::MODEL, 'usage' => null, 'cost_usd' => null,
            'proposal' => null, 'raw_proposal' => null, 'error' => null];
        try {
            // Bez ponowień, przekierowań, narzędzi, zdjęć i metadanych konta.
            $response = $this->http->withToken($this->key)->acceptJson()->connectTimeout(3)->timeout(10)
                ->withOptions(['allow_redirects' => false])->withBody($body, 'application/json')->post(self::ENDPOINT);
            $result['http_status'] = $response->status();
            if (! $response->successful()) {
                $result['error'] = 'http_'.$response->status();
            } elseif (strlen($response->body()) > 65536) {
                $result['error'] = 'response_too_large';
            } else {
                $data = $response->json();
                $usage = $data['usage'] ?? null;
                if (is_array($usage) && is_int($usage['input_tokens'] ?? null) && is_int($usage['output_tokens'] ?? null)
                    && $usage['input_tokens'] >= 0 && $usage['output_tokens'] >= 0) {
                    $cached = $usage['input_tokens_details']['cached_tokens'] ?? 0;
                    if (is_int($cached) && $cached >= 0 && $cached <= $usage['input_tokens']) {
                        $result['usage'] = $usage;
                        $result['cost_usd'] = (($usage['input_tokens'] - $cached) * 0.20 + $cached * 0.02 + $usage['output_tokens'] * 1.25) / 1000000;
                    }
                }
                if (($data['status'] ?? '') !== 'completed') {
                    $result['error'] = 'incomplete';
                } else {
                    $texts = [];
                    foreach (($data['output'] ?? []) as $item) {
                        foreach (($item['content'] ?? []) as $part) {
                            if (($part['type'] ?? '') === 'output_text' && is_string($part['text'] ?? null)) {
                                $texts[] = $part['text'];
                            }
                        }
                    }
                    if (count($texts) !== 1) {
                        $result['error'] = 'no_single_output';
                    } else {
                        $raw = json_decode($texts[0], true, 32, JSON_THROW_ON_ERROR);
                        $result['raw_proposal'] = $raw;
                        $result['proposal'] = Pilot::validate($task, $source, $raw);
                    }
                }
            }
        } catch (DomainException) {
            $result['error'] = 'invalid_proposal';
        } catch (\JsonException) {
            $result['error'] = 'invalid_json';
        } catch (Throwable) {
            // Nigdy getMessage(): wyjątek może zawierać treść i nagłówki.
            $result['error'] = 'transport_error';
        }
        $result['latency_ms'] = round((hrtime(true) - $start) / 1000000, 3);

        return $result;
    }
}
