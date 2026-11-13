<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Auth;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;
use Throwable;

final class SessionService
{
    /** Public dummy value used only to equalize unknown-user password verification cost. */
    private const DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly PasswordHasher $passwords = new PasswordHasher(),
        private readonly IdGenerator $ids = new IdGenerator(),
        private readonly UtcClock $clock = new UtcClock(),
        private readonly int $ttlSeconds = 28800,
        ?AuditLogger $audit = null,
    ) {
        $this->audit = $audit ?? new AuditLogger($repository);
    }

    private readonly AuditLogger $audit;

    public function login(
        string $email,
        string $password,
        string $userAgent = '',
        string $ip = '',
        string $requestId = 'authentication',
    ): AuthenticatedSession
    {
        $normalized = strtolower(trim($email));
        $user = $this->repository->findUserByEmail($normalized);
        $storedHash = is_string($user['password_hash'] ?? null)
            ? $user['password_hash']
            : self::DUMMY_PASSWORD_HASH;
        $passwordValid = $this->passwords->verify($password, $storedHash);
        if ($user === null
            || ($user['status'] ?? null) !== 'active'
            || !$passwordValid
        ) {
            try {
                $this->audit->record(null, 'auth.login_failed', 'auth_attempt', $this->ids->generate(), $requestId, [
                    'identifier_hash' => hash('sha256', $normalized),
                ]);
            } catch (Throwable) {
                // 登录仍必须以统一的凭据错误结束，不能由审计故障泄漏账号是否存在。
            }
            throw new ApiException(401, 'invalid_credentials', '邮箱或密码错误。');
        }

        $now = $this->clock->now();
        $expires = $now->add(new DateInterval('PT' . $this->ttlSeconds . 'S'));
        $cookieToken = self::token();
        $csrfToken = self::token();
        $sessionId = $this->ids->generate();
        $this->repository->transaction(function () use (
            $user,
            $sessionId,
            $cookieToken,
            $csrfToken,
            $userAgent,
            $ip,
            $now,
            $expires,
            $requestId,
        ): void {
            $this->repository->insertSession([
                'id' => $sessionId,
                'user_id' => $user['id'],
                'token_hash' => self::hashToken($cookieToken),
                'csrf_hash' => self::hashToken($csrfToken),
                'user_agent_hash' => $userAgent === '' ? null : hash('sha256', $userAgent, true),
                'ip_hash' => $ip === '' ? null : hash('sha256', $ip, true),
                'created_at' => $now->format('Y-m-d H:i:s.u'),
                'last_seen_at' => $now->format('Y-m-d H:i:s.u'),
                'expires_at' => $expires->format('Y-m-d H:i:s.u'),
            ]);
            $this->repository->updateLastLogin((string) $user['id'], $now->format('Y-m-d H:i:s.u'));
            $this->audit->record(
                Principal::fromRow($user),
                'auth.login_succeeded',
                'user',
                (string) $user['id'],
                $requestId,
            );
        });

        return new AuthenticatedSession(
            Principal::fromRow($user),
            $sessionId,
            $cookieToken,
            $csrfToken,
            $expires->format(DATE_RFC3339_EXTENDED),
        );
    }

    /** @return array{principal:Principal,session:array<string,mixed>} */
    public function authenticate(string $cookieToken): array
    {
        if ($cookieToken === '') {
            throw new ApiException(401, 'authentication_required', '需要登录。');
        }
        $row = $this->repository->findSessionByHash(self::hashToken($cookieToken));
        if ($row === null) {
            throw new ApiException(401, 'session_invalid', '会话无效或已过期。');
        }
        $principal = Principal::fromRow($row);
        $expiresAt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.u',
            (string) $row['expires_at'],
            new DateTimeZone('UTC'),
        );
        if ($principal->status !== 'active' || $expiresAt === false || $expiresAt <= $this->clock->now()) {
            $this->repository->revokeSession((string) $row['session_id'], 'invalid_or_disabled', $this->clock->sql());
            throw new ApiException(401, 'session_invalid', '会话无效或已过期。');
        }
        return ['principal' => $principal, 'session' => $row];
    }

    /** @param array<string,mixed> $session */
    public function assertCsrf(array $session, string $token): void
    {
        $expected = $session['csrf_hash'] ?? null;
        if (!is_string($expected) || $token === '' || !hash_equals($expected, self::hashToken($token))) {
            throw new ApiException(403, 'csrf_invalid', 'CSRF Token 无效。');
        }
    }

    public function logout(string $sessionId, Principal $actor, string $requestId = 'authentication'): void
    {
        $this->repository->transaction(function () use ($sessionId, $actor, $requestId): void {
            $this->repository->revokeSession($sessionId, 'logout', $this->clock->sql());
            $this->audit->record($actor, 'auth.logout', 'user', $actor->id, $requestId);
        });
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token, true);
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
