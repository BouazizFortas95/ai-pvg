<?php

namespace App\Services\Publishing;

use App\Models\PublishingApprovalSession;
use App\Services\Automation\ApprovalService;
use RuntimeException;

class YouTubePublishService
{
    public function publishVideoScript(int $videoScriptId): bool
    {
        $session = PublishingApprovalSession::where('video_script_id', $videoScriptId)
            ->where('status', 'approved')
            ->first();
        if ($session === null) {
            throw new RuntimeException('No approved dual-admin session for script.');
        }
        if ($session->first_approver_id === $session->second_approver_id) {
            throw new RuntimeException('Two distinct admins required for public publish.');
        }
        return true; // actual YouTube upload remains opt-in and mocked for G-06
    }
}
