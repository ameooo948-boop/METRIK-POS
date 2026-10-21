<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class NotificationController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        /** @var Organization|null $organization */
        $organization = $request->attributes->get('currentOrganization');
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($notification): array => [
                'id' => (string) $notification->getKey(),
                'type' => (string) $notification->type,
                'title' => (string) ($notification->data['title'] ?? 'إشعار جديد'),
                'message' => (string) ($notification->data['message'] ?? ''),
                'url' => $this->safeInternalUrl($request, $notification->data['url'] ?? null),
                'readAt' => $notification->read_at?->toIso8601String(),
                'createdAt' => $notification->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Notifications/Index', [
            'organization' => $organization?->only(['id', 'name']),
            'currentBranch' => $request->attributes->get('currentBranch')?->only(['id', 'name', 'code']),
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
            'flashMessage' => $request->session()->get('success'),
        ]);
    }

    public function markRead(Request $request, string $notificationId): RedirectResponse
    {
        // Resolve through the authenticated user's relationship: a notification ID is not authorization.
        $notification = $request->user()->notifications()->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();

        return back()->with('success', 'تم تحديد الإشعار كمقروء.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'تم تحديد جميع الإشعارات كمقروءة.');
    }

    private function safeInternalUrl(Request $request, mixed $candidate): ?string
    {
        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if ($host !== null && strcasecmp((string) $host, $request->getHost()) !== 0) {
            return null;
        }

        $scheme = parse_url($candidate, PHP_URL_SCHEME);
        if ($scheme !== null && ! in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            return null;
        }

        return $candidate;
    }
}
