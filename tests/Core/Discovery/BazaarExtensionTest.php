<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Discovery;

use Itsjustmoha\X402\Core\Codec\Json;
use Itsjustmoha\X402\Core\Discovery\BazaarExtension;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use PHPUnit\Framework\TestCase;

final class BazaarExtensionTest extends TestCase
{
    public function test_builds_a_query_method_declaration_like_the_specification_example(): void
    {
        $extension = BazaarExtension::forHttp('get', [
            'input' => ['city' => 'San Francisco'],
            'input_schema' => ['properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            'output' => ['city' => 'San Francisco', 'weather' => 'foggy', 'temperature' => 60],
        ])['bazaar'];

        self::assertSame(['type' => 'http', 'method' => 'GET', 'queryParams' => ['city' => 'San Francisco']], $extension['info']['input']);
        self::assertSame(['type' => 'json', 'example' => ['city' => 'San Francisco', 'weather' => 'foggy', 'temperature' => 60]], $extension['info']['output']);

        $input = $extension['schema']['properties']['input'];
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $extension['schema']['$schema']);
        self::assertSame(['GET'], $input['properties']['method']['enum']);
        self::assertSame(['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']], $input['properties']['queryParams']);
        self::assertSame(['type', 'method'], $input['required']);
        self::assertFalse($input['additionalProperties']);
        self::assertSame(['input'], $extension['schema']['required']);
        self::assertArrayNotHasKey('routeTemplate', $extension);
    }

    public function test_builds_a_body_method_declaration(): void
    {
        $extension = BazaarExtension::forHttp('POST', ['input' => ['query' => 'example'], 'body_type' => 'json'])['bazaar'];

        self::assertSame(['type' => 'http', 'method' => 'POST', 'bodyType' => 'json', 'body' => ['query' => 'example']], $extension['info']['input']);
        self::assertSame(['type', 'method', 'bodyType', 'body'], $extension['schema']['properties']['input']['required']);
        self::assertArrayNotHasKey('output', $extension['info']);
    }

    public function test_empty_objects_are_encoded_as_json_objects(): void
    {
        $json = Json::encode(BazaarExtension::forHttp('GET', []));

        self::assertStringContainsString('"queryParams":{}', $json);
        self::assertStringContainsString('"queryParams":{"type":"object","properties":{}}', $json);
    }

    public function test_adds_path_params_and_a_valid_route_template(): void
    {
        $extension = BazaarExtension::forHttp('GET', [], '/users/:userId', ['userId' => '123'])['bazaar'];

        self::assertSame('/users/:userId', $extension['routeTemplate']);
        self::assertSame(['userId' => '123'], $extension['info']['input']['pathParams']);
        self::assertSame(['type' => 'object'], $extension['schema']['properties']['input']['properties']['pathParams']);
    }

    public function test_drops_route_templates_that_fail_the_specification_rules(): void
    {
        foreach (['users/:id', '/users/../admin', '/a/%2e%2e/b', '/x/http://evil', '/with space', ''] as $template) {
            self::assertFalse(BazaarExtension::isValidRouteTemplate($template), $template);
            self::assertArrayNotHasKey('routeTemplate', BazaarExtension::forHttp('GET', [], $template)['bazaar']);
        }

        self::assertTrue(BazaarExtension::isValidRouteTemplate('/weather/:country/:city'));
    }

    public function test_rejects_invalid_combinations(): void
    {
        $invalid = [
            ['OPTIONS', []],
            ['GET', ['body_type' => 'json']],
            ['POST', ['body_type' => 'xml']],
            ['GET', ['input' => 'city=Berlin']],
        ];

        foreach ($invalid as [$method, $options]) {
            try {
                BazaarExtension::forHttp($method, $options);
                self::fail($method.' '.json_encode($options).' should have been rejected');
            } catch (InvalidConfiguration) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
