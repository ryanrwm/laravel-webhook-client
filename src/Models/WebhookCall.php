<?php

namespace Spatie\WebhookClient\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Spatie\WebhookClient\Exceptions\InvalidConfig;
use Spatie\WebhookClient\WebhookConfig;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Class WebhookCall
 * @package Spatie\WebhookClient\Models
 *
 * @property-read int $id
 * @property string $name
 * @property string $url
 * @property array|null $headers
 * @property array|null $payload
 * @property array|null $exception
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static Builder|WebhookCall newModelQuery()
 * @method static Builder|WebhookCall newQuery()
 * @method static Builder|WebhookCall query()
 * @method static Builder|WebhookCall whereId($value)
 * @method static Builder|WebhookCall whereName($value)
 * @method static Builder|WebhookCall wherePayload($value)
 * @method static Builder|WebhookCall whereException($value)
 * @method static Builder|WebhookCall whereCreatedAt($value)
 * @method static Builder|WebhookCall whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class WebhookCall extends Model
{
    use MassPrunable;

    public $guarded = [];

    protected $casts = [
        'headers' => 'array',
        'payload' => 'array',
        'exception' => 'array',
    ];

    public static function storeWebhook(WebhookConfig $config, Request $request): WebhookCall
    {
        $headers = self::headersToStore($config, $request);

        // Get basic payload data
        $payload = $request->input();

        // Process and store file attachments
        if ($request->allFiles()) {
            $storedFiles = [];

            foreach ($request->allFiles() as $key => $uploadedFiles) {
                $files = is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles];

                if (!isset($storedFiles[$key])) {
                    $storedFiles[$key] = [];
                }

                foreach ($files as $index => $file) {
                    $originalName = $file->getClientOriginalName();
                    $extension = $file->getClientOriginalExtension();
                    $mimeType = $file->getMimeType();
                    $size = $file->getSize();

                    // Generate unique filename
                    $filename = md5(uniqid() . $originalName) . '.' . $extension;

                    // Store file to storage/app/webhooks directory (or specify your preferred disk)
                    $storagePath = $file->storeAs('webhooks', $filename);

                    $storedFiles[$key][] = [
                        'original_name' => $originalName,
                        'storage_path' => $storagePath,
                        'mime_type' => $mimeType,
                        'size' => $size,
                    ];
                }
            }

            $payload['attachments'] = $storedFiles;
        }

        return self::create([
            'name' => $config->name,
            'url' => $request->fullUrl(),
            'headers' => $headers,
            'payload' => $payload,
            'exception' => null,
        ]);
    }

    public static function headersToStore(WebhookConfig $config, Request $request): array
    {
        $headerNamesToStore = $config->storeHeaders;

        if ($headerNamesToStore === '*') {
            return $request->headers->all();
        }

        $headerNamesToStore = array_map(fn(string $headerName) => strtolower($headerName), $headerNamesToStore);

        return collect($request->headers->all())
            ->filter(fn(array $headerValue, string $headerName) => in_array($headerName, $headerNamesToStore))
            ->toArray();
    }

    public function headerBag(): HeaderBag
    {
        return new HeaderBag($this->headers ?? []);
    }

    public function headers(): HeaderBag
    {
        return $this->headerBag();
    }

    public function saveException(Exception $exception): self
    {
        $this->exception = [
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ];

        $this->save();

        return $this;
    }

    public function clearException(): self
    {
        $this->exception = null;

        $this->save();

        return $this;
    }

    public function prunable()
    {
        $days = config('webhook-client.delete_after_days');

        if (!is_int($days)) {
            throw InvalidConfig::invalidPrunable($days);
        }

        return static::where('created_at', '<', now()->subDays($days));
    }
}
