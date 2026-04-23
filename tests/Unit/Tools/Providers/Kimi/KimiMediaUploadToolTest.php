<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\Kimi;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Tools\Providers\Kimi\KimiMediaUploadTool;

class KimiMediaUploadToolTest extends TestCase
{
    public function test_attributes_declare_network_cost_sensitive(): void
    {
        $tool = $this->makeTool([]);
        $attrs = $tool->attributes();
        $this->assertContains('network', $attrs);
        $this->assertContains('cost', $attrs);
        $this->assertContains('sensitive', $attrs);
    }

    public function test_rejects_missing_file_path(): void
    {
        $tool = $this->makeTool([]);
        $result = $tool->execute(['mime_type' => 'video/mp4']);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('file_path', $result->contentAsString());
    }

    public function test_rejects_missing_mime_type(): void
    {
        $tmp = $this->makeTmpFile('x');
        try {
            $tool = $this->makeTool([]);
            $result = $tool->execute(['file_path' => $tmp]);
            $this->assertTrue($result->isError);
            $this->assertStringContainsString('mime_type', $result->contentAsString());
        } finally {
            @unlink($tmp);
        }
    }

    public function test_rejects_unknown_purpose_without_override(): void
    {
        $tmp = $this->makeTmpFile('x');
        try {
            $tool = $this->makeTool([]);
            $result = $tool->execute([
                'file_path' => $tmp,
                'mime_type' => 'application/pdf',   // not video/*, not image/*
            ]);
            $this->assertTrue($result->isError);
            $this->assertStringContainsString('derive purpose', $result->contentAsString());
        } finally {
            @unlink($tmp);
        }
    }

    public function test_uploads_video_and_returns_ms_uri(): void
    {
        $tmp = $this->makeTmpFile("\x00\x00\x00 ftypmp42");
        try {
            $history = [];
            $tool = $this->makeToolWithHistory([
                new Response(200, [], json_encode(['id' => 'file_vid_42'])),
            ], $history);

            $result = $tool->execute([
                'file_path' => $tmp,
                'mime_type' => 'video/mp4',
            ]);

            $this->assertFalse($result->isError);
            $data = $result->content;
            $this->assertSame('file_vid_42', $data['file_id']);
            $this->assertSame('ms://file_vid_42', $data['uri']);
            $this->assertSame('video', $data['purpose']);
            $this->assertSame('video/mp4', $data['mime_type']);

            $this->assertCount(1, $history);
            $req = $history[0]['request'];
            $this->assertSame('POST', $req->getMethod());
            $this->assertStringEndsWith('/v1/files', $req->getUri()->getPath());
            $this->assertStringStartsWith(
                'multipart/form-data',
                $req->getHeaderLine('Content-Type'),
            );
            $body = (string) $req->getBody();
            $this->assertStringContainsString('name="purpose"', $body);
            $this->assertStringContainsString('video', $body);
            $this->assertStringContainsString('video/mp4', $body);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_derives_image_purpose_from_mime(): void
    {
        $tmp = $this->makeTmpFile('fake-png');
        try {
            $history = [];
            $tool = $this->makeToolWithHistory([
                new Response(200, [], json_encode(['id' => 'img_7'])),
            ], $history);

            $result = $tool->execute([
                'file_path' => $tmp,
                'mime_type' => 'image/png',
            ]);
            $this->assertFalse($result->isError);
            $this->assertSame('image', $result->content['purpose']);
            $this->assertSame('ms://img_7', $result->content['uri']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_explicit_purpose_overrides_mime_derivation(): void
    {
        // Lets the caller force "video" on a mime the deriver doesn't
        // recognise — escape hatch for exotic container types.
        $tmp = $this->makeTmpFile('x');
        try {
            $history = [];
            $tool = $this->makeToolWithHistory([
                new Response(200, [], json_encode(['id' => 'f_x'])),
            ], $history);

            $result = $tool->execute([
                'file_path' => $tmp,
                'mime_type' => 'application/octet-stream',
                'purpose'   => 'video',
            ]);
            $this->assertFalse($result->isError);
            $this->assertSame('video', $result->content['purpose']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_upload_without_id_is_surfaced_as_error(): void
    {
        $tmp = $this->makeTmpFile('x');
        try {
            $history = [];
            $tool = $this->makeToolWithHistory([
                new Response(200, [], json_encode(['status' => 'weird'])),
            ], $history);

            $result = $tool->execute([
                'file_path' => $tmp,
                'mime_type' => 'image/png',
            ]);
            $this->assertTrue($result->isError);
            $this->assertStringContainsString('file id', $result->contentAsString());
        } finally {
            @unlink($tmp);
        }
    }

    public function test_derive_purpose_static_helper(): void
    {
        $this->assertSame('video', KimiMediaUploadTool::derivePurpose('video/mp4'));
        $this->assertSame('video', KimiMediaUploadTool::derivePurpose('VIDEO/MP4'));   // case-insensitive
        $this->assertSame('image', KimiMediaUploadTool::derivePurpose('image/png'));
        $this->assertNull(KimiMediaUploadTool::derivePurpose('application/pdf'));
        $this->assertNull(KimiMediaUploadTool::derivePurpose(''));
    }

    // ── helpers ──────────────────────────────────────────────────

    private function makeTmpFile(string $contents): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kmu_');
        file_put_contents($tmp, $contents);
        return $tmp;
    }

    private function makeTool(array $responses): KimiMediaUploadTool
    {
        $history = [];
        return $this->makeToolWithHistory($responses, $history);
    }

    /**
     * @param array<int, Response>                  $responses
     * @param array<int, array<string, mixed>>      $history   captured by reference
     */
    private function makeToolWithHistory(array $responses, array &$history): KimiMediaUploadTool
    {
        $provider = new KimiProvider(['api_key' => 'sk-test']);

        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.moonshot.ai/',
        ]);

        $ref = new \ReflectionObject($provider);
        while ($ref && ! $ref->hasProperty('client')) {
            $ref = $ref->getParentClass();
        }
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($provider, $client);

        return new KimiMediaUploadTool($provider);
    }
}
