<?php

namespace SLiMS\Plugins\Inventory\Api;

use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;

final class AuthController
{
    public const API_VERSION = 1;

    /** What the app checks right after a librarian picks this library. */
    public function discovery(Context $context): JsonResponse
    {
        return JsonResponse::ok([
            'api_version' => self::API_VERSION,
            'library_name' => Context::libraryName(),
            'grant_types' => AgentCodes::enabled($context->db) ? ['password', 'refresh_token', 'authorization_code'] : ['password', 'refresh_token'],
            // Where a librarian allows an AI app; Klaras Panel sends them here (see AgentCodes).
            'agent_authorize_url' => AgentCodes::enabled($context->db) ? AgentCodes::authorizeUrl() : null,
        ]);
    }

    public function issue(Context $context): JsonResponse
    {
        $input = $context->input();
        $tokens = new StaffTokens($context->db);
        $grant = $input->string('grant_type', 20, 'password');

        if ($grant === 'refresh_token') {
            $refreshed = $tokens->refresh($input->required('refresh_token', 200), $context->request->ip());
            if ($refreshed['kind'] === AgentCodes::KIND && !AgentCodes::enabled($context->db)) {
                throw AgentCodes::disabled();
            }
            $staff = Staff::fromUser($context->db, $refreshed['user'], $refreshed['session_id']);
            $this->assertCanUseApp($staff);

            return JsonResponse::ok([...$refreshed['tokens'], 'staff' => $staff->toArray()]);
        }
        if ($grant === 'authorization_code') {
            return $this->agent($context, $input, $tokens);
        }
        if ($grant !== 'password') {
            throw new ApiException('unsupported_grant_type', 'Jenis masuk tidak dikenal.', 400);
        }

        $user = (new StaffAuthenticator($context->db))->attempt(
            $input->required('username', 50),
            $input->string('password', 200),
            $input->has('otp') ? $input->string('otp', 10) : null,
            $context->request->ip(),
        );
        $staff = Staff::fromUser($context->db, $user);
        $this->assertCanUseApp($staff);
        $issued = $tokens->issue($user, $input->string('device_name', 100, 'Perangkat'), $context->request->ip(), $input->bool('remember', true));
        $context->log('Masuk ke Klaras InvenSync dari ' . $input->string('device_name', 100, 'perangkat') . '.', 'Login');

        return JsonResponse::ok([...$issued, 'staff' => $staff->toArray()]);
    }

    /** An AI app the librarian allowed on this SLiMS, connecting through Klaras Panel. */
    private function agent(Context $context, Input $input, StaffTokens $tokens): JsonResponse
    {
        if (!AgentCodes::enabled($context->db)) {
            throw AgentCodes::disabled();
        }
        try {
            $grant = AgentCodes::redeem($context->db, $input->required('code', 64), $input->required('code_verifier', 128), $input->required('redirect_uri', 500), time());
        } catch (\RuntimeException $error) {
            throw new ApiException('invalid_grant', $error->getMessage(), 400);
        }
        $statement = $context->db->prepare('SELECT * FROM user WHERE user_id = ?');
        $statement->execute([$grant['user_id']]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$user || (string) ($user['is_active'] ?? '1') !== '1') {
            throw new ApiException('invalid_grant', 'Akun SLiMS yang memberi izin tidak aktif lagi.', 400);
        }
        $staff = Staff::fromUser($context->db, $user);
        $this->assertCanUseApp($staff);
        $issued = $tokens->issue($user, 'Agent AI · ' . $grant['client'], $context->request->ip(), true, AgentCodes::KIND);
        $context->log('Agent AI ' . $grant['client'] . ' dihubungkan atas nama ' . $staff->name . '.', 'Login');

        return JsonResponse::ok([...$issued, 'staff' => $staff->toArray()]);
    }

    private function assertCanUseApp(Staff $staff): void
    {
        if (!$staff->canRead) {
            throw Failure::forbidden('no_stock_take_access', 'Akun Anda tidak punya hak Stock Take. Minta administrator SLiMS menambahkannya.');
        }
    }

    public function revoke(Context $context): JsonResponse
    {
        (new StaffTokens($context->db))->revoke($context->staff()->sessionId);
        $context->log('Keluar dari Klaras InvenSync.', 'Logout');

        return JsonResponse::ok(['revoked' => true]);
    }

    public function me(Context $context): JsonResponse
    {
        return JsonResponse::ok([...$context->staff()->toArray(), 'library_name' => Context::libraryName()]);
    }

    /** People a finding can be given to. */
    public function staffList(Context $context): JsonResponse
    {
        $rows = $context->db->query("SELECT user_id, realname FROM user WHERE is_active = '1' ORDER BY realname LIMIT 500")->fetchAll(\PDO::FETCH_ASSOC);

        return JsonResponse::ok(array_map(static fn (array $row): array => ['id' => (int) $row['user_id'], 'name' => (string) $row['realname']], $rows));
    }
}
