<?php

declare(strict_types=1);

use LandingOps\Contract\Json;
use LandingOps\Contract\ConfigValidator;
use LandingOps\Template\TemplateValidator;
use LandingOps\Tests\FixtureFactory;
use LandingOps\Tests\TestHarness as T;
use LandingOps\Zip\SafeExtractor;
use LandingOps\Zip\ZipLimits;

return [
    'validator accepts the minimal LTS v1.0 template' => static function (): void {
        $dir = T::tempDirectory('valid-template');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue($validation->result->isValid(), Json::encode((object) $validation->result->toArray(), true));
        T::assertSame('minimal-static-download', $validation->template?->manifest->template->slug);
    },

    'validator rejects ZIP path traversal' => static function (): void {
        $dir = T::tempDirectory('path-traversal');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip, extra: ['../escape.txt' => 'bad']);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertFalse($validation->result->isValid());
        T::assertTrue(in_array('zip_path_traversal', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator rejects dangerous environment files' => static function (): void {
        $dir = T::tempDirectory('dangerous-file');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip, extra: ['.env' => 'TOKEN=do-not-store']);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array('zip_forbidden_file', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator rejects Unix symlink entries' => static function (): void {
        $dir = T::tempDirectory('symlink');
        $zip = $dir . '/template.zip';
        $entries = ['index.html' => 'x', 'landingops.json' => '{}', 'assets/link' => '../outside'];
        FixtureFactory::zip($zip, $entries, ['assets/link' => 0120777]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array('zip_special_file', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator enforces file count limits' => static function (): void {
        $dir = T::tempDirectory('file-count');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $limits = new ZipLimits(maxFiles: 3);
        $validation = (new TemplateValidator($limits))->validate($zip);
        T::assertTrue(in_array('zip_file_count_exceeded', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator enforces compressed archive size limits' => static function (): void {
        $dir = T::tempDirectory('archive-size');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $limits = new ZipLimits(maxArchiveBytes: 64);
        $validation = (new TemplateValidator($limits))->validate($zip);
        T::assertTrue(in_array('zip_too_large', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator enforces uncompressed single file limits' => static function (): void {
        $dir = T::tempDirectory('entry-size');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $limits = new ZipLimits(maxFileBytes: 128);
        $validation = (new TemplateValidator($limits))->validate($zip);
        T::assertTrue(in_array('zip_entry_too_large', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator rejects unknown manifest fields' => static function (): void {
        $dir = T::tempDirectory('manifest-unknown');
        $zip = $dir . '/template.zip';
        $manifest = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/landingops.json'), 'manifest');
        $manifest->screenshot = 'forbidden.png';
        FixtureFactory::sampleZip($zip, replace: ['landingops.json' => Json::encode($manifest, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array('manifest_unknown_field', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator requires config schema for configurable templates' => static function (): void {
        $dir = T::tempDirectory('missing-schema');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip, skip: ['config.schema.json']);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array('template_schema_required', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator enforces native admin capability consistency' => static function (): void {
        $dir = T::tempDirectory('admin-mismatch');
        $zip = $dir . '/template.zip';
        $manifest = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/landingops.json'), 'manifest');
        $manifest->native_admin->supported = true;
        $manifest->native_admin->entry = 'admin/index.php';
        $manifest->native_admin->auth = 'local_password';
        FixtureFactory::sampleZip($zip, replace: ['landingops.json' => Json::encode($manifest, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array('capability_admin_entry', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator rejects remote schema references' => static function (): void {
        $dir = T::tempDirectory('remote-ref');
        $zip = $dir . '/template.zip';
        $schema = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/config.schema.json'), 'schema');
        $schema->properties->events->properties->download->{'$ref'} = 'https://evil.example/schema.json';
        FixtureFactory::sampleZip($zip, replace: ['config.schema.json' => Json::encode($schema, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array('schema_remote_ref', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator requires public schema annotations' => static function (): void {
        $dir = T::tempDirectory('public-annotation');
        $zip = $dir . '/template.zip';
        $schema = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/config.schema.json'), 'schema');
        unset($schema->properties->pixel_ids->{'x-landingops-visibility'});
        FixtureFactory::sampleZip($zip, replace: ['config.schema.json' => Json::encode($schema, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array('schema_public_visibility', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator rejects private descendants beneath a public path' => static function (): void {
        $dir = T::tempDirectory('private-descendant');
        $zip = $dir . '/template.zip';
        $schema = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/config.schema.json'), 'schema');
        $schema->properties->events->properties->server_token = (object) [
            'type' => 'string',
            'x-landingops-visibility' => 'private',
        ];
        FixtureFactory::sampleZip($zip, replace: ['config.schema.json' => Json::encode($schema, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array('schema_public_contains_private', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator rejects unmarked descendants beneath a public object' => static function (): void {
        $dir = T::tempDirectory('unmarked-descendant');
        $zip = $dir . '/template.zip';
        $schema = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/config.schema.json'), 'schema');
        unset($schema->{'$defs'}->toggle->properties->enabled->{'x-landingops-visibility'});
        FixtureFactory::sampleZip($zip, replace: ['config.schema.json' => Json::encode($schema, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array(
            'schema_public_descendant_visibility',
            array_column($validation->result->toArray()['errors'], 'code'),
            true,
        ));
    },

    'validator rejects unmarked items beneath a public array' => static function (): void {
        $dir = T::tempDirectory('unmarked-array-items');
        $zip = $dir . '/template.zip';
        $schema = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/config.schema.json'), 'schema');
        unset($schema->properties->pixel_ids->items->{'x-landingops-visibility'});
        FixtureFactory::sampleZip($zip, replace: ['config.schema.json' => Json::encode($schema, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array(
            'schema_public_descendant_visibility',
            array_column($validation->result->toArray()['errors'], 'code'),
            true,
        ));
    },

    'validator accepts recursively explicit public nested references' => static function (): void {
        $dir = T::tempDirectory('nested-ref-public');
        $zip = $dir . '/template.zip';
        $schema = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/config.schema.json'), 'schema');
        $schema->{'$defs'}->toggleAlias = (object) [
            '$ref' => '#/$defs/toggle',
            'x-landingops-visibility' => 'public',
        ];
        $schema->properties->events->properties->download->{'$ref'} = '#/$defs/toggleAlias';
        FixtureFactory::sampleZip($zip, replace: ['config.schema.json' => Json::encode($schema, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue($validation->result->isValid(), Json::encode((object) $validation->result->toArray(), true));
    },

    'validator rejects direct self references in private definitions' => static function (): void {
        $dir = T::tempDirectory('private-self-ref');
        $zip = $dir . '/template.zip';
        $schema = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/config.schema.json'), 'schema');
        $schema->{'$defs'}->privateLoop = (object) [
            '$ref' => '#/$defs/privateLoop',
            'x-landingops-visibility' => 'private',
        ];
        $schema->properties->private_note = (object) [
            '$ref' => '#/$defs/privateLoop',
            'x-landingops-visibility' => 'private',
        ];
        FixtureFactory::sampleZip($zip, replace: ['config.schema.json' => Json::encode($schema, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array(
            'schema_ref_cycle',
            array_column($validation->result->toArray()['errors'], 'code'),
            true,
        ));
    },

    'validator rejects indirect cycles across unused private definitions' => static function (): void {
        $dir = T::tempDirectory('private-double-ref');
        $zip = $dir . '/template.zip';
        $schema = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/config.schema.json'), 'schema');
        $schema->{'$defs'}->privateA = (object) [
            '$ref' => '#/$defs/privateB',
            'x-landingops-visibility' => 'private',
        ];
        $schema->{'$defs'}->privateB = (object) [
            '$ref' => '#/$defs/privateA',
            'x-landingops-visibility' => 'private',
        ];
        FixtureFactory::sampleZip($zip, replace: ['config.schema.json' => Json::encode($schema, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array(
            'schema_ref_cycle',
            array_column($validation->result->toArray()['errors'], 'code'),
            true,
        ));
    },

    'validator accepts an acyclic nested private reference chain' => static function (): void {
        $dir = T::tempDirectory('private-ref-chain');
        $zip = $dir . '/template.zip';
        $schema = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/config.schema.json'), 'schema');
        $schema->{'$defs'}->privateString = (object) [
            'type' => 'string',
            'maxLength' => 200,
            'x-landingops-visibility' => 'private',
        ];
        $schema->{'$defs'}->privateAliasB = (object) [
            '$ref' => '#/$defs/privateString',
            'x-landingops-visibility' => 'private',
        ];
        $schema->{'$defs'}->privateAliasA = (object) [
            '$ref' => '#/$defs/privateAliasB',
            'x-landingops-visibility' => 'private',
        ];
        $schema->properties->private_note = (object) [
            '$ref' => '#/$defs/privateAliasA',
            'x-landingops-visibility' => 'private',
        ];
        FixtureFactory::sampleZip($zip, replace: ['config.schema.json' => Json::encode($schema, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue($validation->result->isValid(), Json::encode((object) $validation->result->toArray(), true));
    },

    'config validator stops cyclic schemas even when contract validation is bypassed' => static function (): void {
        $schema = (object) [
            '$defs' => (object) [
                'a' => (object) ['$ref' => '#/$defs/b'],
                'b' => (object) ['$ref' => '#/$defs/a'],
            ],
            '$ref' => '#/$defs/a',
        ];
        $result = (new ConfigValidator())->validate('value', $schema);
        T::assertTrue(in_array(
            'config_schema_cycle',
            array_column($result->toArray()['errors'], 'code'),
            true,
        ));
    },

    'config validator accepts a legal nested reference chain' => static function (): void {
        $schema = (object) [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['value'],
            'properties' => (object) [
                'value' => (object) ['$ref' => '#/$defs/a'],
            ],
            '$defs' => (object) [
                'a' => (object) ['$ref' => '#/$defs/b'],
                'b' => (object) ['type' => 'string', 'minLength' => 1],
            ],
        ];
        $result = (new ConfigValidator())->validate((object) ['value' => 'valid'], $schema);
        T::assertTrue($result->isValid(), Json::encode((object) $result->toArray(), true));
    },

    'config validator enforces a maximum recursive schema depth' => static function (): void {
        $schema = (object) ['type' => 'string'];
        $config = 'leaf';
        for ($index = 0; $index < 140; $index++) {
            $schema = (object) [
                'type' => 'object',
                'properties' => (object) ['child' => $schema],
            ];
            $config = (object) ['child' => $config];
        }
        $result = (new ConfigValidator())->validate($config, $schema);
        T::assertTrue(in_array(
            'config_recursion_limit',
            array_column($result->toArray()['errors'], 'code'),
            true,
        ));
    },

    'validator rejects unmarked nested reference targets' => static function (): void {
        $dir = T::tempDirectory('nested-ref-unmarked');
        $zip = $dir . '/template.zip';
        $schema = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/config.schema.json'), 'schema');
        $schema->{'$defs'}->toggleAlias = (object) ['$ref' => '#/$defs/toggle'];
        $schema->properties->events->properties->download->{'$ref'} = '#/$defs/toggleAlias';
        FixtureFactory::sampleZip($zip, replace: ['config.schema.json' => Json::encode($schema, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array(
            'schema_public_descendant_visibility',
            array_column($validation->result->toArray()['errors'], 'code'),
            true,
        ));
    },

    'validator rejects missing local schema definitions' => static function (): void {
        $dir = T::tempDirectory('missing-ref');
        $zip = $dir . '/template.zip';
        $schema = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/config.schema.json'), 'schema');
        $schema->properties->events->properties->download->{'$ref'} = '#/$defs/missing';
        FixtureFactory::sampleZip($zip, replace: ['config.schema.json' => Json::encode($schema, true)]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array('schema_ref_missing', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'validator rejects embedded private key content' => static function (): void {
        $dir = T::tempDirectory('private-key');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip, extra: [
            'assets/config.txt' => "-----BEGIN PRIVATE KEY-----\nnot-a-real-key\n-----END PRIVATE KEY-----",
        ]);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue(in_array('template_private_key_content', array_column($validation->result->toArray()['errors'], 'code'), true));
    },

    'extractor uses the validated private snapshot after source ZIP replacement' => static function (): void {
        $dir = T::tempDirectory('zip-replacement');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $validation = (new TemplateValidator())->validate($zip);
        T::assertTrue($validation->result->isValid());
        $archive = $validation->template?->archive;
        T::assertTrue($archive !== null);
        $validatedHash = $archive->sha256;

        unlink($zip);
        FixtureFactory::zip($zip, [
            'landingops.json' => '{}',
            'index.html' => '<h1>REPLACED-ZIP-CONTENT</h1>',
        ]);
        T::assertFalse(hash_equals($validatedHash, (string) hash_file('sha256', $zip)));

        $destination = $dir . '/extracted';
        (new SafeExtractor())->extract($archive, $destination);
        $index = (string) file_get_contents($destination . '/index.html');
        T::assertContains('LandingOps 最小模板', $index);
        T::assertNotContains('REPLACED-ZIP-CONTENT', $index);
    },
];
