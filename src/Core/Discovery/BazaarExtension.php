<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Discovery;

use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use stdClass;

/**
 * Builds the `bazaar` discovery extension for an HTTP endpoint
 * (specs/extensions/bazaar.md), mirroring the reference helpers
 * declareDiscoveryExtension() + enrichDeclaration() of @x402/extensions:
 * the `method` enum is narrowed to the actual request method, and dynamic
 * routes get `pathParams` plus a top-level `routeTemplate`.
 */
final class BazaarExtension
{
    public const string KEY = 'bazaar';

    private const string JSON_SCHEMA = 'https://json-schema.org/draft/2020-12/schema';

    private const array QUERY_METHODS = ['GET', 'HEAD', 'DELETE'];

    private const array BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    private const array BODY_TYPES = ['json', 'form-data', 'text'];

    private function __construct() {}

    /**
     * @param  array<string, mixed>  $options  input, input_schema, body_type, output, output_schema
     * @param  array<string, string>  $pathParams  concrete values of the route parameters of this request
     * @return array{bazaar: array<string, mixed>}
     *
     * @throws InvalidConfiguration
     */
    public static function forHttp(string $method, array $options, ?string $routeTemplate = null, array $pathParams = []): array
    {
        $method = strtoupper($method);
        $isBodyMethod = in_array($method, self::BODY_METHODS, true);

        if (! $isBodyMethod && ! in_array($method, self::QUERY_METHODS, true)) {
            throw new InvalidConfiguration(sprintf('Bazaar discovery does not support the %s method.', $method));
        }

        $input = self::arrayOption($options, 'input');
        $inputSchema = self::arrayOption($options, 'input_schema') ?? ['properties' => new stdClass];

        $info = ['type' => 'http', 'method' => $method];
        $properties = [
            'type' => ['type' => 'string', 'const' => 'http'],
            'method' => ['type' => 'string', 'enum' => [$method]],
        ];
        $required = ['type', 'method'];

        if ($isBodyMethod) {
            $bodyType = $options['body_type'] ?? 'json';

            if (! is_string($bodyType) || ! in_array($bodyType, self::BODY_TYPES, true)) {
                throw new InvalidConfiguration('Bazaar body_type must be one of: '.implode(', ', self::BODY_TYPES).'.');
            }

            $info['bodyType'] = $bodyType;
            $info['body'] = self::object($input ?? []);
            $properties['bodyType'] = ['type' => 'string', 'enum' => self::BODY_TYPES];
            $properties['body'] = $inputSchema;
            $required = [...$required, 'bodyType', 'body'];
        } else {
            if (isset($options['body_type'])) {
                throw new InvalidConfiguration(sprintf('Bazaar body_type only applies to POST, PUT and PATCH, not %s.', $method));
            }

            $info['queryParams'] = self::object($input ?? []);
            $properties['queryParams'] = ['type' => 'object'] + $inputSchema;
        }

        if ($pathParams !== []) {
            $info['pathParams'] = $pathParams;
            $properties['pathParams'] = ['type' => 'object'];
        }

        $declaration = [
            'info' => ['input' => $info],
            'schema' => [
                '$schema' => self::JSON_SCHEMA,
                'type' => 'object',
                'properties' => [
                    'input' => [
                        'type' => 'object',
                        'properties' => $properties,
                        'required' => $required,
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['input'],
            ],
        ];

        $output = $options['output'] ?? null;
        if ($output !== null && $output !== []) {
            $outputSchema = self::arrayOption($options, 'output_schema') ?? [];
            $declaration['info']['output'] = ['type' => 'json', 'example' => $output];
            $declaration['schema']['properties']['output'] = [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string'],
                    'example' => ['type' => is_array($output) && array_is_list($output) ? 'array' : 'object'] + $outputSchema,
                ],
                'required' => ['type'],
            ];
        }

        if ($routeTemplate !== null && self::isValidRouteTemplate($routeTemplate)) {
            $declaration['routeTemplate'] = $routeTemplate;
        }

        return [self::KEY => $declaration];
    }

    /**
     * Validation rules of specs/extensions/bazaar.md ("routeTemplate Validation Rules").
     */
    public static function isValidRouteTemplate(string $template): bool
    {
        $decoded = rawurldecode($template);

        return preg_match('#^/[a-zA-Z0-9_/:.\-~%]+$#D', $template) === 1
            && ! str_contains($decoded, '..')
            && ! str_contains($decoded, '://');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<array-key, mixed>|null
     *
     * @throws InvalidConfiguration
     */
    private static function arrayOption(array $options, string $key): ?array
    {
        $value = $options[$key] ?? null;

        if ($value !== null && ! is_array($value)) {
            throw new InvalidConfiguration(sprintf('Bazaar option "%s" must be an array.', $key));
        }

        return $value;
    }

    /**
     * Empty PHP arrays would be encoded as JSON lists; these slots must be objects.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>|stdClass
     */
    private static function object(array $value): array|stdClass
    {
        return $value === [] ? new stdClass : $value;
    }
}
