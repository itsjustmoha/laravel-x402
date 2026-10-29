<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Protocol;

use Itsjustmoha\X402\Core\Discovery\ServiceMetadata;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;

/**
 * The ResourceInfo object of x402 v2 (specification section 5.1.2).
 */
final readonly class ResourceInfo
{
    /**
     * @param  list<string>  $tags
     */
    private function __construct(
        public string $url,
        public string $description,
        public string $mimeType,
        public ?string $serviceName,
        public array $tags,
        public ?string $iconUrl,
    ) {}

    /**
     * Optional service metadata that fails the Bazaar validation rules is
     * dropped silently, exactly like the reference SDK helpers do.
     *
     * @param  array<array-key, mixed>  $tags
     *
     * @throws InvalidConfiguration when the URL is not an absolute http(s) URL
     */
    public static function create(
        string $url,
        string $description = '',
        string $mimeType = '',
        ?string $serviceName = null,
        array $tags = [],
        ?string $iconUrl = null,
    ): self {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new InvalidConfiguration(sprintf('Resource URL "%s" must be an absolute http(s) URL.', $url));
        }

        return new self(
            $url,
            $description,
            $mimeType,
            $serviceName !== null && ServiceMetadata::isValidServiceName($serviceName) ? $serviceName : null,
            ServiceMetadata::sanitizeTags($tags),
            $iconUrl !== null && ServiceMetadata::isValidIconUrl($iconUrl) ? $iconUrl : null,
        );
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function toArray(): array
    {
        $data = [
            'url' => $this->url,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
        ];

        if ($this->serviceName !== null) {
            $data['serviceName'] = $this->serviceName;
        }

        if ($this->tags !== []) {
            $data['tags'] = $this->tags;
        }

        if ($this->iconUrl !== null) {
            $data['iconUrl'] = $this->iconUrl;
        }

        return $data;
    }
}
