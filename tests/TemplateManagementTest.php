<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\Contract\Json;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\SessionService;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Http\ControlPlaneKernel;
use LandingOps\ControlPlane\Http\Request;
use LandingOps\ControlPlane\Service\AccountService;
use LandingOps\ControlPlane\Service\OwnershipTransferService;
use LandingOps\ControlPlane\Service\ResourceAccessService;
use LandingOps\ControlPlane\Service\TemplateGrantService;
use LandingOps\ControlPlane\Service\TemplateManagementService;
use LandingOps\ControlPlane\Template\PrivateTemplateStorage;
use LandingOps\ControlPlane\Template\UploadedTemplateFile;
use LandingOps\Exception\LandingOpsException;

/** @return array{0:TemplateManagementService,1:TemplateGrantService,2:PrivateTemplateStorage,3:string} */
function templateServices(MemoryControlPlaneRepository $repository, string $label): array
{
    $root = TestHarness::tempDirectory($label);
    $storage = new PrivateTemplateStorage($root . '/private-templates', webRoot: $root . '/public');
    $authorization = new AuthorizationService();
    $grants = new TemplateGrantService($repository, $authorization);
    return [
        new TemplateManagementService($repository, $storage, $authorization, grants: $grants),
        $grants,
        $storage,
        $root,
    ];
}

function uploadedFixture(string $directory, string $name = 'template.zip', array $replace = [], array $extra = []): UploadedTemplateFile
{
    $path = $directory . '/' . $name;
    FixtureFactory::sampleZip($path, replace: $replace, extra: $extra);
    return new UploadedTemplateFile($path, $name, (int) filesize($path));
}

/** @return list<string> */
function storedZipFiles(string $root): array
{
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'zip') {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

/** @return array<string,\Closure> */
return [
    'template upload validates stores immutable version and defaults team grant' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$service, , , $root] = templateServices($repository, 'template-upload');
        $file = uploadedFixture($root);
        $created = $service->upload($principals[ADMIN_A_ID], $file, 'upload-request');

        TestHarness::assertSame('valid', $created['validation_status']);
        TestHarness::assertSame(1, count($repository->templates));
        TestHarness::assertSame(2, count($repository->templateVersions));
        TestHarness::assertTrue($repository->hasTemplateGrant($created['template_version_id'], TEAM_A_ID));
        TestHarness::assertSame(1, count($service->list($principals[STAFF_A_ID])));
        TestHarness::assertSame([], $service->list($principals[STAFF_B_ID]));
        TestHarness::assertSame(1, count(storedZipFiles($root . '/private-templates')));
        TestHarness::assertTrue(count(array_filter(
            $repository->audits,
            static fn (array $audit): bool => $audit['action'] === 'template.version_upload',
        )) === 1);
    },

    'invalid template upload is rejected and private intake is cleaned' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$service, , , $root] = templateServices($repository, 'template-invalid');
        $file = uploadedFixture($root, extra: ['.env' => 'SECRET=must-not-survive']);
        $exception = TestHarness::assertThrows(
            static fn () => $service->upload($principals[ADMIN_A_ID], $file, 'invalid-request'),
            ApiException::class,
        );
        TestHarness::assertSame('template_validation_failed', $exception->errorCode);
        TestHarness::assertSame(0, count($repository->templates));
        TestHarness::assertSame(1, count($repository->templateVersions));
        TestHarness::assertSame([], storedZipFiles($root . '/private-templates'));
        TestHarness::assertNotContains('private-templates', json_encode($exception->details, JSON_THROW_ON_ERROR));
    },

    'template hash and immutable version conflicts are rejected without extra files' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$service, , , $root] = templateServices($repository, 'template-conflicts');
        $first = uploadedFixture($root, 'first.zip');
        $service->upload($principals[ADMIN_A_ID], $first, 'first');

        $same = uploadedFixture($root, 'same.zip');
        $hashConflict = TestHarness::assertThrows(
            static fn () => $service->upload($principals[ADMIN_A_ID], $same, 'same'),
            ApiException::class,
        );
        TestHarness::assertSame('template_hash_conflict', $hashConflict->errorCode);

        $changed = uploadedFixture($root, 'changed.zip', replace: ['assets/style.css' => 'body{color:#123456}']);
        $versionConflict = TestHarness::assertThrows(
            static fn () => $service->upload($principals[ADMIN_A_ID], $changed, 'changed'),
            ApiException::class,
        );
        TestHarness::assertSame('template_version_immutable', $versionConflict->errorCode);
        TestHarness::assertSame(1, count(storedZipFiles($root . '/private-templates')));
    },

    'template revoke hides future use while regrant restores visibility' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$service, $grants, , $root] = templateServices($repository, 'template-grants');
        $created = $service->upload($principals[ADMIN_A_ID], uploadedFixture($root), 'upload');
        $versionId = $created['template_version_id'];

        $grants->revokeBySuperAdmin($principals[SUPER_ID], $versionId, TEAM_A_ID, 'revoke');
        TestHarness::assertSame([], $service->list($principals[ADMIN_A_ID]));
        TestHarness::assertSame([], $service->list($principals[STAFF_A_ID]));
        $hiddenReport = TestHarness::assertThrows(
            static fn () => $service->validation($principals[STAFF_A_ID], $versionId),
            ApiException::class,
        );
        TestHarness::assertSame(404, $hiddenReport->status);
        TestHarness::assertSame(STAFF_A_ID, $repository->instances[INSTANCE_A_ID]['current_owner_user_id']);

        $grants->grantBySuperAdmin($principals[SUPER_ID], $versionId, TEAM_A_ID, 'regrant');
        TestHarness::assertSame(1, count($service->list($principals[STAFF_A_ID])));
        TestHarness::assertTrue(count(array_filter(
            $repository->audits,
            static fn (array $audit): bool => in_array($audit['action'], ['template.grant_revoke', 'template.grant_set'], true),
        )) === 2);
    },

    'staff cannot upload and cross team ownership is server enforced' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$service, , , $root] = templateServices($repository, 'template-role');
        $file = uploadedFixture($root);
        $staff = TestHarness::assertThrows(
            static fn () => $service->upload($principals[STAFF_A_ID], $file, 'staff'),
            ApiException::class,
        );
        TestHarness::assertSame(403, $staff->status);
        $service->upload($principals[ADMIN_A_ID], $file, 'team-a');

        $manifest = Json::decode((string) file_get_contents(FixtureFactory::sampleDirectory() . '/landingops.json'), 'manifest');
        $manifest->template->version = '1.0.1';
        $otherTeamFile = uploadedFixture($root, 'team-b.zip', replace: ['landingops.json' => Json::encode($manifest, true)]);
        $crossTeam = TestHarness::assertThrows(
            static fn () => $service->upload($principals[ADMIN_B_ID], $otherTeamFile, 'team-b'),
            ApiException::class,
        );
        TestHarness::assertSame('template_not_available', $crossTeam->errorCode);
    },

    'multipart template API uses csrf and returns validation contract' => static function (): void {
        [$repository] = controlFixture();
        [$management, $grants, , $root] = templateServices($repository, 'template-api');
        $authorization = new AuthorizationService();
        $kernel = new ControlPlaneKernel(
            new SessionService($repository),
            new AccountService($repository, $authorization),
            new ResourceAccessService($repository, $authorization),
            new OwnershipTransferService($repository, $authorization),
            templates: $management,
            templateGrants: $grants,
        );
        $login = $kernel->handle(new Request('POST', '/api/v1/login', [], [], [
            'email' => 'admin-a@example.test',
            'password' => 'Correct horse battery staple 123!',
        ]));
        preg_match('/landingops_session=([^;]+)/', $login->headers['Set-Cookie'], $matches);
        $cookie = rawurldecode($matches[1]);
        $csrf = $login->body['data']['csrf_token'];
        $file = uploadedFixture($root);
        $tooMany = $kernel->handle(new Request(
            'POST',
            '/api/v1/templates/uploads',
            ['X-CSRF-Token' => $csrf],
            [ControlPlaneKernel::COOKIE => $cookie],
            [],
            '',
            [
                'template_zip' => ['name' => $file->originalName, 'tmp_name' => $file->temporaryPath, 'size' => $file->size, 'error' => 0],
                'extra_zip' => ['name' => $file->originalName, 'tmp_name' => $file->temporaryPath, 'size' => $file->size, 'error' => 0],
            ],
        ));
        TestHarness::assertSame(422, $tooMany->status);
        TestHarness::assertSame('template_upload_count', $tooMany->body['error']['code']);
        $upload = $kernel->handle(new Request(
            'POST',
            '/api/v1/templates/uploads',
            ['X-CSRF-Token' => $csrf],
            [ControlPlaneKernel::COOKIE => $cookie],
            [],
            '',
            ['template_zip' => [
                'name' => $file->originalName,
                'tmp_name' => $file->temporaryPath,
                'size' => $file->size,
                'error' => $file->error,
            ]],
        ));
        TestHarness::assertSame(201, $upload->status);
        $versionId = $upload->body['data']['template_version_id'];
        $report = $kernel->handle(new Request(
            'GET',
            '/api/v1/template-versions/' . $versionId . '/validation',
            [],
            [ControlPlaneKernel::COOKIE => $cookie],
        ));
        TestHarness::assertSame(200, $report->status);
        TestHarness::assertSame(true, $report->body['data']['validation_report']['valid']);
    },

    'template private storage refuses a web root location' => static function (): void {
        $root = TestHarness::tempDirectory('template-web-root');
        mkdir($root . '/public', 0700, true);
        $exception = TestHarness::assertThrows(
            static fn () => new PrivateTemplateStorage($root . '/public/uploads', webRoot: $root . '/public'),
            LandingOpsException::class,
        );
        TestHarness::assertContains('Web Root', $exception->getMessage());
    },
];
